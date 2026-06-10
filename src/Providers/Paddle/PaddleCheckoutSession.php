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
        return 'paddle_'.uniqid();
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
     * Build a Paddle.Checkout.open() payload for the OVERLAY widget.
     *
     * Cashier Paddle's own Checkout::options() targets the inline widget
     * (displayMode=inline plus a frame style) and its array_filter strips the
     * allowLogout=false it sets, so it is not safe for overlay use. This
     * payload pins the checkout to the attached customer: without
     * allowLogout=false the buyer could "log out" inside the widget and pay
     * under a different customer_id, which webhook listeners can never
     * resolve back to the billable.
     *
     * @return array<string, mixed>
     */
    public function getOverlayOptions(): array
    {
        $settings = [
            'displayMode' => 'overlay',
            'allowLogout' => false,
        ];

        if (method_exists($this->checkout, 'getReturnUrl') && ($returnUrl = $this->checkout->getReturnUrl()) !== null) {
            $settings['successUrl'] = $returnUrl;
        }

        $options = [
            'items' => method_exists($this->checkout, 'getItems') ? $this->checkout->getItems() : [],
            'settings' => $settings,
        ];

        if (method_exists($this->checkout, 'getCustomer') && ($customer = $this->checkout->getCustomer()) !== null) {
            $options['customer'] = ['id' => $customer->paddle_id];
        }

        if (method_exists($this->checkout, 'getCustomData') && ($custom = $this->checkout->getCustomData()) !== []) {
            $options['customData'] = $custom;
        }

        return $options;
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
