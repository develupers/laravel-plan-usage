<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Develupers\PlanUsage\Services\PlanManager plans()
 * @method static \Develupers\PlanUsage\Services\UsageTracker usage()
 * @method static \Develupers\PlanUsage\Services\QuotaEnforcer quotas()
 * @method static bool can($billable, string $featureSlug, float $amount = 1)
 * @method static void record($billable, string $featureSlug, float $amount = 1, ?array $metadata = null)
 * @method static \Illuminate\Support\Collection getAllPlans()
 * @method static ?\Develupers\PlanUsage\Models\Plan findPlan($identifier)
 *
 * @see \Develupers\PlanUsage\PlanUsage
 */
class PlanUsage extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'plan-usage';
    }
}
