<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Providers\Polar;

use Carbon\CarbonImmutable;
use Danestves\LaravelPolar\Customer;
use Danestves\LaravelPolar\Events\WebhookHandled;
use Danestves\LaravelPolar\LaravelPolar;
use Develupers\PlanUsage\Contracts\Billable;
use Develupers\PlanUsage\Contracts\BillingProvider;
use Develupers\PlanUsage\Contracts\CheckoutSession;
use Develupers\PlanUsage\Contracts\SubscriptionLifecycleProvider;
use Develupers\PlanUsage\Enums\Interval;
use Develupers\PlanUsage\Enums\SubscriptionChangeTiming;
use Develupers\PlanUsage\Models\PlanPrice;
use Develupers\PlanUsage\Support\ProviderSubscriptionChange;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Polar\Models\Components;
use Polar\Polar;

class PolarProvider implements BillingProvider, SubscriptionLifecycleProvider
{
    public function name(): string
    {
        return 'polar';
    }

    public function getCustomerIdColumn(): string
    {
        return 'polar_id';
    }

    public function getPriceIdColumn(): string
    {
        return 'polar_product_id';
    }

    public function getProductIdColumn(): string
    {
        return 'polar_product_id';
    }

    public function getWebhookEventClass(): string
    {
        return WebhookHandled::class;
    }

    /**
     * @param  Model&Billable  $billable
     */
    public function createCheckoutSession(Model $billable, string $priceId, array $options = []): CheckoutSession
    {
        $subscriptionName = (string) ($options['subscription_name'] ?? 'default');
        $checkoutOptions = $options['checkout_options'] ?? [];
        $customerMetadata = $options['customer_metadata'] ?? [];
        $metadata = $options['metadata'] ?? $options['custom_data'] ?? [];

        $checkout = $billable->subscribe(
            $priceId,
            $subscriptionName,
            is_array($checkoutOptions) ? $checkoutOptions : [],
            is_array($customerMetadata) ? $customerMetadata : [],
            is_array($metadata) ? $metadata : [],
        );

        if (isset($options['success_url'])) {
            $checkout->withSuccessUrl((string) $options['success_url']);
        }

        if (isset($options['cancel_url'])) {
            $checkout->withReturnUrl((string) $options['cancel_url']);
        }

        if (isset($options['embed_origin'])) {
            $checkout->withEmbedOrigin((string) $options['embed_origin']);
        }

        if (($options['allow_promotion_codes'] ?? true) === false) {
            $checkout->withoutDiscountCodes();
        }

        return new PolarCheckoutSession($checkout);
    }

    public function syncProducts(iterable $plans, array $options = []): array
    {
        $results = [
            'created' => [],
            'updated' => [],
            'errors' => [],
        ];

        $dryRun = (bool) ($options['dry_run'] ?? false);
        $force = (bool) ($options['force'] ?? false);

        if (! $dryRun && $this->accessToken() === null) {
            $results['errors'][] = [
                'plan' => 'all',
                'error' => 'Polar access token not configured. Set POLAR_ACCESS_TOKEN in .env.',
            ];

            return $results;
        }

        foreach ($plans as $plan) {
            foreach ($plan->prices as $planPrice) {
                try {
                    $hasProduct = filled($planPrice->polar_product_id);

                    if ($dryRun) {
                        $results[$hasProduct ? 'updated' : 'created'][] = [
                            'plan' => $plan->slug,
                            'plan_price_id' => $planPrice->id,
                            'interval' => $planPrice->interval->value,
                            'action' => $hasProduct ? ($force ? 'update' : 'skip') : 'create',
                            'dry_run' => true,
                        ];

                        continue;
                    }

                    if ($hasProduct) {
                        if ($force) {
                            $this->updateProduct($planPrice->polar_product_id, $plan, $planPrice);
                        }

                        $results['updated'][] = [
                            'plan' => $plan->slug,
                            'plan_price_id' => $planPrice->id,
                            'interval' => $planPrice->interval->value,
                            'product_id' => $planPrice->polar_product_id,
                            'skipped' => $force ? null : 'Product already exists. Use --force to update.',
                        ];

                        continue;
                    }

                    $productId = $this->createProduct($plan, $planPrice);
                    $planPrice->polar_product_id = $productId;
                    $planPrice->save();

                    $results['created'][] = [
                        'plan' => $plan->slug,
                        'plan_price_id' => $planPrice->id,
                        'interval' => $planPrice->interval->value,
                        'product_id' => $productId,
                    ];
                } catch (\Throwable $exception) {
                    $results['errors'][] = [
                        'plan' => $plan->slug,
                        'plan_price_id' => $planPrice->id,
                        'error' => $exception->getMessage(),
                    ];
                }
            }
        }

        return $results;
    }

    public function isInstalled(): bool
    {
        return class_exists(LaravelPolar::class);
    }

    public function findBillableByCustomerId(string $customerId): ?Model
    {
        if (! class_exists(Customer::class)) {
            return null;
        }

        $customer = Customer::query()->where('polar_id', $customerId)->first();
        $billable = $customer?->billable;

        return $billable instanceof Model ? $billable : null;
    }

