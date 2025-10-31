<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Contracts\RendererInterface;
use App\Contracts\UploadServiceInterface;
use App\Repositories\AwardRepository;
use App\Services\{
    JudgesService,
    LogoService,
    Awards\AwardsService,
    Files\UploadService,
    Awards\JudgesOptionsService,
    Awards\NominationSettingsService,
    Awards\FormRecordsService,
    QuestionService,
    ChecklistService
};
use App\Repositories\QuestionRepository;
use App\Repositories\JudgesRepository;
use App\Support\renderHtml;
use App\Http\CsrfGuard;
use Base;
use Flash;
use RuntimeException;

final class AwardsController
{
    private Base $f3;
    private $award;
    private renderHtml $renderer;
    private AwardsService $awards;
    private QuestionService $questions;
    private LogoService $logoSvc;
    private ChecklistService $checklistService;
    private string $cpSlug = '';

    private const FU_INPUT_FIELDS = [
        'textarea', 'text', 'date', 'number', 'email'
    ];

    public function __construct(Base $f3, array $args = [], $alias = null)
    {
        $this->f3 = $f3;
        $this->logoSvc = new LogoService($f3);
        $this->renderer = new renderHtml($this->f3);
        $this->awards = new AwardsService(new AwardRepository($this->f3), $this->f3);
        $this->questions = new QuestionService($this->f3, new QuestionRepository($this->f3->get('DB')));
        $this->checklistService = new ChecklistService(new QuestionRepository($this->f3->get('DB')));
        $this->cpSlug = (string)($this->f3->get('SESSION.cp_slug') ?? '');
    }

    public function beforeRoute(Base $f3, array $args): void
    {
        if (!$this->f3->get('SESSION.logged')) {
            $this->f3->reroute('/login/cp/index');
            return;
        }
        $incoming = (string)($args['awardSlug'] ?? $args['award_slug'] ?? '');
        if (!empty($incoming)) {
            $awardSlug = (string)($args['awardSlug'] ?? '');
            $this->f3->set('SESSION.awardSlug', $awardSlug);
            $this->f3->set('awardSlug', $awardSlug);
            $metrics = $this->awards->getMetricsForAward($awardSlug);
            $this->f3->set('metrics', $metrics);
            $award = $this->awards->fetchAwardByAwardSlug($awardSlug);
            $this->award = $award;
            $this->f3->set('award', is_array($this->award) ? ($this->award[0] ?? null) : $this->award);
            $this->logoSvc->resolveAwardLogo($this->award, ['sponsor_logo']);
        }
        //this is necessary
        $this->f3->set('active_awards', $this->awards->countActiveByCpSlug($this->cpSlug));
        $this->f3->set('all_awards', $this->awards->countAllByCp($this->cpSlug));
        $this->f3->set('server_name', 'https://new.nominatepro.com');
        $this->refreshAwardIfChanged($args);

    }

    public function index(): void
    {
        $this->f3->clear('SESSION.logo');
        $rows = $this->awards->listAll($this->cpSlug);
        $deleted = $this->awards->listDeleted($this->cpSlug);
        $this->renderer->render(
            'settings/awards/all_awards.htm',
            'settings/layout.htm',
            [
                'PAGE_TITLE' => 'All Awards',
                'awards' => $rows,
                'deleted_awards' => $deleted,
                'setting' => '',
            ]
        );
    }

    public function newAward(): void
    {
        CsrfGuard::issueToken($this->f3);
        $this->renderer->render(
            'settings/awards/new_award_form.htm',
            'settings/layout.htm',
            [
                'PAGE_TITLE' => 'Create a New Award',
                'postRoute' => '/app/create/new',
                'cancelRoute' => '/app/all/index',
            ]
        );
    }

    public function create(): void
    {
        try {
            CsrfGuard::validate($this->f3, $this->f3->get('POST.csrf_token'));
        } catch (RuntimeException $e) {
            Flash::instance()->addMessage('Security check failed, please try again.', 'danger');
            $this->f3->reroute('/app/award/newAward');
            return;
        }
        // Prevent duplicates (case-insensitive suggested)
        $cp_slug = (string)$this->f3->get('SESSION.cp_slug');
        $award_name = $this->f3->clean($this->f3->get('POST.award_name'));
        if ($this->awards->awardNameExists($cp_slug, $award_name)) {
            Flash::instance()->addMessage('You have already created an award with that name. Try again.', 'danger');
            $this->f3->reroute("/app/award/new");
            return;
        }
        $award = $this->awards->createAward();
        $award_slug = $award['slug'];
        if ($award['id']) {
            Flash::instance()->addMessage('The award has been created. Please complete the required sections and questions.', 'success');
            $this->f3->reroute("/app/$award_slug/award_details/Award Details/singleAward");
        } else {
            Flash::instance()->addMessage('The award could not be created. Please try again"', 'danger');
            $this->f3->reroute("/app/award/new");
        }

    }

