<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Interface for billing provider implementations.
 *
 * This interface defines the contract for billing providers (Stripe, Paddle, etc.)
 * to enable provider-agnostic subscription management.
 */
interface BillingProvider
{
    /**
     * Get the provider name.
     *
     * @return string 'stripe' or 'paddle'
     */
    public function name(): string;

    /**
     * Get the column name for storing the customer ID.
     *
     * @return string e.g., 'stripe_id' or 'paddle_id'
     */
    public function getCustomerIdColumn(): string;

    /**
     * Get the column name for storing the price ID.
     *
     * @return string e.g., 'stripe_price_id' or 'paddle_price_id'
     */
    public function getPriceIdColumn(): string;

    /**
     * Get the column name for storing the product ID.
     *
     * @return string e.g., 'stripe_product_id' or 'paddle_product_id'
     */
    public function getProductIdColumn(): string;

    /**
     * Get the webhook event class to listen for.
     *
     * @return string The fully qualified event class name
     */
    public function getWebhookEventClass(): string;

    /**
     * Create a checkout session for subscription.
     *
     * @param  Model&Billable  $billable  The billable entity
     * @param  string  $priceId  The provider's price ID
     * @param  array  $options  Additional checkout options
     * @return CheckoutSession The checkout session
     */
    public function createCheckoutSession(Model $billable, string $priceId, array $options = []): CheckoutSession;

    /**
     * Sync local plans to the billing provider.
     *
     * @param  iterable  $plans  The plans to sync
     * @param  array  $options  Sync options (e.g., force, dry-run)
     * @return array Results of the sync operation
     */
    public function syncProducts(iterable $plans, array $options = []): array;

    /**
     * Check if the provider's SDK is installed.
     *
     * @return bool True if the SDK is available
     */
    public function isInstalled(): bool;

    /**
     * Find a billable entity by the provider's customer ID.
     *
     * @param  string  $customerId  The provider's customer ID
     * @return Model|null The billable entity or null
     */
    public function findBillableByCustomerId(string $customerId): ?Model;
}
