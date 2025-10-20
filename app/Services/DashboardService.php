<?php
declare(strict_types=1);

namespace App\Services;

use Base;
use App\Contracts\AwardRepositoryInterface;   // ✅ correct namespace
use App\Repositories\AwardRepository;         // only needed if you use the fallback ctor

final class DashboardService
{
    private AwardRepositoryInterface $awards;

    public function __construct(
        private Base $f3,
        ?AwardRepositoryInterface $awards = null   // allow DI or fallback
    ) {
        // Fallback to concrete repo if none injected
        $this->awards = $awards ?? new AwardRepository($this->f3);
    }

    public function getAwardMetricsForCpSlug(?string $cpSlug): array
    {
        if ($cpSlug === null || $cpSlug === '') {
            return ['total' => 0, 'active' => 0];
        }

        $total  = $this->awards->countByCpSlug($cpSlug);
        $active = $this->awards->countActiveByCpSlugQuery($cpSlug);

        return ['total' => $total, 'active' => $active];
    }
}