    public function activeAwards(): void
    {
        $awards = $this->awards->fetchActiveAwardsByCpSlug($this->cpSlug);
        $this->renderer->render(
            'settings/awards/active_awards.htm',
            'settings/layout.htm',
            [
                'PAGE_TITLE' => 'Active Awards',
                'awards' => $awards,
            ]
        );
    }

    public function singleAward(Base $f3, array $args = []): void
    {
        $form = isset($args['form']) ? $args['form'] . '_form.htm' : null;

        if (!empty($args['form'])) {
            switch ($args['form']) {
                case 'nominator_contact':
                    $form = 'nominator_nominee_contact_form.htm';
                    $f3->set('nominator_nominee', 'nominator');
                    break;

                case 'nominee_contact':
                    $form = 'nominator_nominee_contact_form.htm';
                    $f3->set('nominator_nominee', 'nominee');
                    break;
            }
        }

        $title = $args['title'] ?? null;
        $this->renderer->render(
            'settings/forms/award_form.htm',
            'settings/layout.htm',
            [
                'PAGE_TITLE' => $title,
                'form' => $form,
                'setting' => $args['form'] ?? '',
                'title' => $title,
            ]
        );
    }

    public function nominations(Base $f3, array $args = []): void
    {
        $awardSlug = (string)($args['awardSlug'] ?? '');
        $status    = strtolower((string)($args['status'] ?? 'ongoing')); // route like /app/@awardSlug/nominations/@type

        // Guard
        if ($awardSlug === '') {
            Flash::instance()->addMessage('Award not specified', 'danger');
            $f3->reroute('/app/awards');
            return;
        }

        // Allowed statuses and view-specific labels
        $meta = [
            'ongoing'   => ['PAGE_TITLE' => 'Ongoing Nominations',   'table_column' => 'Date Submitted'],
            'completed' => ['PAGE_TITLE' => 'Completed Nominations', 'table_column' => 'Date Completed'],
            'demo'      => ['PAGE_TITLE' => 'Demo Nominations',      'table_column' => 'Demo Created'],
            'all'       => ['PAGE_TITLE' => 'All Nominations',  'table_column' => 'All Nominations']
        ];

        if (!isset($meta[$status])) {
            Flash::instance()->addMessage('Unknown nominations filter.', 'warning');
            $f3->reroute("/app/{$awardSlug}/nominations/ongoing");
            return;
        }

        // Single call to the service/repo
        $nominations = $this->awards->fetchNominationsByStatus($awardSlug, $status);

        $this->renderer->render(
            'settings/nominations/nominations.htm',
            'settings/layout.htm',
            [
                'PAGE_TITLE'       => $meta[$status]['PAGE_TITLE'],
                'setting'          => '',
                'nominations'      => $nominations,
                'status'           => $status,
                'completed_status' => $meta[$status]['table_column'],
            ]
        );
    }

    public function switchNomination(Base $f3, array $args = []): void
    {
        $awardSlug = $args['awardSlug'] ?? '';
        $action    = strtolower($args['action'] ?? '');

        $route = "app/$awardSlug/important_dates/Important%20Dates/singleAward#settings";

        if ($awardSlug === '' || !in_array($action, ['on', 'off'], true)) {
            Flash::instance()->addMessage('Invalid request. Please try again.', 'danger');
            $f3->reroute($route);   // or wherever makes sense as a fallback
            return;
        }

        try {
            $this->awards->switchNomination($awardSlug, $action);
            $action == 'on' ? $f3->set('SESSION.nomination_is_active', true) : $f3->set('SESSION.nomination_is_active', false);
            Flash::instance()->addMessage("The nomination is turned $action.", 'success');
            $f3->reroute($route); // adjust reroute target
        } catch (\Throwable $e) {
            Flash::instance()->addMessage('Unable to update the nomination: ' . $e->getMessage(), 'danger');
            $f3->reroute($route);
        }
    }

