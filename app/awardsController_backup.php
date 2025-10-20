<?php
declare(strict_types=1);

namespace App\Controllers;

use Base;
use Flash;

use App\Contracts\RendererInterface;

use App\Contracts\UploadServiceInterface;        // ✅ contract lives in App\Contracts

use App\Repositories\AwardRepository;

use App\Services\Awards\AwardsService;

use App\Services\Files\UploadService;                  // ✅ concrete implementation

use App\Services\Awards\JudgesOptionsService;

use App\Services\Awards\NominationSettingsService;

use App\Services\Awards\FormRecordsService;

use App\Repositories\QuestionRepository;

use App\Services\QuestionService;

use App\Services\LogoService;

use App\Support\renderHtml;

use App\Http\CsrfGuard;

final class AwardsController
{
    private Base $f3;
    private RendererInterface $renderer;
    private AwardsService $awards;
    private QuestionService $questions;

    private string $cpSlug = '';
    private string $awardSlug = '';

    private CsrfGuard $csrf;

    public function __construct(Base $f3, array $args = [], $alias = null)
    {
        $this->f3 = $f3;

        // Instantiate dependencies (swap for DI/container if you have one)
        $awardRepo = new AwardRepository($this->f3);

        // ❗ Remove LogoUploadService and the duplicate assignment.
        // Type-hint by interface, construct the concrete UploadService.
        /** @var UploadServiceInterface $uploader */
        $uploader  = new UploadService($this->f3);

        $judgesSvc = new JudgesOptionsService();
        $nomSvc    = new NominationSettingsService();
        $formSvc   = new FormRecordsService();

        $this->awards   = new AwardsService($awardRepo, $this->f3, $uploader, $judgesSvc, $nomSvc, $formSvc);
        $this->renderer = new renderHtml($this->f3); // implements RendererInterface
        $this->csrf     = new CsrfGuard();
        $this->logoSvc  = new LogoService($this->f3);
        $repo = new QuestionRepository($this->f3->get('DB'));
        $this->questions = new QuestionService($this->f3, $repo);

        $this->cpSlug = (string) ($this->f3->get('SESSION.cp_slug') ?? '');
    }

    public function beforeRoute(Base $f3, array $args): void
    {
        if (!$this->f3->get('SESSION.logged')) {
            $this->f3->reroute('/login/cp/index');
            return;
        }

        if (isset($args['awardSlug'])) {
            $this->awardSlug = (string) ($args['awardSlug'] ?? '');
            $this->f3->set('SESSION.awardSlug', $this->awardSlug);

            $metrics = $this->awards->getMetricsForAward($this->awardSlug);
            $this->f3->set('metrics', $metrics);
        }

        $logo = $this->logoSvc->resolveAwardLogo();

        $this->refreshAwardIfChanged($args);

    }

    public function index(): void
    {
        $rows    = $this->awards->listAll($this->cpSlug);
        $deleted = $this->awards->listDeleted($this->cpSlug);

        $this->renderer->render(
            '/views/settings/awards/all_awards.htm',
            '/views/settings/layout.htm',
            [
                'PAGE_TITLE'     => 'All Awards',
                'awards'         => $rows,
                'deleted_awards' => $deleted,
                'setting'        => '',
//                'metrics.active_awards'  => $this->awards->countActiveByCp($this->cpSlug),
//                'metrics.all_awards'  => $this->awards->countAllByCp($this->cpSlug)
            ]
        );
    }

    public function newAward(Base $f3): void
    {
        CsrfGuard::issueToken($f3);

        $this->renderer->render(
            '/views/settings/awards/new_award_form.htm',
            '/views/settings/layout.htm',
            [
                'PAGE_TITLE'  => 'Create a New Award',
                'postRoute'   => '/app/settings/postNewAward',
                'cancelRoute' => '/app/all/index',
            ]
        );
    }

    public function activeAwards(): void
    {
        $rows = $this->awards->activeAwardsByCpSlug($this->cpSlug);

        $this->renderer->render(
            '/views/settings/awards/active_awards.htm',
            '/views/settings/layout.htm',
            [
                'PAGE_TITLE' => 'Active Awards',
                'awards'     => $rows,
            ]
        );
    }

    public function singleAward(Base $f3, array $args = []): void
    {
        $rows = $this->f3->get('SESSION._award_rows') ?? [];
        $form  = isset($args['form']) ? $args['form'] . '_form.htm' : null;
        $title = $args['title'] ?? null;

        $this->renderer->render(
            '/views/settings/forms/award_form.htm',
            '/views/settings/layout.htm',
            [
                'PAGE_TITLE' => $title,
                'form'       => $form,
                'award'      => $rows[0] ?? [],
                'setting'    => $args['form'] ?? '',
                'title'      => $title,
            ]
        );
    }