    public function fetchSubscription(string $subscriptionId): ?Components\Subscription
    {
        $response = $this->sdk()->subscriptions->get($subscriptionId);

        return $response->subscription;
    }

    /**
     * @return Collection<string, Components\Subscription>
     */
    public function fetchSubscriptions(): Collection
    {
        $subscriptions = collect();

        foreach ($this->sdk()->subscriptions->list() as $response) {
            foreach ($response->listResourceSubscription->items ?? [] as $subscription) {
                $subscriptions->put($subscription->id, $subscription);
            }
        }

        return $subscriptions;
    }

    public function supportsTiming(SubscriptionChangeTiming $timing): bool
    {
        // Polar natively supports both immediate swaps and pending updates
        // applied at the next renewal.
        return true;
    }

    /**
     * @param  Model&Billable  $billable
     */
    public function changeSubscription(
        Model $billable,
        string $productId,
        SubscriptionChangeTiming $timing,
        string $subscriptionName = 'default'
    ): ProviderSubscriptionChange {
        $subscription = $this->subscription($billable, $subscriptionName);
        $behavior = $this->subscriptionProrationBehavior($timing);

        $remoteSubscription = $this->updateSubscriptionProduct(
            $subscription->polar_id,
            $productId,
            $behavior
        );

        $subscription->sync($this->subscriptionSyncAttributes($remoteSubscription));

        return new ProviderSubscriptionChange(
            providerSubscriptionId: $remoteSubscription->id,
            currentProductId: $remoteSubscription->productId,
            pendingProductId: $remoteSubscription->pendingUpdate?->productId,
            periodStart: CarbonImmutable::instance($remoteSubscription->currentPeriodStart),
            periodEnd: CarbonImmutable::instance($remoteSubscription->currentPeriodEnd),
            effectiveAt: $remoteSubscription->pendingUpdate === null
                ? null
                : CarbonImmutable::instance($remoteSubscription->pendingUpdate->appliesAt),
            providerChangeId: $remoteSubscription->pendingUpdate?->id,
        );
    }

    /**
     * @param  Model&Billable  $billable
     */
    public function cancelPendingSubscriptionChange(Model $billable, string $subscriptionName = 'default'): void
    {
        $subscription = $this->subscription($billable, $subscriptionName);

        $this->polarApiRequest()
            ->patch("/v1/subscriptions/{$subscription->polar_id}", [
                'pending_update' => null,
            ])
            ->throw();
    }

    /**
     * @param  Model&Billable  $billable
     */
    public function cancelSubscription(
        Model $billable,
        bool $immediately = false,
        string $subscriptionName = 'default'
    ): void {
        $subscription = $this->subscription($billable, $subscriptionName);

        if (! $immediately) {
            $subscription->cancel();

            return;
        }

        $response = $this->sdk()->subscriptions->revoke($subscription->polar_id);

        if ($response->subscription === null) {
            throw new \RuntimeException('Polar did not return the revoked subscription.');
        }

        $subscription->sync($this->subscriptionSyncAttributes($response->subscription));
    }

    /**
     * @param  Model&Billable  $billable
     */
    public function resumeSubscription(Model $billable, string $subscriptionName = 'default'): void
    {
        $this->subscription($billable, $subscriptionName)->resume();
    }

    protected function sdk(): Polar
    {
        $this->configurePolarPackage();

        return LaravelPolar::sdk();
    }

    protected function updateSubscriptionProduct(
        string $subscriptionId,
        string $productId,
        Components\SubscriptionProrationBehavior $behavior
    ): Components\Subscription {
        $this->configurePolarPackage();

        return LaravelPolar::updateSubscription(
            subscriptionId: $subscriptionId,
            request: new Components\SubscriptionUpdateProduct(
                productId: $productId,
                prorationBehavior: $behavior,
            ),
        );
    }

    protected function createProduct(Model $plan, PlanPrice $planPrice): string
    {
        $response = $this->sdk()->products->create($this->productCreateRequest($plan, $planPrice));

        if ($response->product === null) {
            throw new \RuntimeException('Polar did not return the created product.');
        }

        return $response->product->id;
    }

    protected function updateProduct(string $productId, Model $plan, PlanPrice $planPrice): void
    {
        $response = $this->sdk()->products->update(
            productUpdate: $this->productUpdateRequest($plan, $planPrice),
            id: $productId,
        );

        if ($response->product === null) {
            throw new \RuntimeException('Polar did not return the updated product.');
        }
    }

    protected function productCreateRequest(
        Model $plan,
        PlanPrice $planPrice
    ): Components\ProductCreateOneTime|Components\ProductCreateRecurring {
        $attributes = [
            'name' => $this->productName($plan, $planPrice),
            'prices' => [$this->productPrice($planPrice)],
            'metadata' => $this->productMetadata($plan, $planPrice),
            'description' => $plan->description,
            'organizationId' => config('polar.organization_id')
                ?? config('plan-usage.polar.organization_id'),
        ];

        if ($planPrice->interval === Interval::LIFETIME) {
            return new Components\ProductCreateOneTime(...$attributes);
        }

        return new Components\ProductCreateRecurring(
            ...$attributes,
            recurringInterval: $this->recurringInterval($planPrice->interval),
            trialInterval: $plan->trial_days > 0 ? Components\TrialInterval::Day : null,
            trialIntervalCount: $plan->trial_days > 0 ? $plan->trial_days : null,
        );
    }

