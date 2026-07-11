<?php

declare(strict_types=1);

namespace Develupers\PlanUsage;

use Develupers\PlanUsage\Actions\Subscription\CancelPendingPlanChangeAction;
use Develupers\PlanUsage\Actions\Subscription\ChangeSubscriptionPlanAction;
use Develupers\PlanUsage\Enums\SubscriptionChangeTiming;
use Develupers\PlanUsage\Models\Plan;
use Develupers\PlanUsage\Models\PlanPrice;
use Develupers\PlanUsage\Models\SubscriptionPlanChange;
use Develupers\PlanUsage\Services\PlanManager;
use Develupers\PlanUsage\Services\QuotaEnforcer;
use Develupers\PlanUsage\Services\UsageTracker;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

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
    public function consume($billable, string $featureSlug, float $amount = 1, array $metadata = []): bool
    {
        $allowed = $this->quotaEnforcer->enforce($billable, $featureSlug, $amount);

        if ($allowed) {
            try {
                $this->usageTracker->record($billable, $featureSlug, $amount, $metadata ?: null);
            } catch (\Exception $e) {
                Log::error('Failed to record usage after quota enforcement', [
                    'feature' => $featureSlug,
                    'amount' => $amount,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $allowed;
    }

    /**
     * Change a billable's subscription to a different plan price through the
     * billing provider. Requires a SubscriptionLifecycleProvider.
     */
    public function changePlan(
        $billable,
        PlanPrice $targetPlanPrice,
        SubscriptionChangeTiming|string $timing = SubscriptionChangeTiming::Immediate,
        ?string $subscriptionName = null
    ): SubscriptionPlanChange {
        if (is_string($timing)) {
            $timing = SubscriptionChangeTiming::from($timing);
        }

        return app(ChangeSubscriptionPlanAction::class)
            ->execute($billable, $targetPlanPrice, $timing, $subscriptionName);
    }

    /**
     * Cancel a billable's pending (scheduled) plan change.
     */
    public function cancelPendingPlanChange($billable, ?string $subscriptionName = null): SubscriptionPlanChange
    {
        return app(CancelPendingPlanChangeAction::class)->execute($billable, $subscriptionName);
    }

    /**
     * Get all plans
     */
    public function getAllPlans(): Collection
    {
        return $this->planManager->getAllPlans();
    }

    /**
     * Find a plan
     */
    public function findPlan($identifier): ?Plan
    {
        return $this->planManager->findPlan($identifier);
    }
}