    public function update(array $args = []): void
    {
       // try {
            CsrfGuard::validate($this->f3, $this->f3->get('POST.token'));
            $awardSlug = (string)($args['awardSlug'] ?? $this->f3->get('SESSION.awardSlug') ?? '');
            $this->f3->set('SESSION.awardSlug', $awardSlug);
            $post = $this->f3->get('POST');

            $files = $_FILES ?? [];
            if(!empty($files['sponsor_logo']))
            {
                $post['sponsor_logo'] = $files['sponsor_logo']['name'];
            }

//            $award = $this->awards->fetchAwardByAwardSlugMapperVersion($awardSlug);
//            $store = $this->logoSvc->saveLogo($award, $awardSlug, $_FILES, 'sponsor_logo');

           // $this->processLogoUploads($awardSlug, $files);

            $today = date('Y-m-d');
            $ok = $this->awards->updateSettings($awardSlug, $post, $files, $today);
            $fresh = $this->awards->fetchAwardByAwardSlug($awardSlug);
            // Refresh the session cache to the newest values
            $this->f3->set('SESSION._award_rows', $fresh);
            // 5) Message + redirect
            $route = '/app/' . ($args['awardSlug'] ?? '') . '/' . ($args['setting'] ?? '') . '/' . ($args['title'] ?? '') . '/singleAward#settings';
            if ($ok) {
                $this->messageAndRedirect('award', 'updated', $route);
            } else {
                $this->messageAndRedirect('award', 'not updated', $route);
            }
//        } catch (\Throwable $e) {
//            Flash::instance()->addMessage('Update failed: ' . $e->getMessage(), 'warning');
//            $this->f3->reroute('app/'.$awardSlug.'/sponsor_logo/Add%20Sponsor%20Logo/singleAward');
//        }
    }

    public function nomineeNominatorContact(Base $f3, array $args = []): void
    {
        $awardSlug = (string)($args['awardSlug'] ?? $args['award_slug'] ?? $f3->get('SESSION.awardSlug') ?? '');
        $verb      = strtoupper((string)($f3->get('VERB') ?? 'GET'));
        $setting   = (string)($args['setting'] ?? '');
        $title     = (string)($args['title'] ?? '');
        $who       = $setting === 'nominator' ? 'nominators' : 'nominees';

        // Build a URL-safe path
        $safeTitle = rawurlencode($title);
        $path      = "/app/{$awardSlug}/contact/{$who}/{$safeTitle}/index";

        // Optional: preload existing data for GET
        if ($data = $this->awards->fetchNominatorNomineeContactInformation($awardSlug, $who)) {
            $f3->set('data', $data[0]);
            $f3->set('update', true); // enable  your template relies on it
        }

        try {
            if ($verb === 'GET') {
                $f3->clear('SESSION.csrf_token');
                CsrfGuard::issueToken($f3);

                $this->renderer->render(
                    'settings/forms/nominator_nominee_contact_form.htm',
                    'settings/layout.htm',
                    [
                        'PAGE_TITLE'       => 'Contact Information for the ' . ucfirst($who),
                        'action'           => $path,                 // form action
                        'setting'          => $setting,
                        'nominator_nominee'=> $who
                    ]
                );
                return;
            }

            // POST branch
            $post = (array)$f3->clean($f3->get('POST') ?? []);

            // Be tolerant to either 'token' or 'csrf_token' from the form
            $csrf = $post['csrf_token'] ?? $post['token'] ?? null;
            if (!$csrf) {
                throw new \RuntimeException('Missing CSRF token');
            }

            CsrfGuard::validate($f3, $csrf);

            $this->awards->createUpdateNominatorNomineeContact($awardSlug, $who, $post);

            // Success message (if you use Flash)
            Flash::instance()->addMessage('Saved contact information.', 'success');

            // PRG
            $f3->reroute($path);
            return;

        } catch (\Throwable $e) {
            // Surface the error and redirect back to GET so the page *reloads*
            Flash::instance()->addMessage('Error: ' . $e->getMessage(), 'danger');
            $f3->reroute($path);
            return;
        }
    }


