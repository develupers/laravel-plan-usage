<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Jobs;

use Develupers\PlanUsage\Actions\Subscription\DeleteSubscriptionAction;
use Develupers\PlanUsage\Events\PlanRevoked;
use Develupers\PlanUsage\Models\Plan;
use Develupers\PlanUsage\Support\EntitlementStatusPolicy;
use Develupers\PlanUsage\Support\SubscriptionStateLock;
use Develupers\PlanUsage\Traits\DetectsBillingProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class EnforcePlanSubscriptionsJob implements ShouldQueue
{
    use DetectsBillingProvider;
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
        $provider = $this->detectBillingProvider();

        // Get all non-lifetime plan IDs
        $lifetimePlanIds = Plan::where('is_lifetime', true)->pluck('id');
        $defaultPlanId = config('plan-usage.subscription.default_plan_id');

        // Find billables with a plan that is not lifetime. Billables already
        // on the configured default (free) plan are excluded — re-revoking
        // them every run would delete and recreate their quotas, resetting
        // free-tier usage daily.
        $billables = $modelClass::query()
            ->whereNotNull('plan_id')
            ->when($lifetimePlanIds->isNotEmpty(), fn ($q) => $q->whereNotIn('plan_id', $lifetimePlanIds))
            ->when($defaultPlanId, fn ($q) => $q->where('plan_id', '!=', $defaultPlanId))
            ->with('plan')
            ->get();

        $revokedCount = 0;

        foreach ($billables as $billable) {
            // Cheap pre-check outside the lock; the authoritative decision is
            // re-made under it.
            if ($this->hasActiveSubscription($billable, $subscriptionName, $provider)) {
                continue;
            }

            $previousPlan = $billable->plan;

            // Route through the centralized revocation action: raw FK updates
            // left quota rows untouched (stale paid limits, or usable rows on
            // a planless billable). The action assigns the configured default
            // plan (syncing quotas to it) or clears the plan and deletes the
            // quotas.
            try {
                $revoked = app(SubscriptionStateLock::class)->block($billable, function () use ($billable, $subscriptionName, $provider): bool {
                    // Re-check under the lock against a fresh read: a
                    // concurrent webhook (e.g. a resume) may have restored the
                    // subscription since the pre-check, and revoking from that
                    // stale decision would undo the grant.
                    if ($billable instanceof Model) {
                        $billable->refresh();
                        $billable->unsetRelation('subscriptions');
                    }

                    if ($this->hasActiveSubscription($billable, $subscriptionName, $provider)) {
                        return false;
                    }

                    // A Polar order.paid handler can assign a lifetime plan
                    // while this job waits on the lock — lifetime entitlements
                    // are only revocable via order.refunded, never here.
                    if ($billable instanceof Model && ($billable->plan->is_lifetime ?? false)) {
                        return false;
                    }

                    app(DeleteSubscriptionAction::class)->execute($billable);

                    return true;
                });
            } catch (\Throwable $exception) {
                Log::error('EnforcePlanSubscriptionsJob: failed to revoke plan, will retry next run.', [
                    'billable_type' => get_class($billable),
                    'billable_id' => $billable->getKey(),
                    'error' => $exception->getMessage(),
                ]);

                continue;
            }

            if (! $revoked) {
                continue;
            }

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
     * Check if the billable's subscription still holds entitlements.
     *
     * Uses the shared EntitlementStatusPolicy rather than Cashier's active():
     * Cashier Paddle's active() excludes trialing, and both Cashiers treat
     * past_due as inactive — the policy (and the webhook listeners using it)
     * may intentionally keep both.
     */
    private function hasActiveSubscription(mixed $billable, string $subscriptionName, string $provider): bool
    {
        if (! method_exists($billable, 'subscription')) {
            return false;
        }

        $subscription = $billable->subscription($subscriptionName);

        if (! $subscription) {
            return false;
        }

        // On grace period (cancelled but not yet expired) keeps the plan.
        if (method_exists($subscription, 'onGracePeriod') && $subscription->onGracePeriod()) {
            return true;
        }

        $status = $subscription->status ?? $subscription->stripe_status ?? null;

        if ($status !== null) {
            // ends_at gives Polar's 'canceled' its grace-period context: a
            // past end no longer holds entitlements.
            return EntitlementStatusPolicy::statusHoldsEntitlements($provider, $status, $subscription->ends_at ?? null);
        }

        return (bool) $subscription->active();
    }
}
