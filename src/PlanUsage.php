<?php

declare(strict_types=1);

namespace Develupers\PlanUsage;

use Develupers\PlanUsage\Services\PlanManager;
use Develupers\PlanUsage\Services\UsageTracker;
use Develupers\PlanUsage\Services\QuotaEnforcer;

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
     * Quick check if a billable can use a feature
     */
    public function can($billable, string $featureSlug, float $amount = 1): bool
    {
        return $this->quotaEnforcer->canUse($billable, $featureSlug, $amount);
    }

    /**
     * Quick record usage for a billable
     */
    public function record($billable, string $featureSlug, float $amount = 1, ?array $metadata = null): void
    {
        $this->usageTracker->record($billable, $featureSlug, $amount, $metadata);
        $this->quotaEnforcer->increment($billable, $featureSlug, $amount);
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
