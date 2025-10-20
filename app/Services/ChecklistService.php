<?php
namespace App\Services;

use App\Repositories\QuestionRepository;

class ChecklistService
{
    private QuestionRepository $questions;

    public function __construct(QuestionRepository $questions)
    {
        $this->questions = $questions;
    }

    /**
     * Build the checklist summary used by the view.
     * Types default to 'nominator' and 'nominee' to mirror the old site.
     */
    public function build(string $awardSlug, string $nominatorType = 'nominator', string $nomineeType = 'nominee'): array
    {

        return [
            'awardSlug'        => $awardSlug,
            'has_sections'     => $sectionsCount > 0,
            'has_nominator_qs' => $nominatorCount > 0,
            'has_nominee_qs'   => $nomineeCount > 0,
        ];
    }
}
