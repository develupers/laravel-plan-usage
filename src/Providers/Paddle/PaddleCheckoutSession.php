<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Providers\Paddle;

use Develupers\PlanUsage\Contracts\CheckoutSession;
use Illuminate\Http\RedirectResponse;

/**
 * Paddle checkout session wrapper.
 *
 * Wraps Laravel Cashier Paddle's Checkout object to provide a provider-agnostic interface.
 * Paddle uses a client-side overlay for checkout rather than a redirect URL.
 */
class PaddleCheckoutSession implements CheckoutSession
{
    public function __construct(
        protected mixed $checkout
    ) {}

    /**
     * Get the checkout URL.
     *
     * Note: Paddle typically uses a client-side overlay, but can also use hosted checkout.
     */
    public function getUrl(): string
    {
        // Paddle checkout may not always have a URL (overlay-based)
        if (method_exists($this->checkout, 'url')) {
            return $this->checkout->url();
        }

        // For overlay-based checkout, return empty string
        // The frontend should use Paddle.js to open the checkout
        return '';
    }

    /**
     * Get the checkout session ID.
     */
    public function getId(): string
    {
        if (method_exists($this->checkout, 'id')) {
            return (string) $this->checkout->id();
        }

        // Generate a unique ID if none available
        return 'paddle_' . uniqid();
    }

    /**
     * Create a redirect response to the checkout.
     *
     * Note: Paddle typically uses overlay-based checkout.
     * For hosted checkout page, this will redirect properly.
     */
    public function redirect(): RedirectResponse
    {
        if (method_exists($this->checkout, 'redirect')) {
            return $this->checkout->redirect();
        }

        // Fallback: return to the current page (overlay will be used)
        return redirect()->back();
    }

    /**
     * Get the underlying Paddle checkout object.
     */
    public function getProviderCheckout(): mixed
    {
        return $this->checkout;
    }

    /**
     * Get data needed for Paddle.js overlay checkout.
     */
    public function getOverlayData(): array
    {
        $data = [
            'provider' => 'paddle',
        ];

        if (method_exists($this->checkout, 'toArray')) {
            return array_merge($data, $this->checkout->toArray());
        }

        return $data;
    }

    /**
     * Convert to array for serialization.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->getId(),
            'url' => $this->getUrl(),
            'provider' => 'paddle',
            'overlay_data' => $this->getOverlayData(),
        ];
    }

    /**
     * Check if this checkout uses overlay (client-side) vs redirect (hosted page).
     */
    public function usesOverlay(): bool
    {
        return empty($this->getUrl());
    }
}