    public function sections(Base $f3, array $args = []): void
    {
        // -------- Guard + input normalization -----------------------------------
        $awardSlug = (string)($args['awardSlug'] ?? $args['award_slug'] ?? $f3->get('SESSION.awardSlug') ?? '');
        $sectionSlug = (string)($args['sectionSlug'] ?? '');
        $verb = strtoupper((string)($f3->get('VERB') ?? 'GET'));
        $path = (string)$f3->get('PATH'); // fallback when route doesn’t pass action

        // Determine action: explicit from route args or last path segment
        $action = $args['action'] ?? ltrim((string)strrchr($path, '/'), '/') ?: 'index';
        // Centralized paths used in views/redirects
        $paths = [
            'index' => "/app/$awardSlug/sections#sections",
            'store' => "/app/$awardSlug/sections/store",
            'update' => "/app/$awardSlug/$sectionSlug/sections/update",
        ];
        // Helpers ---------------------------------------------------------------
        $requireCsrf = function () use ($f3) {
            try {
                CsrfGuard::validate($f3, (string)$f3->get('POST.token'));
            } catch (\RuntimeException $e) {
                throw new \RuntimeException('Security check failed, please try again.');
            }
        };
        $render = function (string $view, array $data = []) {
            $this->renderer->render($view, 'settings/layout.htm', $data);
        };
        try {
            switch ($action) {
                case 'store':
                    if ($verb === 'GET') {
                        $f3->clear('SESSION.csrf_token');
                        CsrfGuard::issueToken($f3);
                        $render('settings/forms/sections_form.htm', [
                            'PAGE_TITLE' => 'Add a section',
                            'action' => $paths['store'],
                            'setting' => '',
                        ]);
                        return;
                    }
                    // POST
                    $requireCsrf();
                    $post = (array)$f3->clean($f3->get('POST') ?? []);
                    $insertId = $this->questions->createSection($awardSlug, $post);
                    Flash::instance()->addMessage(
                        $insertId > 0 ? 'The section has been created.' : 'The section could not be created. Please try again.',
                        $insertId > 0 ? 'success' : 'danger'
                    );
                    $f3->reroute($insertId > 0 ? $paths['index'] : $paths['store']);
                    return;
                case 'update':
                    if ($sectionSlug === '') {
                        Flash::instance()->addMessage('Missing section id (slug)', 'danger');
                        $f3->reroute($paths['index']);
                        return;
                    }
                    if ($verb === 'GET') {
                        $f3->clear('SESSION.csrf_token');
                        CsrfGuard::issueToken($f3);
                        // fetch once; repo may return a row or a list — handle both safely
                        $row = $this->questions->fetchSectionBySectionSlug($sectionSlug);
                        if (is_array($row) && isset($row[0]) && is_array($row[0])) {
                            $row = $row[0];
                        }
                        $section = (string)($row['section'] ?? '');
                        $sectionName = (string)($row['section_name'] ?? '');
                        $render('settings/forms/sections_form.htm', [
                            'PAGE_TITLE' => $section !== '' ? 'Update "' . $section . '" section' : 'Update section',
                            'section' => $section,
                            'awardSlug' => $awardSlug,
                            'sectionName' => $sectionName,
                            'action' => $paths['update'],
                            'setting' => '',
                        ]);
                        return;
                    }
                    // POST
                    $requireCsrf();
                    $post = (array)$f3->clean($f3->get('POST') ?? []);
                    $count = $this->questions->updateSection($sectionSlug, $post);
                    Flash::instance()->addMessage(
                        $count > 0 ? 'The section was updated' : 'The section could not be updated. Try again.',
                        $count > 0 ? 'success' : 'danger'
                    );
                    $f3->reroute($count > 0 ? $paths['index'] : $paths['update']);
                    return;
                case 'delete':
                    // treat slug as string; do not compare with <= 0
                    $slug = (string)($args['sectionSlug'] ?? '');
                    if ($slug === '') {
                        throw new \InvalidArgumentException('Missing section id (slug)');
                    }
                    $count = $this->questions->deleteSection($slug);
                    Flash::instance()->addMessage(
                        $count > 0 ? 'The section was deleted' : 'The section could not be deleted. Try again.',
                        $count > 0 ? 'success' : 'danger'
                    );
                    $f3->reroute($paths['index']);
                    return;
                case 'index':
                default:
                    $sections = $this->questions->fetchSectionsAndQuestionCountBySection($awardSlug);
                    $render('settings/questions/sections.htm', [
                        'PAGE_TITLE' => 'Sections',
                        'sections' => $sections,
                        'setting' => '',
                    ]);
                    return;
            }
        } catch (\Throwable $e) {
            Flash::instance()->addMessage('Error: ' . $e->getMessage(), 'danger');
            $f3->reroute($paths['index']);
        }
    }

