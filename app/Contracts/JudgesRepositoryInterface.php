<?php

namespace App\Contracts;

use DB\SQL\Mapper;
interface JudgesRepositoryInterface
{
    public function fetchJudges(string $awardSlug): array;
}