<?php

namespace App\Services;

use Base;
use App\Contracts\JudgesRepositoryInterface;

class JudgesService
{
    public function __construct(
        private Base $f3,
        private JudgesRepositoryInterface $repo
    ) {}

    public function fetchJudges(string $awardSlug): array
    {
        return $this->repo->fetchJudges($awardSlug);
    }
}