    public function questions(Base $f3, array $args = []): void
    {
        // -------- Guard + input normalization -----------------------------------
        $awardSlug = (string)($args['awardSlug'] ?? $args['award_slug'] ?? $f3->get('SESSION.awardSlug') ?? '');
        $type = (string)($args['type'] ?? $f3->get('SESSION.type') ?? '');
        $questionSlug = (string)($args['questionSlug'] ?? '');
        $verb = strtoupper((string)($f3->get('VERB') ?? 'GET'));
        $path = (string)$f3->get('PATH'); // only to infer action when route doesn't provide it

        // Persist `type` (if provided) so subsequent GETs don’t lose context
        if ($type !== '') {
            $f3->set('SESSION.type', $type);
        }
        // Derive action from route or last path segment
        $action = $args['action'] ?? ltrim((string)strrchr($path, '/'), '/');
        // Preload shared view data
        $sections = $this->questions->fetchSectionsByAwardSlug($awardSlug);
        $f3->set('sections', $sections);
        $f3->set('type', $type);
        $f3->set('setting', $type);
        // Centralized paths for redirects/forms
        $paths = [
            'index' => "/app/{$awardSlug}/questions/{$type}/index",
            'store' => "/app/{$awardSlug}/questions/{$type}/store",
            'update' => "/app/{$awardSlug}/{$questionSlug}/questions/{$type}/update",
            'judging' => "/app/{$awardSlug}/questions/{$type}/{$questionSlug}/judging",
        ];
        // Helper: require CSRF on mutating requests
        $requireCsrf = function () use ($f3) {
            try {
                CsrfGuard::validate($f3, (string)$f3->get('POST.token'));
            } catch (\RuntimeException $e) {
                throw new \RuntimeException('Security check failed, please try again.');
            }
        };
        // Helper: render
        $render = function (string $view, array $data = []) {
            $this->renderer->render($view, 'settings/layout.htm', $data);
        };
        // -------- Controller actions --------------------------------------------
        try {
            switch ($action) {
                case 'store':
                    if ($verb === 'GET') {
                        $f3->clear('SESSION.csrf_token');
                        CsrfGuard::issueToken($f3);
                        $render('settings/forms/question_form.htm', [
                            'PAGE_TITLE' => 'Add a question for ' . ucfirst($type . 's'),
                            'action' => $paths['store'],
                        ]);
                        return;
                    }
                    // POST
                    $requireCsrf();
                    $post = (array)$f3->clean($f3->get('POST') ?? []);
                    $insertId = $this->questions->createQuestion($awardSlug, $post);
                    if ($insertId > 0) {
                        Flash::instance()->addMessage('The question was successfully created.', 'success');
                        $f3->reroute($paths['index']);
                    } else {
                        Flash::instance()->addMessage('The question could not be created. Please try again.', 'danger');
                        $f3->reroute($paths['store']);
                    }
                    return;
                case 'update':
                    $question = $this->questions->fetchQuestion($questionSlug);
                    if ($verb === 'GET') {
                        if (empty($sections)) {
                            Flash::instance()->addMessage('Create at least one section before adding a question.', 'danger');
                            $f3->reroute($paths['index']);
                            return;
                        }
                        // cp_slug required for clone options
                        $cpSlug = (string)($f3->get('SESSION.cp_slug') ?? '');
                        if ($cpSlug === '') {
                            Flash::instance()->addMessage('The cp_slug is not available', 'danger');
                            $f3->reroute($paths['index']);
                            return;
                        }
                        CsrfGuard::issueToken($f3);
                        $awards = $this->awards->listAllAwards($cpSlug);
                        $render('settings/forms/question_form.htm', [
                            'PAGE_TITLE' => 'Update the question',
                            'awardSlug' => $awardSlug,
                            'question' => $question,
                            'awards' => $awards,
                            'action' => $paths['update'],
                        ]);
                        return;
                    }

                    $requireCsrf();
                    $post = (array)$f3->clean($f3->get('POST') ?? []);

                    // Optional clone flow
                    if (!empty($post['check_for_clone'])) {
                        $original = $this->questions->fetchQuestion($questionSlug);
                        if (empty($original)) {
                            throw new \RuntimeException('There is no data for this question');
                        }
                        $insertId = $this->questions->cloneQuestion(
                            (string)$post['cloned_award_slug'],
                            $questionSlug,
                            $original
                        );
                        if ($insertId > 0) {
                            Flash::instance()->addMessage('Question was successfully cloned.', 'success');
                            $f3->reroute($paths['index']);
                            return;
                        }
                        Flash::instance()->addMessage('Question could not be cloned. Please try again.', 'danger');
                        $f3->reroute($paths['update']);
                        return;
                    }
                    //if there is no checkbox_fu_trigger or radio_fu_trigger, update questions set fu_slug and fu_followup_questions to null and remove the followup question from the questions_follow_up table.
                    if ((empty([$post]['radio_fu_trigger']) || empty([$post]['checkbox_fu_trigger'])) && !empty($question['fu_slug'])) {
                        $this->questions->deleteFollowupQuestionBySlug($question['fu_slug']);
                    }

                    // Standard update
                    $count = $this->questions->updateQuestion($questionSlug, $post);
                    if ($count > 0) {
                        Flash::instance()->addMessage('The question was updated.', 'success');
                        $f3->reroute($paths['index']);
                    } else {
                        Flash::instance()->addMessage('The question could not be updated. Try again.', 'danger');
                        $f3->reroute($paths['update']);
                    }
                    return;
                case 'toggle':
                    // expects @questionSlug and POST.is_displayed
                    if ($questionSlug === '') {
                        throw new \InvalidArgumentException('Missing question id');
                    }
                    $on = (bool)($f3->get('POST.is_displayed') ?? true);
                    $this->questions->toggleDisplay($questionSlug, $on);
                    if ($this->isAjax()) {
                        $this->json(['ok' => true, 'id' => $questionSlug, 'is_displayed' => $on]);
                        return;
                    }
                    Flash::instance()->addMessage('Question visibility updated', 'success');
                    $f3->reroute($paths['index']);
                    return;
                case 'delete':
                    if ($questionSlug === '') {
                        throw new \InvalidArgumentException('Missing question id');
                    }
                    $count = $this->questions->deleteQuestion($questionSlug);
                    Flash::instance()->addMessage(
                        $count > 0 ? 'The question was deleted' : 'The question could not be deleted. Try again.',
                        $count > 0 ? 'success' : 'danger'
                    );
                    $f3->reroute($paths['index']);
                    return;
                case 'reorder':
                    // expects POST.ids = "12,9,15" or POST.ids[]
                    $ids = $f3->get('POST.ids');
                    if (is_string($ids)) {
                        $ids = array_values(array_filter(array_map('trim', explode(',', $ids))));
                    } elseif (!is_array($ids)) {
                        $ids = [];
                    }
                    $this->questions->reorder($ids);
                    $this->json(['ok' => true, 'ordered' => $ids]);
                    return;
                case 'judging':
                    if ($questionSlug === '') {
                        throw new \InvalidArgumentException('Missing question id');
                    }
                    $row = $this->questions->fetchQuestion($questionSlug);
                    $responseAnchors = [];
                    if (!empty($row['response_anchors'])) {
                        $decoded = json_decode((string)$row['response_anchors'], true);
                        if (is_array($decoded)) {
                            $responseAnchors = $decoded;
                        }
                    }
                    $activeTab = (isset($row['numeric_scale']) && (int)$row['numeric_scale'] >= 0) ? 'numeric' : 'open';
                    $judges = $this->judges->fetchJudges($awardSlug);
                    if (!empty($judges)) {
                        $judges = implode(',', array_map(
                            fn($j) => $j['judge_name'] . ':' . $j['judge_email'],
                            $judges
                        ));
                    } else {
                        $judges = '';
                    }
                    if ($verb === 'GET') {
                        CsrfGuard::issueToken($f3);
                        $render('settings/forms/questions_judging_forms.htm', [
                            'PAGE_TITLE' => 'Judging Form',
                            'action' => $paths['judging'],
                            'type' => $type,
                            'response_anchors' => $responseAnchors,
                            'active_tab' => $activeTab,
                            'judges' => $judges,
                        ]);
                        return;
                    }
                    // (POST for judging not implemented in original)
                    Flash::instance()->addMessage('Unsupported request.', 'danger');
                    $f3->reroute($paths['index']);
                    return;
                case 'index':
                default:
                    $questions = $this->questions->listByAward($awardSlug, $type);
                    $render('settings/questions/questions.htm', [
                        'PAGE_TITLE' => 'Questions for ' . ucfirst($type . 's'),
                        'question_headers' => $this->questionHeaders(),
                        'questions' => $questions,
                    ]);
                    return;
            }
        } catch (\Throwable $e) {
            Flash::instance()->addMessage('Error: ' . $e->getMessage(), 'danger');
            // Don’t strand the user on an error page — go back to list
            $f3->reroute("/app/{$awardSlug}/questions/{$type}/index");
        }
    }

