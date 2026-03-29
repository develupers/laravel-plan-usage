<?php

declare(strict_types=1);

namespace Develupers\PlanUsage;

use Develupers\PlanUsage\Services\PlanManager;
use Develupers\PlanUsage\Services\QuotaEnforcer;
use Develupers\PlanUsage\Services\UsageTracker;

class PlanUsage
{
    protected PlanManager $planManager;

    protected UsageTracker $usageTracker;

    protected QuotaEnforcer $quotaEnforcer;

    public function __construct()
    {
        $this->planManager = app('plan-usage.manager');
        $this->usageTracker = app('plan-usage.tracker');
        $this->quotaEnforcer = app('plan-usage.quota');
    }

    /**
     * Get the plan manager instance
     */
    public function plans(): PlanManager
    {
        return $this->planManager;
    }

    /**
     * Get the usage tracker instance
     */
    public function usage(): UsageTracker
    {
        return $this->usageTracker;
    }

    /**
     * Get the quota enforcer instance
     */
    public function quotas(): QuotaEnforcer
    {
        return $this->quotaEnforcer;
    }

    /**
     * Check if a billable can use a feature (read-only).
     */
    public function checkQuota($billable, string $featureSlug, float $amount = 1): bool
    {
        return $this->quotaEnforcer->canUse($billable, $featureSlug, $amount);
    }

    /**
     * Consume a feature: enforce quota, increment usage, and log.
     */
    public function consume($billable, string $featureSlug, float $amount = 1, ?array $metadata = null): bool
    {
        $allowed = $this->quotaEnforcer->enforce($billable, $featureSlug, $amount);

        if ($allowed) {
            $this->usageTracker->record($billable, $featureSlug, $amount, $metadata);
        }

        return $allowed;
    }

    /**
     * Get all plans
     */
    public function getAllPlans(): \Illuminate\Support\Collection
    {
        return $this->planManager->getAllPlans();
    }

    /**
     * Find a plan
     */
    public function findPlan($identifier): ?\Develupers\PlanUsage\Models\Plan
    {
        return $this->planManager->findPlan($identifier);
    }
}
