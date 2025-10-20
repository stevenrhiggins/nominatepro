<?php
namespace App\Controllers;

use App\Contracts\RendererInterface;
use App\Support\renderHtml;
use App\Repositories\QuestionRepository;
use App\Services\Awards\AwardsService;
use App\Repositories\AwardRepository;
use App\Services\ChecklistService;
use Base;

class AwardsChecklistController
{

    private Base $f3;
    private RendererInterface $renderer;
    private ChecklistService $service;
    private AwardsService $award;


    public function __construct(Base $f3)
    {
        $this->f3 = $f3;
        $questionRepository = new QuestionRepository($this->f3->get('DB'));
        $this->service = new ChecklistService($questionRepository);

        $awardRepository = new AwardRepository($this->f3->get('DB'));
        $this->award = new AwardsService($awardRepository, $f3);

        $this->renderer = new renderHtml($f3);
    }

    public function index(Base $f3, array $params = []): void
    {
        $awardSlug = (string)($params['awardSlug'] ?? $this->f3->get('SESSION.awardSlug') ?? '');
        echo $awardSlug;
        $award = $this->award->fetchAwardByAwardSlug($awardSlug);
        $f3->set('award', $award[0]);

        $metrics = $this->award->getMetricsForAward($awardSlug);
        $this->f3->set('metrics', $metrics);

        if ($awardSlug === '') {
            \Flash::instance()->addMessage('Award not specified', 'danger');
            $this->f3->reroute('/app/awards');
            return;
        }

        $summary = $this->service->build($awardSlug);

        $this->renderer->render(
            '/views/settings/awards/checklist.htm',
            '/views/settings/layout.htm',
            [
                'PAGE_TITLE'     => 'Award Checklist',
                'awardSlug' => $awardSlug,
                'sections_count' => $summary['sections_count'],
                'nominator_count' => $summary['nominator_count'],
                'nominee_count' => $summary['nominee_count'],
                'has_sections' => $summary['has_sections'],
                'has_nominator_qs' => $summary['has_nominator_qs'],
                'has_nominee_qs' => $summary['has_nominee_qs'],
            ]
        );

    }
}
