<?php

namespace App\Services;

use App\Repositories\AwardRepositoryInterface;

class AwardNavService
{
    public function __construct(private AwardRepositoryInterface $repo) {}

    public function build(string $awardSlug, string $cpSlug): ?array
    {

        $nominatorQuestionCount    = $this->repo->countQuestionsByType($awardSlug, 'nominator');
        $nomineeQuestionCount    = $this->repo->countQuestionsByType($awardSlug, 'nominee');
        $sectionsCount = $this->repo->countSectionsByCpSlug($cpSlug);

        // Shape the arrays exactly how your nav partial expects them
        return [
            'meta' => [
                'id'     => (int)$award['id'],
                'name'   => $award['name'],
                'status' => $award['status'],
                'slug'   => $awardSlug,
            ],
            'links' => [
                ['label'=>'Overview',   'url'=>"/app/awards/{$awardSlug}/overview"],
                ['label'=>"Nominations ({$counts['submitted']})", 'url'=>"/app/awards/{$awardSlug}/nominations"],
                ['label'=>'Settings',   'url'=>"/app/awards/{$awardSlug}/settings"],
            ],
            'questions' => array_map(
                fn($q)=>[
                    'label'=>$q['label'],
                    'url'=>"/app/awards/{$awardSlug}/questions/{$q['id']}"
                ],
                $questions
            ),
        ];
    }
}