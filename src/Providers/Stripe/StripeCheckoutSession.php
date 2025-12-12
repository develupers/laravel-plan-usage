<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Providers\Stripe;

use Develupers\PlanUsage\Contracts\CheckoutSession;
use Illuminate\Http\RedirectResponse;
use Laravel\Cashier\Checkout;

/**
 * Stripe checkout session wrapper.
 *
 * Wraps Laravel Cashier's Checkout object to provide a provider-agnostic interface.
 */
class StripeCheckoutSession implements CheckoutSession
{
    public function __construct(
        protected Checkout $checkout
    ) {}

    /**
     * Get the checkout URL.
     */
    public function getUrl(): string
    {
        return $this->checkout->url;
    }

    /**
     * Get the checkout session ID.
     */
    public function getId(): string
    {
        return $this->checkout->id;
    }

    /**
     * Create a redirect response to the checkout.
     */
    public function redirect(): RedirectResponse
    {
        return $this->checkout->redirect();
    }

    /**
     * Get the underlying Stripe checkout object.
     */
    public function getProviderCheckout(): Checkout
    {
        return $this->checkout;
    }

    /**
     * Convert to array for serialization.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->getId(),
            'url' => $this->getUrl(),
            'provider' => 'stripe',
        ];
    }
}
