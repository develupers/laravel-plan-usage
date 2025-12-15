<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Contracts;

use Illuminate\Http\RedirectResponse;

/**
 * Interface for provider-agnostic checkout sessions.
 *
 * This interface wraps provider-specific checkout objects (Stripe Checkout, Paddle Checkout)
 * to provide a unified API for creating subscription checkouts.
 */
interface CheckoutSession
{
    /**
     * Get the checkout URL.
     *
     * @return string The URL to redirect the user to for checkout
     */
    public function getUrl(): string;

    /**
     * Get the checkout session ID.
     *
     * @return string The provider's session ID
     */
    public function getId(): string;

    /**
     * Create a redirect response to the checkout.
     */
    public function redirect(): RedirectResponse;

    /**
     * Get the underlying provider checkout object.
     *
     * @return mixed The provider-specific checkout object
     */
    public function getProviderCheckout(): mixed;
}
