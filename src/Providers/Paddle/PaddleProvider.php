<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Providers\Paddle;

use Carbon\CarbonImmutable;
use Develupers\PlanUsage\Contracts\Billable;
use Develupers\PlanUsage\Contracts\BillingProvider;
use Develupers\PlanUsage\Contracts\CheckoutSession;
use Develupers\PlanUsage\Contracts\SubscriptionLifecycleProvider;
use Develupers\PlanUsage\Enums\SubscriptionChangeTiming;
use Develupers\PlanUsage\Support\ProviderSubscriptionChange;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Laravel\Paddle\Cashier;
use Laravel\Paddle\Events\WebhookHandled;
use Laravel\Paddle\Exceptions\PaddleException;
use Laravel\Paddle\Subscription as PaddleSubscription;

/**
 * Paddle billing provider implementation.
 *
 * This class provides Paddle-specific billing functionality through Laravel Cashier Paddle.
 * Paddle acts as Merchant of Record, handling all tax/VAT compliance.
 */
class PaddleProvider implements BillingProvider, SubscriptionLifecycleProvider
{
    /**
     * Get the provider name.
     */
    public function name(): string
    {
        return 'paddle';
    }

    /**
     * Get the column name for storing the customer ID.
     */
    public function getCustomerIdColumn(): string
    {
        return 'paddle_id';
    }

    /**
     * Get the column name for storing the price ID.
     */
    public function getPriceIdColumn(): string
    {
        return 'paddle_price_id';
    }

    /**
     * Get the column name for storing the product ID.
     */
    public function getProductIdColumn(): string
    {
        return 'paddle_product_id';
    }

    /**
     * Get the webhook event class to listen for.
     */
    public function getWebhookEventClass(): string
    {
        // WebhookHandled fires after Cashier Paddle has processed the webhook
        // and created/updated the local subscription row — required for the
        // listener's identity validation.
        return WebhookHandled::class;
    }

    /**
     * Create a checkout session for subscription.
     */
    public function createCheckoutSession(Model $billable, string $priceId, array $options = []): CheckoutSession
    {
        $subscriptionName = $options['subscription_name'] ?? 'default';

        // Cashier Paddle has no Stripe-style SubscriptionBuilder flow: its
        // checkout() ensures the Paddle customer exists and returns a Checkout
        // object consumed by Paddle.js (overlay/inline). The subscription_type
        // entry in customData is what makes Cashier Paddle create a local
        // subscription of that type when the webhook arrives — this mirrors
        // $billable->subscribe(), while still allowing caller custom data.
        $checkout = $billable->checkout($priceId)
            ->customData(array_merge(
                $options['custom_data'] ?? [],
                ['subscription_type' => $subscriptionName],
            ));

        if (isset($options['success_url'])) {
            $checkout->returnTo($options['success_url']);
        }

        return new PaddleCheckoutSession($checkout);
    }

    /**
     * Propagate a billing-email change to the billable's existing Paddle customer.
     *
     * Cashier Paddle's createAsCustomer() only runs once per billable; after
     * that, changing the email the app derives via paddleEmail() has no effect
     * on Paddle (or the local customers row) unless it is pushed explicitly.
     * Paddle also fires customer.updated after the PATCH, but the local row is
     * updated here too so the change is visible without webhook latency.
     *
     * Returns true when an update was pushed, false when there was nothing to
     * do (no Paddle customer yet, or the email is unchanged).
     *
     * @throws PaddleException when Paddle rejects the email (e.g. it already belongs to another customer)
     */
    public function updateCustomerEmail(Model $billable, string $email): bool
    {
        $customer = $billable->customer;

        if ($customer === null || $customer->email === $email) {
            return false;
        }

        Cashier::api('PATCH', "customers/{$customer->paddle_id}", [
            'email' => $email,
        ]);

        $customer->email = $email;
        $customer->save();

        return true;
    }

