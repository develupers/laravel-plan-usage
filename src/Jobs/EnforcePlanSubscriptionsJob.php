<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Jobs;

use Develupers\PlanUsage\Events\PlanRevoked;
use Develupers\PlanUsage\Models\Plan;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class EnforcePlanSubscriptionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Execute the job.
     *
     * For each billable with a non-lifetime plan but no active subscription,
     * revoke the plan and dispatch a PlanRevoked event.
     */
    public function handle(): void
    {
        $modelClass = config('plan-usage.models.billable') ?? config('cashier.model');

        if (! $modelClass || ! class_exists($modelClass)) {
            Log::warning('EnforcePlanSubscriptionsJob: Billable model not configured.');

            return;
        }

        $subscriptionName = config('plan-usage.subscription.default_name', 'default');
        $defaultPlanId = config('plan-usage.subscription.default_plan_id');

        // Get all non-lifetime plan IDs
        $lifetimePlanIds = Plan::where('is_lifetime', true)->pluck('id');

        // Find billables with a plan that is not lifetime
        $billables = $modelClass::query()
            ->whereNotNull('plan_id')
            ->when($lifetimePlanIds->isNotEmpty(), fn ($q) => $q->whereNotIn('plan_id', $lifetimePlanIds))
            ->with('plan')
            ->get();

        $revokedCount = 0;

        foreach ($billables as $billable) {
            if ($this->hasActiveSubscription($billable, $subscriptionName)) {
                continue;
            }

            $previousPlan = $billable->plan;

            $billable->update([
                'plan_id' => $defaultPlanId,
                'plan_price_id' => null,
            ]);

            if ($previousPlan) {
                PlanRevoked::dispatch($billable, $previousPlan, 'no_active_subscription');
            }

            $revokedCount++;
        }

        if ($revokedCount > 0) {
            Log::info("EnforcePlanSubscriptionsJob: Revoked plans for {$revokedCount} billable(s).");
        }
    }

    /**
     * Check if the billable has an active subscription or is on a grace period.
     */
    private function hasActiveSubscription(mixed $billable, string $subscriptionName): bool
    {
        if (! method_exists($billable, 'subscription')) {
            return false;
        }

        $subscription = $billable->subscription($subscriptionName);

        if (! $subscription) {
            return false;
        }

        // Active or on grace period (cancelled but not yet expired)
        if (method_exists($subscription, 'onGracePeriod') && $subscription->onGracePeriod()) {
            return true;
        }

        return $subscription->active();
    }
}
