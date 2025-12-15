<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Actions\Subscription;

use Develupers\PlanUsage\Contracts\Billable;
use Develupers\PlanUsage\Contracts\CheckoutSession;
use Develupers\PlanUsage\Models\PlanPrice;
use Develupers\PlanUsage\Providers\Stripe\StripeProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Laravel\Cashier\Checkout;

/**
 * Stripe-specific checkout session action.
 *
 * @deprecated Use CreateCheckoutSessionAction with the BillingProvider interface for provider-agnostic checkout.
 */
class CreateStripeCheckoutSessionAction
{
    protected StripeProvider $provider;

    public function __construct()
    {
        $this->provider = new StripeProvider;
    }

    /**
     * Create a new Stripe Checkout session for subscription.
     *
     * @param  Model&Billable  $billable  The billable entity
     * @param  string  $priceId  The Stripe price ID
     * @param  array  $sessionOptions  Additional checkout session options
     * @param  string  $subscriptionName  The subscription name (default: 'default')
     *
     * @throws ValidationException
     */
    public function execute(
        $billable,
        string $priceId,
        array $sessionOptions = [],
        string $subscriptionName = 'default'
    ): Checkout|CheckoutSession {
        // Check if billable already has an active subscription
        if ($billable->subscribed($subscriptionName)) {
            throw ValidationException::withMessages([
                'subscription' => ['You already have an active subscription. Please manage it from your billing portal.'],
            ]);
        }

        // Validate the price ID exists in our system
        $planPrice = PlanPrice::where('stripe_price_id', $priceId)->first();
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

        // Ensure the billable has a Stripe customer ID
        if (method_exists($billable, 'createOrGetStripeCustomer')) {
            $billable->createOrGetStripeCustomer();
        }

        // Merge default options with provided options
        $defaultOptions = [
            'success_url' => $this->getSuccessUrl($sessionOptions),
            'cancel_url' => $this->getCancelUrl($sessionOptions),
        ];

        // Allow metadata to be passed
        if (isset($sessionOptions['metadata'])) {
            $defaultOptions['metadata'] = $sessionOptions['metadata'];
        }

        // Allow additional line items
        if (isset($sessionOptions['line_items'])) {
            $defaultOptions['line_items'] = $sessionOptions['line_items'];
        }

        // Allow customer update
        if (isset($sessionOptions['allow_promotion_codes'])) {
            $defaultOptions['allow_promotion_codes'] = $sessionOptions['allow_promotion_codes'];
        }

        $checkoutOptions = array_merge($defaultOptions, $sessionOptions);

        // Create checkout session with minimal options
        // Cashier will handle all the customer information pre-filling
        return $billable->newSubscription($subscriptionName, $priceId)
            ->checkout($checkoutOptions);
    }

    /**
     * Create a checkout session for a plan price model.
     *
     * @param  Model&Billable  $billable  The billable entity
     * @param  PlanPrice  $planPrice  The plan price model
     * @param  array  $sessionOptions  Additional checkout session options
     * @param  string  $subscriptionName  The subscription name
     */
    public function executeForPlanPrice(
        $billable,
        PlanPrice $planPrice,
        array $sessionOptions = [],
        string $subscriptionName = 'default'
    ): Checkout|CheckoutSession {
        if (! $planPrice->stripe_price_id) {
            throw ValidationException::withMessages([
                'price' => ['The selected price is not configured for Stripe.'],
            ]);
        }

        return $this->execute($billable, $planPrice->stripe_price_id, $sessionOptions, $subscriptionName);
    }

    /**
     * Create a checkout session for one-time payment.
     *
     * @param  Billable  $billable  The billable entity
     * @param  string  $priceId  The Stripe price ID
     * @param  array  $sessionOptions  Additional checkout session options
     */
    public function executeOneTime(
        Billable $billable,
        string $priceId,
        array $sessionOptions = []
    ): Checkout {
        // Check if the billable has Cashier's Billable trait methods
        if (! method_exists($billable, 'checkout')) {
            throw ValidationException::withMessages([
                'subscription' => ['The billable model must use Laravel Cashier\'s Billable trait.'],
            ]);
        }

        // Ensure the billable has a Stripe customer ID
        if (method_exists($billable, 'createOrGetStripeCustomer')) {
            $billable->createOrGetStripeCustomer();
        }

        // Merge default options with provided options
        $defaultOptions = [
            'success_url' => $this->getSuccessUrl($sessionOptions),
            'cancel_url' => $this->getCancelUrl($sessionOptions),
        ];

        $checkoutOptions = array_merge($defaultOptions, $sessionOptions);

        return $billable->checkout($priceId, $checkoutOptions);
    }

    /**
     * Get the success URL for the checkout session.
     *
     * @param  array  $options  Session options
     */
    protected function getSuccessUrl(array $options): string
    {
        if (isset($options['success_url'])) {
            return $options['success_url'];
        }

        // Try to use configured URLs
        $baseUrl = config('plan-usage.checkout.success_url');
        if ($baseUrl) {
            return $baseUrl.'?session_id={CHECKOUT_SESSION_ID}';
        }

        // Default to a generic route if available
        if (function_exists('route') && \Route::has('subscription.success')) {
            return route('subscription.success').'?session_id={CHECKOUT_SESSION_ID}';
        }

        return url('/subscription/success?session_id={CHECKOUT_SESSION_ID}');
    }

    /**
     * Get the cancel URL for the checkout session.
     *
     * @param  array  $options  Session options
     */
    protected function getCancelUrl(array $options): string
    {
        if (isset($options['cancel_url'])) {
            return $options['cancel_url'];
        }

        // Try to use configured URLs
        $baseUrl = config('plan-usage.checkout.cancel_url');
        if ($baseUrl) {
            return $baseUrl;
        }

        // Default to a generic route if available
        if (function_exists('route') && \Route::has('subscription.cancel')) {
            return route('subscription.cancel');
        }

        return url('/subscription/cancel');
    }
}
