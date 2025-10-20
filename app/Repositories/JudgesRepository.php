<?php

namespace App\Repositories;

use App\Contracts\JudgesRepositoryInterface;
use DB\SQL;

class JudgesRepository implements JudgesRepositoryInterface
{
    public function __construct(private SQL $db) {}

    public function fetchJudges(string $awardSlug): array
    {
        return $this->db()->exec(
            'SELECT `judge_name`, `judge_email` FROM `judges` WHERE `award_slug`=?',
            [$awardSlug]
        );
    }
}