<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Actions\Subscription;

use Develupers\PlanUsage\Contracts\Billable;
use Illuminate\Validation\ValidationException;

class CancelSubscriptionAction
{
    /**
     * Cancel the billable's subscription.
     *
     * @param  Billable  $billable  The billable entity
     * @param  bool  $immediately  Whether to cancel immediately or at period end
     * @param  string  $subscriptionName  The subscription name (default: 'default')
     *
     * @throws ValidationException
     */
    public function execute(Billable $billable, bool $immediately = false, string $subscriptionName = 'default'): void
    {
        $subscription = $billable->subscription($subscriptionName);

        if (! $subscription) {
            throw ValidationException::withMessages([
                'subscription' => ['No active subscription found.'],
            ]);
        }

        if ($subscription->canceled()) {
            throw ValidationException::withMessages([
                'subscription' => ['Subscription is already cancelled.'],
            ]);
        }

        // Cancel at the period end by default, or immediately if specified
        if ($immediately) {
            $subscription->cancelNow();
        } else {
            $subscription->cancel();
        }
    }

    /**
     * Cancel all subscriptions for the billable.
     *
     * @param  Billable  $billable  The billable entity
     * @param  bool  $immediately  Whether to cancel immediately or at period end
     *
     * @throws ValidationException
     */
    public function cancelAll(Billable $billable, bool $immediately = false): void
    {
        $subscriptions = $billable->subscriptions()->active()->get();

        if ($subscriptions->isEmpty()) {
            throw ValidationException::withMessages([
                'subscription' => ['No active subscriptions found.'],
            ]);
        }

        foreach ($subscriptions as $subscription) {
            if (! $subscription->canceled()) {
                if ($immediately) {
                    $subscription->cancelNow();
                } else {
                    $subscription->cancel();
                }
            }
        }
    }

    /**
     * Resume a cancelled subscription if still in grace period.
     *
     * @param  Billable  $billable  The billable entity
     * @param  string  $subscriptionName  The subscription name (default: 'default')
     *
     * @throws ValidationException
     */
    public function resume(Billable $billable, string $subscriptionName = 'default'): void
    {
        $subscription = $billable->subscription($subscriptionName);

        if (! $subscription) {
            throw ValidationException::withMessages([
                'subscription' => ['No subscription found.'],
            ]);
        }

        if (! $subscription->onGracePeriod()) {
            throw ValidationException::withMessages([
                'subscription' => ['Subscription is not in grace period and cannot be resumed.'],
            ]);
        }

        $subscription->resume();
    }
}