    public function followUpQuestions(Base $f3, $args = []): void
    {
        $awardSlug = (string)($args['awardSlug'] ?? $args['award_slug'] ?? $f3->get('SESSION.awardSlug') ?? '');
        $type = (string)($args['type'] ?? '');
        $questionSlug = (string)($args['questionSlug'] ?? $args['questionSlug'] ??  '');
        $fuSlug = (string)($args['fuSlug'] ?? $args['fuSlug'] ??  '');
        $verb = strtoupper((string)($f3->get('VERB') ?? 'GET'));
        $path = (string)$f3->get('PATH'); // fallback when route doesn’t pass action
        if ($awardSlug === '') {
            Flash::instance()->addMessage('Award not specified', 'danger');
            $f3->reroute('/app/awards');
            return;
        }
        // Determine action: explicit from route args or last path segment
        $action = $args['action'] ?? ltrim((string)strrchr($path, '/'), '/') ?: 'index';

        $paths = [
            'store' => "/app/$awardSlug/followup/$type/$questionSlug/store",
            'update' => "/app/$awardSlug/followup/$type/$fuSlug/update",
            'list' => "/app/$awardSlug/questions/$type/index" //to the page with list of questions
        ];
        // Helpers ---------------------------------------------------------------
        $requireCsrf = function () use ($f3) {
            try {
                CsrfGuard::validate($f3, (string)$f3->get('POST.token'));
            } catch (\RuntimeException $e) {
                throw new \RuntimeException('Security check failed, please try again.');
            }
        };
        $render = function (string $view, array $data = []) {
            $this->renderer->render($view, 'settings/layout.htm', $data);
        };
        try {
            switch ($action) {
                //STORE
                case 'store':
                    if ($verb === 'GET') {
                        $f3->clear('SESSION.csrf_token');
                        CsrfGuard::issueToken($f3);
                        $render('settings/forms/followup_form.htm', [
                            'PAGE_TITLE' => 'Add a followup question',
                            'action' => $paths['store'],
                            'questionSlug' => $questionSlug,
                            'setting' => '',
                            'fields' => $this->followupFields()
                        ]);
                        return;
                    }
                    $requireCsrf();
                    $post = (array)$f3->clean($f3->get('POST') ?? []);
                    $insertId = $this->questions->createFollowupQuestion($awardSlug, $post);
                    Flash::instance()->addMessage(
                        $insertId > 0 ? 'The followup question was created' : 'The followup question could not be created. Try again.',
                        $insertId > 0 ? 'success' : 'danger'
                    );
                    $f3->reroute($paths['list']);
                    return;

                //UPDATE
                case 'update':
                    if ($verb === 'GET') {
                        $f3->clear('SESSION.csrf_token');
                        CsrfGuard::issueToken($f3);
                        $question = $this->questions->fetchFollowupQuestion($fuSlug);
                        $render('settings/forms/followup_form.htm', [
                            'PAGE_TITLE'    => 'Update the followup question',
                            'action'        => $paths['update'],
                            'cancel_route'  => $paths['list'],
                            'question'      => $question,
                            'setting'       => '',
                        ]);
                        return;
                    }

                    $requireCsrf();
                    $post = (array)$f3->clean($f3->get('POST') ?? []);

                    $update = 0;
                    try {
                        $update = $this->questions->updateFollowupQuestionBySlug($fuSlug, $post);
                    } catch (\Throwable $e) {
                        // log if needed
                    }

                    Flash::instance()->addMessage(
                        $update > 0 ? 'The question was updated' : 'The question could not be updated. Try again.',
                        $update > 0 ? 'success' : 'danger'
                    );

                    // If the slug might change on update, prefer the new one (fallback to the old)
                    $updatedSlug = $post['slug'] ?? $fuSlug;

                    // Redirect back to the questions list page
                    $f3->reroute("/app/$awardSlug/questions/$type/index");
                    return;


                //DELETE
                case 'delete':
                    $count = $this->questions->deleteFollowupQuestionBySlug($fuSlug);
                    Flash::instance()->addMessage(
                        $count > 0 ? 'The question was deleted' : 'The question could not be deleted. Try again.',
                        $count > 0 ? 'success' : 'danger'
                    );
                    $f3->reroute($paths['list']);
                    return;
            }
        } catch (\Throwable $e) {
            Flash::instance()->addMessage('Error: ' . $e->getMessage(), 'danger');
            $f3->reroute($paths['list']);
        }
    }

