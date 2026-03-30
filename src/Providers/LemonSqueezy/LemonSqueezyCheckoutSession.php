<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Providers\LemonSqueezy;

use Develupers\PlanUsage\Contracts\CheckoutSession;
use Illuminate\Http\RedirectResponse;

/**
 * LemonSqueezy checkout session wrapper.
 *
 * Wraps the LemonSqueezy checkout object to provide a provider-agnostic interface.
 * LemonSqueezy supports both hosted checkout pages and JavaScript overlay.
 */
class LemonSqueezyCheckoutSession implements CheckoutSession
{
    public function __construct(
        protected mixed $checkout
    ) {}

    /**
     * Get the checkout URL.
     */
    public function getUrl(): string
    {
        if (method_exists($this->checkout, 'url')) {
            return $this->checkout->url();
        }

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

        return 'ls_'.uniqid();
    }

    /**
     * Create a redirect response to the checkout.
     */
    public function redirect(): RedirectResponse
    {
        if (method_exists($this->checkout, 'redirect')) {
            return $this->checkout->redirect();
        }

        $url = $this->getUrl();
        if ($url) {
            return redirect($url);
        }

        return redirect()->back();
    }

    /**
     * Get the underlying LemonSqueezy checkout object.
     */
    public function getProviderCheckout(): mixed
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
            'provider' => 'lemon-squeezy',
        ];
    }
}
