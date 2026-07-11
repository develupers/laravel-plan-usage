<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Traits;

use Danestves\LaravelPolar\LaravelPolar;
use Laravel\Paddle\Cashier;

/**
 * Trait for detecting the configured billing provider.
 *
 * This trait provides a centralized way to determine whether Stripe, Paddle,
 * or Polar is the active billing provider based on configuration or auto-detection.
 */
trait DetectsBillingProvider
{
    /**
     * Detect the current billing provider.
     *
     * @return string 'stripe', 'paddle', or 'polar'
     */
    protected function detectBillingProvider(): string
    {
        $provider = config('plan-usage.billing.provider', 'auto');

        if ($provider === 'auto') {
            if (class_exists(Cashier::class)) {
                return 'paddle';
            }

            if (class_exists(LaravelPolar::class)) {
                return 'polar';
            }

            return 'stripe';
        }

        return $provider;
    }

    /**
     * Check if the current provider is Stripe.
     */
    protected function isStripeProvider(): bool
    {
        return $this->detectBillingProvider() === 'stripe';
    }

    /**
     * Check if the current provider is Paddle.
     */
    protected function isPaddleProvider(): bool
    {
        return $this->detectBillingProvider() === 'paddle';
    }

    /**
     * Check if the current provider is Polar.
     */
    protected function isPolarProvider(): bool
    {
        return $this->detectBillingProvider() === 'polar';
    }
}
