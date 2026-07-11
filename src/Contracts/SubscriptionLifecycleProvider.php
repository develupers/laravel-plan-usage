<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Contracts;

use Develupers\PlanUsage\Enums\SubscriptionChangeTiming;
use Develupers\PlanUsage\Support\ProviderSubscriptionChange;
use Illuminate\Database\Eloquent\Model;

interface SubscriptionLifecycleProvider
{
    /**
     * @param  Model&Billable  $billable
     */
    public function changeSubscription(
        Model $billable,
        string $productId,
        SubscriptionChangeTiming $timing,
        string $subscriptionName = 'default'
    ): ProviderSubscriptionChange;

    /**
     * @param  Model&Billable  $billable
     */
    public function cancelPendingSubscriptionChange(Model $billable, string $subscriptionName = 'default'): void;

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