    public function update(Base $f3, array $args = []): void
    {
        try {
            // 1) CSRF
            $this->csrf->validate($this->f3, $this->f3->get('POST.token'));

            // 2) Slug: prefer route arg, fallback to session
            $awardSlug = (string)($args['awardSlug'] ?? $this->f3->get('SESSION.awardSlug') ?? '');
            if ($awardSlug === '') {
                throw new \RuntimeException('Missing award slug.');
            }
            // Keep session in sync
            $this->f3->set('SESSION.awardSlug', $awardSlug);

            // 3) Input
            $post  = (array)($this->f3->clean($this->f3->get('POST')) ?? []);
            $files = $_FILES ?? [];

            // 4) Update via service (make it return bool)
            $today = date('Y-m-d');
            $ok = $this->awards->updateSettings($awardSlug, $post, $files, $today);

            $fresh = $this->awards->fetchAwardByAwardSlug($awardSlug);

            // Refresh the session cache to the newest values
            $this->f3->set('SESSION._award_rows', $fresh);

            // 5) Message + redirect
            $route = (string)($this->f3->get('SESSION.route') ?? '/app/awards');
            if ($ok) {
                $this->messageAndRedirect(true, 'award', ['updated', 'update'], $route);
            } else {
                $this->messageAndRedirect(false, 'award', ['not updated', 'update'], $route);
            }
        } catch (\Throwable $e) {
            \Flash::instance()->addMessage('Update failed: '.$e->getMessage(), 'warning');
            $fallback = '/app/' . ($args['awardSlug'] ?? '') . '/' . ($args['setting'] ?? '') . '/' . ($args['title'] ?? '') . '/singleAward#settings';
            $this->f3->reroute($fallback);
        }
    }


    private function messageAndRedirect(bool $isDateDifferent, string $entity, array $verbs, string $route): void
    {
        Flash::instance()->set('success', 'Award updated.');
        $this->f3->reroute($route ?: '/app/awards');
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
        $incoming    = (string) ($args['awardSlug'] ?? $args['award_slug'] ?? '');
        $sessionSlug = (string) ($this->f3->get('SESSION.awardSlug') ?? '');
        $cachedRows  = $this->f3->get('SESSION._award_rows');

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

    public function questions(Base $f3, array $args = []): void
    {
        // 1) Resolve award slug (route > session), guard if missing
        $awardSlug = (string)($args['award_slug'] ?? $this->f3->get('SESSION.awardSlug') ?? '');
        if ($awardSlug === '') {
            \Flash::instance()->addMessage('Award not specified', 'danger');
            $this->f3->reroute('/app/awards');
            return;
        }
        // keep session in sync
        $this->f3->set('SESSION.awardSlug', $awardSlug);

        // 2) Optional filters
        $type    = isset($args['type']) ? strtolower((string)$args['type']) : null; // 'nominator'|'nominee'|null
        if ($type !== null && $type !== 'nominator' && $type !== 'nominee') {
            $type = null; // ignore unknown types
        }
        $focusSection = (string)($this->f3->get('GET.section') ?? '');

        // 3) Fetch sections (for sidebar/tabs)
        // returns rows like [ ['section_name' => 'Section One'], ... ]
        $sectionRows = $this->questions->fetchSectionsAndQuestionCountBySection($awardSlug);

        // 4) Fetch questions (optionally by type), ordered by section + display_order
        $rows = $this->questions->listByAward($awardSlug, $type);

        // 5) Attach follow-ups where needed (only when flagged to reduce queries)
        //    follow_up_questions = 'on' OR fu_slug not null -> load follow-ups
        foreach ($rows as &$q) {
            $hasFu = ($q['follow_up_questions'] ?? '') === 'on' || !empty($q['fu_slug']);
            if ($hasFu && !empty($q['question_slug'])) {
                $q['follow_ups'] = $this->questions->listFollowUpsByQuestionSlug((string)$q['question_slug']);
            } else {
                $q['follow_ups'] = [];
            }
        }
        unset($q);

        // 6) Group questions by section for easier templating
        $bySection = [];
        foreach ($rows as $q) {
            $sec = (string)($q['section_name'] ?? '');
            $bySection[$sec][] = $q;
        }

        // 7) Quick metrics for tabs/badges
        $countNominator = $this->questions->countByAwardAndType($awardSlug, 'nominator');
        $countNominee   = $this->questions->countByAwardAndType($awardSlug, 'nominee');

        // 8) Expose to views
        $this->f3->set('sections', array_map(fn($r) => $r['section_name'], $sectionRows));
        $this->f3->set('questions_by_section', $bySection);
        $this->f3->set('award_slug', $awardSlug);
        $this->f3->set('active_type', $type ?? 'all');
        $this->f3->set('focus_section', $focusSection);
        $this->f3->set('count_nominator', $countNominator);
        $this->f3->set('count_nominee', $countNominee);

        // 9) Render
        echo \Template::instance()->render('app/awards/questions/index.htm');
    }

    // ------------ small helpers ------------

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


}
