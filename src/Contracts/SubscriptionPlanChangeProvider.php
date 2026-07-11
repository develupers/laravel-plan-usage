<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Contracts;

use Develupers\PlanUsage\Enums\SubscriptionChangeTiming;
use Develupers\PlanUsage\Support\ProviderSubscriptionChange;
use Illuminate\Database\Eloquent\Model;

/**
 * Capability contract for providers that support managed plan changes.
 *
 * Only timings the provider supports NATIVELY are exposed — the package never
 * emulates provider-side scheduling (see supportsTiming()). Plan-change
 * actions depend on this contract alone, not on the full lifecycle surface.
 */
interface SubscriptionPlanChangeProvider
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
     * Whether the provider natively supports the given change timing.
     *
     * Lets consumers feature-detect (e.g. hide a "downgrade at renewal"
     * option) instead of catching the ValidationException changeSubscription
     * throws for unsupported timings.
     */
    public function supportsTiming(SubscriptionChangeTiming $timing): bool;
}
