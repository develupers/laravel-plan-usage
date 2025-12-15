<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Traits;

/**
 * Trait for detecting the configured billing provider.
 *
 * This trait provides a centralized way to determine whether Stripe or Paddle
 * is the active billing provider based on configuration or auto-detection.
 */
trait DetectsBillingProvider
{
    /**
     * Detect the current billing provider.
     *
     * @return string 'stripe' or 'paddle'
     */
    protected function detectBillingProvider(): string
    {
        $provider = config('plan-usage.billing.provider', 'auto');

        if ($provider === 'auto') {
            return class_exists(\Laravel\Paddle\Cashier::class) ? 'paddle' : 'stripe';
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
}