    /**
     * Sync local plans to Paddle via API.
     *
     * Creates products and prices in Paddle and stores the IDs back to the database.
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

        // Get Paddle API credentials
        $apiKey = config('cashier.api_key') ?? config('plan-usage.paddle.api_key');

        if (! $apiKey) {
            $results['errors'][] = [
                'plan' => 'all',
                'error' => 'Paddle API key not configured. Set PADDLE_API_KEY in .env',
            ];

            return $results;
        }

        $baseUrl = $this->isSandbox()
            ? 'https://sandbox-api.paddle.com'
            : 'https://api.paddle.com';

        foreach ($plans as $plan) {
            try {
                $hasProduct = ! empty($plan->paddle_product_id);

                if ($dryRun) {
                    $results['created'][] = [
                        'plan' => $plan->slug,
                        'action' => $hasProduct ? 'update' : 'create',
                        'dry_run' => true,
                    ];

                    continue;
                }

                // Create or update product
                if (! $hasProduct || $force) {
                    $productId = $this->createOrUpdateProduct($baseUrl, $apiKey, $plan, $hasProduct && $force);

                    if ($productId) {
                        $plan->paddle_product_id = $productId;
                        $plan->save();

                        $results['created'][] = [
                            'plan' => $plan->slug,
                            'product_id' => $productId,
                        ];
                    }
                } else {
                    $results['updated'][] = [
                        'plan' => $plan->slug,
                        'product_id' => $plan->paddle_product_id,
                        'skipped' => 'Product already exists. Use --force to update.',
                    ];
                }

                // Sync prices for the plan
                foreach ($plan->prices as $planPrice) {
                    $hasPrice = ! empty($planPrice->paddle_price_id);

                    if (! $hasPrice || $force) {
                        $priceId = $this->createOrUpdatePrice(
                            $baseUrl,
                            $apiKey,
                            $plan,
                            $planPrice,
                            $hasPrice && $force
                        );

                        if ($priceId) {
                            $planPrice->paddle_price_id = $priceId;
                            $planPrice->save();
                        }
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
     * Create or update a product in Paddle.
     *
     * Checks for existing products by custom_data.plan_slug to prevent duplicates.
     */
    protected function createOrUpdateProduct(string $baseUrl, string $apiKey, $plan, bool $update = false): ?string
    {
        $productName = $plan->display_name ?: $plan->name;

        // Check for existing product by plan_slug in custom_data to prevent duplicates
        if (! $update) {
            $existingProductId = $this->findExistingProduct($baseUrl, $apiKey, $plan->slug);
            if ($existingProductId) {
                return $existingProductId;
            }
        }

        $endpoint = $update && $plan->paddle_product_id
            ? "{$baseUrl}/products/{$plan->paddle_product_id}"
            : "{$baseUrl}/products";

        $method = $update && $plan->paddle_product_id ? 'PATCH' : 'POST';

        $payload = [
            'name' => $productName,
            'tax_category' => 'standard',
            'description' => $plan->description ?? "Subscription plan: {$plan->name}",
            'custom_data' => [
                'plan_slug' => $plan->slug,
                'plan_id' => (string) $plan->id,
            ],
        ];

        $response = $this->makeApiRequest($method, $endpoint, $apiKey, $payload);

        return $response['data']['id'] ?? null;
    }

    /**
     * Find an existing Paddle product by plan slug in custom_data.
     */
    protected function findExistingProduct(string $baseUrl, string $apiKey, string $planSlug): ?string
    {
        try {
            // List all products and find one with matching plan_slug
            $response = $this->makeApiRequest('GET', "{$baseUrl}/products?status=active", $apiKey);

            foreach ($response['data'] ?? [] as $product) {
                $customData = $product['custom_data'] ?? [];
                if (isset($customData['plan_slug']) && $customData['plan_slug'] === $planSlug) {
                    return $product['id'];
                }
            }
        } catch (\Exception $e) {
            // If listing fails, proceed with creation
        }

        return null;
    }

