<?php
namespace App\Controllers;

use App\Support\renderHtml;
use App\Repositories\QuestionRepository;
use App\Repositories\AwardRepository;
use Base;

class AwardsChecklistController
{

    private Base $f3;
    private renderHtml $renderer;
    private AwardRepository $award;
    private QuestionRepository $questions;


    public function __construct(Base $f3)
    {
        $this->f3 = $f3;
        $this->questions = new QuestionRepository($f3->get('DB'));
        $this->award = new AwardRepository($f3);
        $this->renderer = new renderHtml($f3);
    }

    public function index(Base $f3, array $params = []): void
    {
        $awardSlug = (string)($params['awardSlug'] ?? $this->f3->get('SESSION.awardSlug') ?? '');
        $award = $this->award->fetchAwardByAwardSlug($awardSlug);
        $f3->set('award', $award[0]);

        $metrics = $this->award->getMetricsForAward($awardSlug);
        $this->f3->set('metrics', $metrics);

        if ($awardSlug === '') {
            \Flash::instance()->addMessage('Award not specified', 'danger');
            $this->f3->reroute('/app/awards');
            return;
        }

        $sectionsCount = $this->questions->countSectionsByAward($awardSlug);

        $this->renderer->render(
            'settings/awards/checklist.htm',
            'settings/layout.htm',
            [
                'PAGE_TITLE'        => 'Award Checklist',
                'awardSlug'         => $awardSlug,
                'sections_count'    =>  $sectionsCount,
//                'nominator_count' => $summary['nominator_count'],
//                'nominee_count' => $summary['nominee_count'],
//                'has_sections' => $summary['has_sections'],
//                'has_nominator_qs' => $summary['has_nominator_qs'],
//                'has_nominee_qs' => $summary['has_nominee_qs'],
            ]
        );

    }
}
