<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Contracts;

use Develupers\PlanUsage\Models\Plan;
use Develupers\PlanUsage\Models\PlanPrice;
use Laravel\Cashier\Checkout;

/**
 * Interface for managing subscriptions.
 *
 * This interface defines the contract for subscription management
 * with Laravel Cashier and Stripe integration.
 */
interface SubscriptionManager
{
    /**
     * Cancel a subscription.
     *
     * @param  Billable  $billable  The billable entity
     * @param  bool  $immediately  Whether to cancel immediately or at period end
     */
    public function cancel(Billable $billable, bool $immediately = false): void;

    /**
     * Create a Stripe checkout session.
     *
     * @param  Billable  $billable  The billable entity
     * @param  string  $priceId  The Stripe price ID
     * @param  array  $sessionOptions  Additional checkout session options
     * @return Checkout The checkout session
     */
    public function createCheckoutSession(Billable $billable, string $priceId, array $sessionOptions = []): Checkout;

    /**
     * Delete a subscription and clean up related data.
     *
     * @param  Billable  $billable  The billable entity
     * @param  bool  $useDefaultPlan  Whether to set a default plan after deletion
     */
    public function delete(Billable $billable, bool $useDefaultPlan = false): void;

    /**
     * Sync a plan with a billable entity.
     *
     * @param  Billable  $billable  The billable entity
     * @param  string|Plan|PlanPrice  $planOrPrice  The plan, price, or Stripe price ID
     * @return bool True if successfully synced
     */
    public function syncPlan(Billable $billable, string|Plan|PlanPrice $planOrPrice): bool;

    /**
     * Reconcile local subscriptions with Stripe.
     *
     * @param  Billable|null  $billable  Optional specific billable to reconcile
     * @return array Results of the reconciliation
     */
    public function reconcile(?Billable $billable = null): array;

    /**
     * Check if a billable entity has an active subscription.
     *
     * @param  Billable  $billable  The billable entity
     * @param  string  $type  The subscription type/name
     */
    public function hasActiveSubscription(Billable $billable, string $type = 'default'): bool;

    /**
     * Get the current subscription for a billable entity.
     *
     * @param  Billable  $billable  The billable entity
     * @param  string  $type  The subscription type/name
     * @return mixed The subscription or null
     */
    public function getSubscription(Billable $billable, string $type = 'default');

    /**
     * Update a subscription to a new plan/price.
     *
     * @param  Billable  $billable  The billable entity
     * @param  string  $priceId  The new Stripe price ID
     * @return mixed The updated subscription
     */
    public function updateSubscription(Billable $billable, string $priceId);
}