    /**
     * Create or update a price in Paddle.
     *
     * Checks for existing prices by custom_data to prevent duplicates.
     */
    protected function createOrUpdatePrice(string $baseUrl, string $apiKey, $plan, $planPrice, bool $update = false): ?string
    {
        if (! $plan->paddle_product_id) {
            return null;
        }

        // Check for existing price to prevent duplicates
        if (! $update) {
            $existingPriceId = $this->findExistingPrice(
                $baseUrl,
                $apiKey,
                $plan->paddle_product_id,
                $planPrice->interval->value
            );
            if ($existingPriceId) {
                return $existingPriceId;
            }
        }

        $endpoint = $update && $planPrice->paddle_price_id
            ? "{$baseUrl}/prices/{$planPrice->paddle_price_id}"
            : "{$baseUrl}/prices";

        $method = $update && $planPrice->paddle_price_id ? 'PATCH' : 'POST';

        // Convert price to smallest currency unit (cents)
        $amount = (string) (int) ($planPrice->price * 100);

        $payload = [
            'product_id' => $plan->paddle_product_id,
            'description' => ucfirst($planPrice->interval->value).' subscription',
            'unit_price' => [
                'amount' => $amount,
                'currency_code' => strtoupper($planPrice->currency ?? 'USD'),
            ],
            'billing_cycle' => $this->getBillingCycle($planPrice->interval->value),
            'custom_data' => [
                'plan_price_id' => (string) $planPrice->id,
                'interval' => $planPrice->interval->value,
            ],
        ];

        // Only include product_id for new prices
        if ($update && $planPrice->paddle_price_id) {
            unset($payload['product_id']);
        }

        $response = $this->makeApiRequest($method, $endpoint, $apiKey, $payload);

        return $response['data']['id'] ?? null;
    }

    /**
     * Find an existing Paddle price by product ID and interval.
     */
    protected function findExistingPrice(string $baseUrl, string $apiKey, string $productId, string $interval): ?string
    {
        try {
            // List prices for this product
            $response = $this->makeApiRequest('GET', "{$baseUrl}/prices?product_id={$productId}&status=active", $apiKey);

            foreach ($response['data'] ?? [] as $price) {
                $customData = $price['custom_data'] ?? [];
                if (isset($customData['interval']) && $customData['interval'] === $interval) {
                    return $price['id'];
                }
            }
        } catch (\Exception $e) {
            // If listing fails, proceed with creation
        }

        return null;
    }

    /**
     * Convert interval to Paddle billing cycle format.
     */
    protected function getBillingCycle(string $interval): array
    {
        return match ($interval) {
            'day' => ['interval' => 'day', 'frequency' => 1],
            'week' => ['interval' => 'week', 'frequency' => 1],
            'month' => ['interval' => 'month', 'frequency' => 1],
            'year' => ['interval' => 'year', 'frequency' => 1],
            'lifetime' => ['interval' => 'year', 'frequency' => 99], // Paddle doesn't have lifetime, use long period
            default => ['interval' => 'month', 'frequency' => 1],
        };
    }

