<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Providers\LemonSqueezy;

use Develupers\PlanUsage\Contracts\BillingProvider;
use Develupers\PlanUsage\Contracts\CheckoutSession;
use Illuminate\Database\Eloquent\Model;
use LemonSqueezy\Laravel\Customer;
use LemonSqueezy\Laravel\Events\WebhookHandled;
use LemonSqueezy\Laravel\LemonSqueezy;

/**
 * LemonSqueezy billing provider implementation.
 *
 * This class provides LemonSqueezy-specific billing functionality through the
 * lemonsqueezy/laravel package. LemonSqueezy acts as Merchant of Record,
 * handling all tax/VAT compliance (similar to Paddle).
 */
class LemonSqueezyProvider implements BillingProvider
{
    /**
     * Get the provider name.
     */
    public function name(): string
    {
        return 'lemon-squeezy';
    }

    /**
     * Get the column name for storing the customer ID.
     */
    public function getCustomerIdColumn(): string
    {
        return 'lemon_squeezy_id';
    }

    /**
     * Get the column name for storing the price ID.
     *
     * LemonSqueezy uses "variants" instead of "prices".
     */
    public function getPriceIdColumn(): string
    {
        return 'lemon_squeezy_variant_id';
    }

    /**
     * Get the column name for storing the product ID.
     */
    public function getProductIdColumn(): string
    {
        return 'lemon_squeezy_product_id';
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
     *
     * LemonSqueezy uses variant IDs (equivalent to price IDs in Stripe/Paddle).
     */
    public function createCheckoutSession(Model $billable, string $priceId, array $options = []): CheckoutSession
    {
        $subscriptionName = $options['subscription_name'] ?? 'default';

        // Build checkout via the LemonSqueezy Billable trait
        $checkout = $billable->subscribe($priceId, $subscriptionName);

        // Apply checkout options
        if (isset($options['success_url'])) {
            $checkout = $checkout->withRedirectUrl($options['success_url']);
        }

        return new LemonSqueezyCheckoutSession($checkout);
    }

    /**
     * Sync local plans to LemonSqueezy via API.
     *
     * Creates products and variants in LemonSqueezy and stores the IDs back to the database.
     *
     * Note: LemonSqueezy's API for creating products/variants requires a store ID.
     * Products and variants are typically created via the LemonSqueezy dashboard,
     * then their IDs are stored locally. This method attempts API-based sync.
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

        $apiKey = config('lemon-squeezy.api_key') ?? config('plan-usage.lemon-squeezy.api_key');
        $storeId = config('lemon-squeezy.store') ?? config('plan-usage.lemon-squeezy.store');

        if (! $apiKey) {
            $results['errors'][] = [
                'plan' => 'all',
                'error' => 'LemonSqueezy API key not configured. Set LEMON_SQUEEZY_API_KEY in .env',
            ];

            return $results;
        }

        if (! $storeId) {
            $results['errors'][] = [
                'plan' => 'all',
                'error' => 'LemonSqueezy store ID not configured. Set LEMON_SQUEEZY_STORE in .env',
            ];

            return $results;
        }

        foreach ($plans as $plan) {
            try {
                $hasProduct = ! empty($plan->lemon_squeezy_product_id);

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
                    $productId = $this->createOrUpdateProduct($apiKey, $storeId, $plan, $hasProduct && $force);

                    if ($productId) {
                        $plan->lemon_squeezy_product_id = $productId;
                        $plan->save();

                        $results['created'][] = [
                            'plan' => $plan->slug,
                            'product_id' => $productId,
                        ];
                    }
                } else {
                    $results['updated'][] = [
                        'plan' => $plan->slug,
                        'product_id' => $plan->lemon_squeezy_product_id,
                        'skipped' => 'Product already exists. Use --force to update.',
                    ];
                }

                // Sync variants (prices) for the plan
                foreach ($plan->prices as $planPrice) {
                    $hasVariant = ! empty($planPrice->lemon_squeezy_variant_id);

                    if (! $hasVariant || $force) {
                        $variantId = $this->createOrUpdateVariant(
                            $apiKey,
                            $plan,
                            $planPrice,
                            $hasVariant && $force
                        );

                        if ($variantId) {
                            $planPrice->lemon_squeezy_variant_id = $variantId;
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
     * Create or update a product in LemonSqueezy.
     */
    protected function createOrUpdateProduct(string $apiKey, string $storeId, $plan, bool $update = false): ?string
    {
        $productName = $plan->display_name ?: $plan->name;

        if ($update && $plan->lemon_squeezy_product_id) {
            // Update existing product
            $response = $this->makeApiRequest('PATCH', "/v1/products/{$plan->lemon_squeezy_product_id}", $apiKey, [
                'data' => [
                    'type' => 'products',
                    'id' => $plan->lemon_squeezy_product_id,
                    'attributes' => [
                        'name' => $productName,
                        'description' => $plan->description ?? "Subscription plan: {$plan->name}",
                    ],
                ],
            ]);

            return $response['data']['id'] ?? $plan->lemon_squeezy_product_id;
        }

        // Create new product
        $response = $this->makeApiRequest('POST', '/v1/products', $apiKey, [
            'data' => [
                'type' => 'products',
                'attributes' => [
                    'name' => $productName,
                    'description' => $plan->description ?? "Subscription plan: {$plan->name}",
                ],
                'relationships' => [
                    'store' => [
                        'data' => [
                            'type' => 'stores',
                            'id' => $storeId,
                        ],
                    ],
                ],
            ],
        ]);

        return $response['data']['id'] ?? null;
    }

    /**
     * Create or update a variant (price) in LemonSqueezy.
     */
    protected function createOrUpdateVariant(string $apiKey, $plan, $planPrice, bool $update = false): ?string
    {
        if (! $plan->lemon_squeezy_product_id) {
            return null;
        }

        // Convert price to cents
        $amount = (int) ($planPrice->price * 100);

        if ($update && $planPrice->lemon_squeezy_variant_id) {
            // Update existing variant
            $response = $this->makeApiRequest('PATCH', "/v1/variants/{$planPrice->lemon_squeezy_variant_id}", $apiKey, [
                'data' => [
                    'type' => 'variants',
                    'id' => $planPrice->lemon_squeezy_variant_id,
                    'attributes' => [
                        'name' => ucfirst($planPrice->interval->value).' subscription',
                        'price' => $amount,
                        'is_subscription' => true,
                    ],
                ],
            ]);

            return $response['data']['id'] ?? $planPrice->lemon_squeezy_variant_id;
        }

        // Create new variant
        $response = $this->makeApiRequest('POST', '/v1/variants', $apiKey, [
            'data' => [
                'type' => 'variants',
                'attributes' => [
                    'name' => ucfirst($planPrice->interval->value).' subscription',
                    'price' => $amount,
                    'is_subscription' => true,
                    'interval' => $this->mapInterval($planPrice->interval->value),
                    'interval_count' => 1,
                ],
                'relationships' => [
                    'product' => [
                        'data' => [
                            'type' => 'products',
                            'id' => $plan->lemon_squeezy_product_id,
                        ],
                    ],
                ],
            ],
        ]);

        return $response['data']['id'] ?? null;
    }

    /**
     * Map interval values to LemonSqueezy format.
     */
    protected function mapInterval(string $interval): string
    {
        return match ($interval) {
            'day' => 'day',
            'week' => 'week',
            'month' => 'month',
            'year' => 'year',
            'lifetime' => 'year', // LemonSqueezy doesn't have lifetime, approximate with long period
            default => 'month',
        };
    }

    /**
     * Make an API request to LemonSqueezy.
     *
     * LemonSqueezy uses JSON:API format.
     */
    protected function makeApiRequest(string $method, string $uri, string $apiKey, array $payload = []): array
    {
        $baseUrl = 'https://api.lemonsqueezy.com';

        $ch = curl_init();

        $headers = [
            'Authorization: Bearer '.$apiKey,
            'Content-Type: application/vnd.api+json',
            'Accept: application/vnd.api+json',
        ];

        curl_setopt_array($ch, [
            CURLOPT_URL => $baseUrl.$uri,
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
            throw new \RuntimeException("LemonSqueezy API request failed: {$error}");
        }

        $data = json_decode($response, true);

        if ($httpCode >= 400) {
            $errorMessage = $data['errors'][0]['detail'] ?? $data['errors'][0]['title'] ?? 'Unknown error';
            throw new \RuntimeException("LemonSqueezy API error ({$httpCode}): {$errorMessage}");
        }

        return $data ?? [];
    }

    /**
     * Check if the LemonSqueezy SDK is installed.
     */
    public function isInstalled(): bool
    {
        return class_exists(LemonSqueezy::class);
    }

    /**
     * Find a billable entity by LemonSqueezy customer ID.
     *
     * LemonSqueezy uses a polymorphic Customer model, but we also store
     * lemon_squeezy_id on the billable table for convenience lookups.
     */
    public function findBillableByCustomerId(string $customerId): ?Model
    {
        $billableClass = config('plan-usage.models.billable');

        if (! $billableClass || ! class_exists($billableClass)) {
            return null;
        }

        // First try the convenience column on the billable table
        if (\Schema::hasColumn((new $billableClass)->getTable(), 'lemon_squeezy_id')) {
            $billable = $billableClass::where('lemon_squeezy_id', $customerId)->first();
            if ($billable) {
                return $billable;
            }
        }

        // Fall back to querying through the LemonSqueezy Customer model
        if (class_exists(Customer::class)) {
            $customer = Customer::where('lemon_squeezy_id', $customerId)->first();
            if ($customer && $customer->billable) {
                return $customer->billable;
            }
        }

        return null;
    }
}