    public function checklist(Base $f3, array $params = []): void
    {
        // Prefer route param, fall back to session if you like
        $awardSlug = (string)($params['awardSlug'] ?? $this->f3->get('SESSION.awardSlug') ?? '');
        $setting = (string)($params['type'] ?? $params['type'] ?? '');

        if ($awardSlug === '') {
            \Flash::instance()->addMessage('Award not specified', 'danger');
            $this->f3->reroute('/app/awards');
            return;
        }

        $summary = $this->checklistService->build($awardSlug);

        $this->renderer->render(
            'settings/awards/checklist.htm',
            'settings/layout.htm',
            [
                'PAGE_TITLE'     => 'Award Checklist',
                'setting' => $setting,
            ]
        );

    }

    // ------------ helpers ------------

    /**
     * Handle header/sponsor logo uploads for a given award.
     * - Loads the award mapper (via repo if available, else direct Mapper)
     * - Saves any provided files using LogoService
     * - Pushes flash messages and refreshes resolved logo/session
     */
    private function processLogoUploads(string $awardSlug, array $files): void
    {
        try {
            // Prefer repository/service to get the Mapper if you have one
//            if (method_exists($this->awards, 'fetchAwardByAwardSlug')) {
//                /** @var \DB\SQL\Mapper $award */
//                $award = $this->awards->fetchAwardByAwardSlug($awardSlug);
//            } else {
                // Fallback direct Mapper (adjust table/field if different)
                $award = new \DB\SQL\Mapper($this->db(), 'awards');
                $award->load(['award_slug = ?', $awardSlug]);
                if ($award->dry()) {
                    throw new \RuntimeException('Award not found for logo upload.');
                }
//            }
            // Sponsor logo
            if (!empty($files['sponsor_logo']) && ($files['sponsor_logo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $stored = $this->logoSvc->saveLogo($award, $awardSlug, $_FILES, 'sponsor_logo');
                \Flash::instance()->addMessage("Sponsor logo uploaded: {$stored}", 'success');
            }

            // Optional: immediately refresh resolved logo + aspect ratio in session
            $this->logoSvc->resolveAwardLogo();

        } catch (\Throwable $e) {
            \Flash::instance()->addMessage('Logo upload failed: ' . $e->getMessage(), 'warning');
        }
    }


    private function messageAndRedirect(string $entity, string $verbs, string $route): void
    {
        Flash::instance()->addMessage('The ' . $entity . ' was ' . $verbs, 'success');
        $this->f3->reroute($route);
    }

    /**
     * Keep SESSION award cache in sync with route + DB changes.
     *
     * Behavior:
     * - If the route slug differs from the session slug → fetch full award and replace cache.
     * - If slugs match and we *have* cached rows → ping DB for last-edited; if changed → re-fetch full award.
     * - If slugs match and we *don't* have cache → fetch full award once.
     */
    private function refreshAwardIfChanged(array $args = []): void
    {
        // Accept either 'awardSlug' or 'award_slug'
        $incoming = (string)($args['awardSlug'] ?? $args['award_slug'] ?? '');
        $sessionSlug = (string)($this->f3->get('SESSION.awardSlug') ?? '');
        $cachedRows = $this->f3->get('SESSION._award_rows');
        // Helper to pull a field from cached rows (array or first row)
        $getCached = static function ($rows, string $key): ?string {
            if (!is_array($rows)) return null;
            // handle ["date_edited"=>...] or [0=>["date_edited"=>...]]
            if (array_key_exists($key, $rows) && is_scalar($rows[$key])) {
                return (string)$rows[$key];
            }
            if (isset($rows[0]) && is_array($rows[0]) && array_key_exists($key, $rows[0])) {
                return (string)$rows[0][$key];
            }
            return null;
        };
        // 1) If route provided a slug AND it's different from the session → refresh fully
        if ($incoming !== '' && $incoming !== $sessionSlug) {
            $rows = $this->awards->fetchAwardByAwardSlug($incoming);
            $this->f3->set('SESSION.awardSlug', $incoming);
            $this->f3->set('SESSION._award_rows', $rows);
            return;
        }
        // Determine the active slug we should be using
        $slug = $incoming !== '' ? $incoming : $sessionSlug;
        // 2) If we have a slug and cached rows, verify freshness via a lightweight "last edited" check
        if ($slug !== '' && is_array($cachedRows)) {
            $cachedEdited = $getCached($cachedRows, 'date_edited') ?? $getCached($cachedRows, 'updated_at');
            // Lightweight HEAD check (implement in repo/service to just SELECT the timestamp)
            $latestEdited = $this->awards->fetchAwardLastEdited($slug); // returns string|null (e.g. '2025-09-04 15:12:03')
            // If we can't do a lightweight check, you can comment the line above and fall back to:
            // $latestEdited = $getCached($this->awards->fetchAwardByAwardSlug($slug), 'date_edited') ?? $getCached(..., 'updated_at');
            if ($latestEdited !== null && $cachedEdited !== null && $latestEdited !== $cachedEdited) {
                // Award changed → re-fetch full payload
                $rows = $this->awards->fetchAwardByAwardSlug($slug);
                $this->f3->set('SESSION._award_rows', $rows);
            }
            // If either edited value is null, we assume no reliable way to compare → do nothing (keep cache)
            return;
        }
        // 3) First load: no cache yet but we do have a slug → fetch once
        if ($slug !== '' && !is_array($cachedRows)) {
            $rows = $this->awards->fetchAwardByAwardSlug($slug);
            $this->f3->set('SESSION._award_rows', $rows);
            // also ensure session slug is set
            if ($sessionSlug === '') {
                $this->f3->set('SESSION.awardSlug', $slug);
            }
        }

        // Otherwise: do nothing → keep the existing array intact
    }

    private function questionHeaders()
    {
        return [
            'Section',
            'Response Type',
            'Display',
            'Required',
            'Judging Module',
            'Question Value',
            'Evaluation Type',
            'Edit',
            'Delete',
            'Followup Question',
        ];
    }

    private function isAjax(): bool
    {
        $hdr = (string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
        return strtolower($hdr) === 'xmlhttprequest';
    }

    private function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($payload);
    }

    private function injectFlashIntoHive(): void
    {
        $msgs = \Flash::instance()->getMessages(); // [{text, type}]
        $this->f3->set('flash', $msgs);

        // JSON for the SweetAlert script block; safe-encode
        $this->f3->set(
            'flash_json',
            json_encode($msgs, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)
        );
    }

    private function followupFields()
    {
        return [
            [
                'id' => 'checktextarea',
                'value' => 'textarea',
                'tabindex' => 2,
                'description' => $this->f3->get('textarea_description')
            ],
            [
                'id' => 'checktext',
                'value' => 'text',
                'tabindex' => 3,
                'description' => $this->f3->get('text_description')
            ],
            [
                'id' => 'checkdata',
                'value' => 'date',
                'tabindex' => 4,
                'description' => $this->f3->get('date_description')
            ],
            [
                'id' => 'checknumber',
                'value' => 'number',
                'tabindex' => 5,
                'description' => $this->f3->get('number_description')
            ],
            [
                'id' => 'checkemail',
                'value' => 'email',
                'tabindex' => 6,
                'description' => $this->f3->get('email_description')
            ],
        ];
    }
}