    /**
     * Make an API request to Paddle.
     */
    protected function makeApiRequest(string $method, string $url, string $apiKey, array $payload = []): array
    {
        $ch = curl_init();

        $headers = [
            'Authorization: Bearer '.$apiKey,
            'Content-Type: application/json',
        ];

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method,
        ]);

        if (in_array($method, ['POST', 'PATCH', 'PUT'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        if ($error) {
            throw new \RuntimeException("Paddle API request failed: {$error}");
        }

        $data = json_decode($response, true);

        if ($httpCode >= 400) {
            $errorMessage = $data['error']['detail'] ?? $data['error']['message'] ?? 'Unknown error';
            throw new \RuntimeException("Paddle API error ({$httpCode}): {$errorMessage}");
        }

        return $data ?? [];
    }

    /**
     * Check if the Paddle SDK is installed.
     */
    public function isInstalled(): bool
    {
        return class_exists(Cashier::class);
    }

    /**
     * Find a billable entity by Paddle customer ID.
     */
    public function findBillableByCustomerId(string $customerId): ?Model
    {
        $billableClass = config('plan-usage.models.billable') ?? config('cashier.model');

        if (! $billableClass || ! class_exists($billableClass)) {
            return null;
        }

        // Paddle stores customer ID differently - check for paddle_id column
        if (\Schema::hasColumn((new $billableClass)->getTable(), 'paddle_id')) {
            return $billableClass::where('paddle_id', $customerId)->first();
        }

        return null;
    }

    public function supportsTiming(SubscriptionChangeTiming $timing): bool
    {
        return $timing === SubscriptionChangeTiming::Immediate;
    }

    /**
     * Change the subscription to a different price.
     *
     * Paddle applies plan swaps immediately (proration invoiced now).
     * Paddle Billing has no native scheduled plan change, so next-period
     * timing is not implemented here.
     *
     * @param  Model&Billable  $billable
     * @param  string  $productId  The target Paddle price ID
     */
    public function changeSubscription(
        Model $billable,
        string $productId,
        SubscriptionChangeTiming $timing,
        string $subscriptionName = 'default'
    ): ProviderSubscriptionChange {
        if ($timing !== SubscriptionChangeTiming::Immediate) {
            throw ValidationException::withMessages([
                'subscription' => ['Paddle supports immediate plan changes only. Scheduled changes are not available for this provider.'],
            ]);
        }

        $subscription = $this->cashierSubscription($billable, $subscriptionName);

        // Cashier Paddle defaults swaps to next-period billing; swapAndInvoice()
        // switches to an immediate prorated charge so the change settles now.
        $subscription->swapAndInvoice($productId);

        $period = $this->currentBillingPeriod($subscription);

        return new ProviderSubscriptionChange(
            providerSubscriptionId: (string) $subscription->paddle_id,
            // The swap is synchronous: if it did not throw, the new price is active.
            currentProductId: $productId,
            pendingProductId: null,
            periodStart: $period['start'],
            periodEnd: $period['end'],
        );
    }

    /**
     * Paddle has no scheduled plan changes, so there is nothing to cancel.
     *
     * @param  Model&Billable  $billable
     */
    public function cancelPendingSubscriptionChange(Model $billable, string $subscriptionName = 'default'): void
    {
        throw ValidationException::withMessages([
            'subscription' => ['Paddle does not support scheduled plan changes, so there is no pending change to cancel.'],
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
        // Paddle un-cancels a scheduled cancellation via stopCancelation();
        // resume() is reserved for paused subscriptions.
        $this->cashierSubscription($billable, $subscriptionName)->stopCancelation();
    }

    /**
     * Resolve the current billing period from the Paddle API.
     *
     * Cashier Paddle does not store the period locally, so it is fetched.
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable}
     */
    protected function currentBillingPeriod(PaddleSubscription $subscription): array
    {
        $remote = $subscription->asPaddleSubscription();
        $period = is_array($remote['current_billing_period'] ?? null)
            ? $remote['current_billing_period']
            : [];

        $start = isset($period['starts_at'])
            ? CarbonImmutable::parse($period['starts_at'])
            : CarbonImmutable::now();

        $end = isset($period['ends_at'])
            ? CarbonImmutable::parse($period['ends_at'])
            : (isset($remote['next_billed_at'])
                ? CarbonImmutable::parse($remote['next_billed_at'])
                : CarbonImmutable::now()->addMonth());

        return ['start' => $start, 'end' => $end];
    }

    /**
     * Resolve the Cashier subscription for the billable, or fail.
     *
     * @param  Model&Billable  $billable
     */
    protected function cashierSubscription(Model $billable, string $subscriptionName): PaddleSubscription
    {
        $subscription = $billable->subscription($subscriptionName);

        if (! $subscription instanceof PaddleSubscription) {
            throw ValidationException::withMessages([
                'subscription' => ['No active Paddle subscription found.'],
            ]);
        }

        return $subscription;
    }

    /**
     * Get the Paddle environment (sandbox or production).
     */
    public function isSandbox(): bool
    {
        return config('plan-usage.paddle.sandbox', true)
            || config('cashier.sandbox', true);
    }

    /**
     * Get the Paddle seller ID.
     */
    public function getSellerId(): ?string
    {
        return config('plan-usage.paddle.seller_id')
            ?? config('cashier.seller_id');
    }
}
