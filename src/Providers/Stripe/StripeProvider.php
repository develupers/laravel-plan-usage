<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Providers\Stripe;

use Carbon\CarbonImmutable;
use Develupers\PlanUsage\Contracts\Billable;
use Develupers\PlanUsage\Contracts\BillingProvider;
use Develupers\PlanUsage\Contracts\CheckoutSession;
use Develupers\PlanUsage\Contracts\SubscriptionLifecycleProvider;
use Develupers\PlanUsage\Enums\SubscriptionChangeTiming;
use Develupers\PlanUsage\Support\ProviderSubscriptionChange;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Events\WebhookHandled;
use Laravel\Cashier\Subscription as CashierSubscription;
use Stripe\Price;
use Stripe\Product;
use Stripe\Stripe;

/**
 * Stripe billing provider implementation.
 *
 * This class provides Stripe-specific billing functionality through Laravel Cashier.
 */
class StripeProvider implements BillingProvider, SubscriptionLifecycleProvider
{
    /**
     * Get the provider name.
     */
    public function name(): string
    {
        return 'stripe';
    }

    /**
     * Get the column name for storing the customer ID.
     */
    public function getCustomerIdColumn(): string
    {
        return 'stripe_id';
    }

    /**
     * Get the column name for storing the price ID.
     */
    public function getPriceIdColumn(): string
    {
        return 'stripe_price_id';
    }

    /**
     * Get the column name for storing the product ID.
     */
    public function getProductIdColumn(): string
    {
        return 'stripe_product_id';
    }

    /**
     * Get the webhook event class to listen for.
     */
    public function getWebhookEventClass(): string
    {
        return WebhookHandled::class;
    }

    /**
     * Create a checkout session for subscription.
     */
    public function createCheckoutSession(Model $billable, string $priceId, array $options = []): CheckoutSession
    {
        // Ensure the billable has a Stripe customer ID
        if (method_exists($billable, 'createOrGetStripeCustomer')) {
            $billable->createOrGetStripeCustomer();
        }

        $subscriptionName = $options['subscription_name'] ?? 'default';

        // Merge default options with provided options
        $defaultOptions = [
            'success_url' => $options['success_url'] ?? $this->getSuccessUrl($options),
            'cancel_url' => $options['cancel_url'] ?? $this->getCancelUrl($options),
        ];

        if (isset($options['allow_promotion_codes'])) {
            $defaultOptions['allow_promotion_codes'] = $options['allow_promotion_codes'];
        }

        $checkoutOptions = array_merge($defaultOptions, $options);

        // Create checkout session
        $checkout = $billable->newSubscription($subscriptionName, $priceId)
            ->checkout($checkoutOptions);

        return new StripeCheckoutSession($checkout);
    }

