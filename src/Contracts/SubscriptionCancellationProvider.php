<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Capability contract for providers that support managed cancellation
 * and resumption of subscriptions.
 */
interface SubscriptionCancellationProvider
{
    /**
     * @param  Model&Billable  $billable
     */
    public function cancelSubscription(
        Model $billable,
        bool $immediately = false,
        string $subscriptionName = 'default'
    ): void;

    /**
     * @param  Model&Billable  $billable
     */
    public function resumeSubscription(Model $billable, string $subscriptionName = 'default'): void;
}
