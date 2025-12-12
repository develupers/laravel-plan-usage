<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Actions\Subscription;

use Develupers\PlanUsage\Contracts\BillingProvider;
use Develupers\PlanUsage\Contracts\Billable;
use Develupers\PlanUsage\Contracts\CheckoutSession;
use Develupers\PlanUsage\Models\PlanPrice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Provider-agnostic action for creating checkout sessions.
 *
 * This action delegates to the configured billing provider (Stripe or Paddle)
 * to create a checkout session for subscription.
 */
class CreateCheckoutSessionAction
{
    public function __construct(
        protected BillingProvider $billingProvider
    ) {}

    /**
     * Create a checkout session for subscription.
     *
     * @param  Model&Billable  $billable  The billable entity
     * @param  string  $priceId  The provider's price ID
     * @param  array  $sessionOptions  Additional checkout session options
     * @param  string  $subscriptionName  The subscription name (default: 'default')
     *
     * @throws ValidationException
     */
    public function execute(
        Model $billable,
        string $priceId,
        array $sessionOptions = [],
        string $subscriptionName = 'default'
    ): CheckoutSession {
        // Check if billable already has an active subscription
        if (method_exists($billable, 'subscribed') && $billable->subscribed($subscriptionName)) {
            throw ValidationException::withMessages([
                'subscription' => ['You already have an active subscription. Please manage it from your billing portal.'],
            ]);
        }

        // Validate the price ID exists in our system
        $planPrice = PlanPrice::findByProviderPriceId($priceId);
        if (! $planPrice) {
            throw ValidationException::withMessages([
                'price' => ['The selected price is not available.'],
            ]);
        }

        // Check if the plan is available for purchase
        $plan = $planPrice->plan;
        if (! $plan || ! $plan->isAvailableForPurchase()) {
            throw ValidationException::withMessages([
                'plan' => ['The selected plan is not available for purchase.'],
            ]);
        }

        // Add subscription name to options
        $sessionOptions['subscription_name'] = $subscriptionName;

        // Delegate to the billing provider
        return $this->billingProvider->createCheckoutSession($billable, $priceId, $sessionOptions);
    }

    /**
     * Create a checkout session for a plan price model.
     *
     * @param  Model&Billable  $billable  The billable entity
     * @param  PlanPrice  $planPrice  The plan price model
     * @param  array  $sessionOptions  Additional checkout session options
     * @param  string  $subscriptionName  The subscription name
     *
     * @throws ValidationException
     */
    public function executeForPlanPrice(
        Model $billable,
        PlanPrice $planPrice,
        array $sessionOptions = [],
        string $subscriptionName = 'default'
    ): CheckoutSession {
        $priceId = $planPrice->getProviderPriceId();

        if (! $priceId) {
            throw ValidationException::withMessages([
                'price' => [
                    sprintf(
                        'The selected price is not configured for %s.',
                        ucfirst($this->billingProvider->name())
                    ),
                ],
            ]);
        }

        return $this->execute($billable, $priceId, $sessionOptions, $subscriptionName);
    }

    /**
     * Get the current billing provider.
     */
    public function getProvider(): BillingProvider
    {
        return $this->billingProvider;
    }

    /**
     * Get the provider name.
     */
    public function getProviderName(): string
    {
        return $this->billingProvider->name();
    }
}