    /**
     * Sync local plans to Stripe.
     */
    public function syncProducts(iterable $plans, array $options = []): array
    {
        $results = [
            'created' => [],
            'updated' => [],
            'errors' => [],
        ];

        $dryRun = $options['dry_run'] ?? false;
        $force = $options['force'] ?? false;

        Stripe::setApiKey(config('cashier.secret') ?? config('services.stripe.secret'));

        foreach ($plans as $plan) {
            try {
                if ($dryRun) {
                    $results['created'][] = [
                        'plan' => $plan->slug,
                        'action' => $plan->stripe_product_id ? 'update' : 'create',
                        'dry_run' => true,
                    ];

                    continue;
                }

                // Create or update Stripe product
                if ($plan->stripe_product_id && ! $force) {
                    // Product exists, update it
                    $product = Product::update($plan->stripe_product_id, [
                        'name' => $plan->display_name ?? $plan->name,
                        'description' => $plan->description,
                        'metadata' => $plan->metadata ?? [],
                    ]);
                    $results['updated'][] = $plan->slug;
                } else {
                    // Create new product
                    $product = Product::create([
                        'name' => $plan->display_name ?? $plan->name,
                        'description' => $plan->description,
                        'metadata' => array_merge($plan->metadata ?? [], [
                            'plan_slug' => $plan->slug,
                        ]),
                    ]);

                    $plan->stripe_product_id = $product->id;
                    $plan->save();

                    $results['created'][] = $plan->slug;
                }

                // Sync prices for the plan
                foreach ($plan->prices as $planPrice) {
                    if (! $planPrice->stripe_price_id || $force) {
                        $price = Price::create([
                            'product' => $plan->stripe_product_id,
                            'unit_amount' => (int) ($planPrice->price * 100),
                            'currency' => strtolower($planPrice->currency),
                            'recurring' => [
                                'interval' => $this->mapInterval($planPrice->interval->value),
                            ],
                            'metadata' => [
                                'plan_price_id' => $planPrice->id,
                            ],
                        ]);

                        $planPrice->stripe_price_id = $price->id;
                        $planPrice->save();
                    }
                }
            } catch (\Exception $e) {
                $results['errors'][] = [
                    'plan' => $plan->slug,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Check if the Stripe SDK is installed.
     */
    public function isInstalled(): bool
    {
        return class_exists(Cashier::class);
    }

    /**
     * Find a billable entity by Stripe customer ID.
     */
    public function findBillableByCustomerId(string $customerId): ?Model
    {
        $billableClass = config('plan-usage.models.billable') ?? config('cashier.model');

        if (! $billableClass || ! class_exists($billableClass)) {
            return null;
        }

        return $billableClass::where('stripe_id', $customerId)->first();
    }

    /**
     * Change the subscription to a different price.
     *
     * Stripe applies plan swaps immediately (with proration invoiced now).
     * Scheduled (next-period) changes would require Stripe Subscription
     * Schedules, which are not implemented here.
     *
     * @param  Model&Billable  $billable
     * @param  string  $productId  The target Stripe price ID
     */
    public function changeSubscription(
        Model $billable,
        string $productId,
        SubscriptionChangeTiming $timing,
        string $subscriptionName = 'default'
    ): ProviderSubscriptionChange {
        if ($timing !== SubscriptionChangeTiming::Immediate) {
            throw ValidationException::withMessages([
                'subscription' => ['Stripe supports immediate plan changes only. Scheduled changes are not available for this provider.'],
            ]);
        }

        $subscription = $this->cashierSubscription($billable, $subscriptionName);

        // For Stripe the PlanPrice provider identifier is a Stripe price ID.
        // swapAndInvoice() applies the change now and invoices the proration.
        $subscription->swapAndInvoice($productId);

        // Cashier's currentPeriodStart()/currentPeriodEnd() issue one Stripe API
        // retrieve PER subscription item PER call; a single subscription fetch
        // yields both period bounds in one round-trip.
        [$periodStart, $periodEnd] = $this->currentBillingPeriod($subscription);

        return new ProviderSubscriptionChange(
            providerSubscriptionId: (string) $subscription->stripe_id,
            // The swap is synchronous: if it did not throw, the new price is active.
            currentProductId: $productId,
            pendingProductId: null,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
        );
    }

    /**
     * Resolve the current billing period from a single Stripe API call.
     *
     * Mirrors Cashier's semantics: earliest item period start, latest item
     * period end (Stripe moved these fields onto subscription items).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    protected function currentBillingPeriod(CashierSubscription $subscription): array
    {
        $stripeSubscription = $subscription->asStripeSubscription();

        $start = null;
        $end = null;

        foreach ($stripeSubscription->items->data ?? [] as $item) {
            $itemStart = $item->current_period_start ?? null;
            $itemEnd = $item->current_period_end ?? null;

            if ($itemStart !== null && ($start === null || $itemStart < $start)) {
                $start = $itemStart;
            }

            if ($itemEnd !== null && ($end === null || $itemEnd > $end)) {
                $end = $itemEnd;
            }
        }

        $periodStart = $start === null
            ? CarbonImmutable::now()
            : CarbonImmutable::createFromTimestamp($start);

        return [
            $periodStart,
            $end === null ? $periodStart->addMonth() : CarbonImmutable::createFromTimestamp($end),
        ];
    }

    /**
     * Stripe has no scheduled plan changes, so there is nothing to cancel.
     *
     * @param  Model&Billable  $billable
     */
    public function cancelPendingSubscriptionChange(Model $billable, string $subscriptionName = 'default'): void
    {
        throw ValidationException::withMessages([
            'subscription' => ['Stripe does not support scheduled plan changes, so there is no pending change to cancel.'],
        ]);
    }

    /**
     * @param  Model&Billable  $billable
     */
    public function cancelSubscription(
        Model $billable,
        bool $immediately = false,
        string $subscriptionName = 'default'
    ): void {
        $subscription = $this->cashierSubscription($billable, $subscriptionName);

        if ($immediately) {
            $subscription->cancelNow();

            return;
        }

        $subscription->cancel();
    }

    /**
     * @param  Model&Billable  $billable
     */
    public function resumeSubscription(Model $billable, string $subscriptionName = 'default'): void
    {
        $this->cashierSubscription($billable, $subscriptionName)->resume();
    }

    /**
     * Resolve the Cashier subscription for the billable, or fail.
     *
     * @param  Model&Billable  $billable
     */
    protected function cashierSubscription(Model $billable, string $subscriptionName): CashierSubscription
    {
        $subscription = $billable->subscription($subscriptionName);

        if (! $subscription instanceof CashierSubscription) {
            throw ValidationException::withMessages([
                'subscription' => ['No active Stripe subscription found.'],
            ]);
        }

        return $subscription;
    }

    /**
     * Get the success URL for checkout.
     */
    protected function getSuccessUrl(array $options): string
    {
        $baseUrl = config('plan-usage.checkout.success_url');

        if ($baseUrl) {
            return $baseUrl.'?session_id={CHECKOUT_SESSION_ID}';
        }

        if (function_exists('route') && \Route::has('subscription.success')) {
            return route('subscription.success').'?session_id={CHECKOUT_SESSION_ID}';
        }

        return url('/subscription/success?session_id={CHECKOUT_SESSION_ID}');
    }

    /**
     * Get the cancel URL for checkout.
     */
    protected function getCancelUrl(array $options): string
    {
        $baseUrl = config('plan-usage.checkout.cancel_url');

        if ($baseUrl) {
            return $baseUrl;
        }

        if (function_exists('route') && \Route::has('subscription.cancel')) {
            return route('subscription.cancel');
        }

        return url('/subscription/cancel');
    }

    /**
     * Map interval values to Stripe format.
     */
    protected function mapInterval(string $interval): string
    {
        return match ($interval) {
            'day' => 'day',
            'week' => 'week',
            'month' => 'month',
            'year' => 'year',
            default => 'month',
        };
    }
}