    protected function productUpdateRequest(Model $plan, PlanPrice $planPrice): Components\ProductUpdate
    {
        $hasTrial = $planPrice->interval !== Interval::LIFETIME && $plan->trial_days > 0;

        return new Components\ProductUpdate(
            name: $this->productName($plan, $planPrice),
            description: $plan->description,
            metadata: $this->productMetadata($plan, $planPrice),
            prices: [$this->productPrice($planPrice)],
            trialInterval: $hasTrial ? Components\TrialInterval::Day : null,
            trialIntervalCount: $hasTrial ? $plan->trial_days : null,
        );
    }

    protected function productPrice(
        PlanPrice $planPrice
    ): Components\ProductPriceFixedCreate|Components\ProductPriceFreeCreate {
        $currency = Components\PresentmentCurrency::from(strtolower($planPrice->currency));

        if ($planPrice->price <= 0) {
            return new Components\ProductPriceFreeCreate(priceCurrency: $currency);
        }

        return new Components\ProductPriceFixedCreate(
            priceAmount: (int) round($planPrice->price * 100),
            priceCurrency: $currency,
        );
    }

    /**
     * @return array<string, string>
     */
    protected function productMetadata(Model $plan, PlanPrice $planPrice): array
    {
        return [
            'plan_id' => (string) $plan->getKey(),
            'plan_slug' => $plan->slug,
            'plan_price_id' => (string) $planPrice->getKey(),
            'interval' => $planPrice->interval->value,
        ];
    }

    protected function polarApiRequest(): PendingRequest
    {
        $token = $this->accessToken();

        if ($token === null) {
            throw new \RuntimeException('Polar access token not configured.');
        }

        return Http::baseUrl($this->apiBaseUrl())
            ->acceptJson()
            ->asJson()
            ->withToken($token)
            ->connectTimeout((int) config('plan-usage.polar.http_connect_timeout', 3))
            ->timeout((int) config('plan-usage.polar.http_timeout', 10))
            ->retry([100, 500, 1000]);
    }

    protected function apiBaseUrl(): string
    {
        return (config('polar.server') ?? config('plan-usage.polar.server', 'sandbox')) === 'production'
            ? 'https://api.polar.sh'
            : 'https://sandbox-api.polar.sh';
    }

    protected function configurePolarPackage(): void
    {
        if (config('polar.access_token') === null) {
            config()->set('polar.access_token', config('plan-usage.polar.access_token'));
        }

        if (config('polar.organization_id') === null) {
            config()->set('polar.organization_id', config('plan-usage.polar.organization_id'));
        }

        if (config('polar.server') === null) {
            config()->set('polar.server', config('plan-usage.polar.server', 'sandbox'));
        }
    }

    protected function accessToken(): ?string
    {
        $token = config('polar.access_token') ?? config('plan-usage.polar.access_token');

        return is_string($token) && $token !== '' ? $token : null;
    }

    protected function productName(Model $plan, PlanPrice $planPrice): string
    {
        $name = $plan->display_name ?: $plan->name;

        return $name.' - '.$planPrice->getIntervalDescription();
    }

    protected function recurringInterval(Interval $interval): Components\SubscriptionRecurringInterval
    {
        return match ($interval) {
            Interval::DAY => Components\SubscriptionRecurringInterval::Day,
            Interval::WEEK => Components\SubscriptionRecurringInterval::Week,
            Interval::MONTH => Components\SubscriptionRecurringInterval::Month,
            Interval::YEAR => Components\SubscriptionRecurringInterval::Year,
            Interval::LIFETIME => throw new \LogicException('Lifetime prices are not recurring.'),
        };
    }

    protected function subscriptionProrationBehavior(
        SubscriptionChangeTiming $timing
    ): Components\SubscriptionProrationBehavior {
        return match ($timing) {
            SubscriptionChangeTiming::Immediate => Components\SubscriptionProrationBehavior::Invoice,
            SubscriptionChangeTiming::NextPeriod => Components\SubscriptionProrationBehavior::NextPeriod,
        };
    }

    /**
     * @param  Model&Billable  $billable
     */
    protected function subscription(Model $billable, string $subscriptionName): object
    {
        $subscription = $billable->subscription($subscriptionName);

        if ($subscription === null) {
            throw ValidationException::withMessages([
                'subscription' => ['No active Polar subscription found.'],
            ]);
        }

        return $subscription;
    }

    /**
     * @return array<string, mixed>
     */
    protected function subscriptionSyncAttributes(Components\Subscription $subscription): array
    {
        return [
            'status' => $subscription->status,
            'product_id' => $subscription->productId,
            'current_period_end' => $subscription->currentPeriodEnd,
            'trial_end' => $subscription->trialEnd,
            'ends_at' => $subscription->endsAt ?? $subscription->endedAt,
        ];
    }
}
