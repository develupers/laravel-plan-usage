<?php

declare(strict_types=1);

use Danestves\LaravelPolar\Billable as PolarBillable;
use Danestves\LaravelPolar\Checkout;
use Danestves\LaravelPolar\Customer;
use Develupers\PlanUsage\Contracts\Billable;
use Develupers\PlanUsage\Contracts\BillingProvider;
use Develupers\PlanUsage\Enums\Interval;
use Develupers\PlanUsage\Enums\SubscriptionChangeTiming;
use Develupers\PlanUsage\Models\Plan;
use Develupers\PlanUsage\Models\PlanPrice;
use Develupers\PlanUsage\Providers\Polar\PolarCheckoutSession;
use Develupers\PlanUsage\Providers\Polar\PolarProvider;
use Develupers\PlanUsage\Traits\HasPlanFeatures;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Polar\Models\Components;
use Polar\Models\Components\SubscriptionProrationBehavior;

class TestablePolarProvider extends PolarProvider
{
    public function behavior(SubscriptionChangeTiming $timing): SubscriptionProrationBehavior
    {
        return $this->subscriptionProrationBehavior($timing);
    }

    public function createRequest(
        Plan $plan,
        PlanPrice $planPrice
    ): Components\ProductCreateOneTime|Components\ProductCreateRecurring {
        return $this->productCreateRequest($plan, $planPrice);
    }

    public function updateRequest(Plan $plan, PlanPrice $planPrice): Components\ProductUpdate
    {
        return $this->productUpdateRequest($plan, $planPrice);
    }
}

class PolarProviderTestBillable extends Model implements Billable
{
    use HasPlanFeatures;
    use PolarBillable;

    public $timestamps = false;

    protected $table = 'test_billables';

    protected $guarded = [];

    public function polarName(): ?string
    {
        return 'Polar Tester';
    }

    public function polarEmail(): ?string
    {
        return 'polar@example.com';
    }
}

beforeEach(function () {
    config()->set('plan-usage.billing.provider', 'polar');
    config()->set('plan-usage.models.billable', PolarProviderTestBillable::class);
    $this->provider = new PolarProvider;
});

it('implements the provider contract with Polar identifiers', function () {
    expect($this->provider)->toBeInstanceOf(BillingProvider::class)
        ->and($this->provider->name())->toBe('polar')
        ->and($this->provider->getCustomerIdColumn())->toBe('polar_id')
        ->and($this->provider->getPriceIdColumn())->toBe('polar_product_id')
        ->and($this->provider->getProductIdColumn())->toBe('polar_product_id')
        ->and($this->provider->isInstalled())->toBeTrue();
});

it('resolves Polar from the package billing provider binding', function () {
    app()->forgetInstance(BillingProvider::class);

    expect(app(BillingProvider::class))->toBeInstanceOf(PolarProvider::class);
});

it('creates one Polar checkout product for the selected plan price', function () {
    $billable = PolarProviderTestBillable::query()->create();

    $session = $this->provider->createCheckoutSession($billable, 'prod_monthly', [
        'subscription_name' => 'primary',
        'success_url' => 'https://example.com/billing/success',
        'cancel_url' => 'https://example.com/billing',
        'metadata' => ['plan_price_id' => 10],
        'customer_metadata' => ['account_kind' => 'team'],
        'allow_promotion_codes' => false,
    ]);

    $checkout = $session->getProviderCheckout();
    $products = new ReflectionProperty($checkout, 'products');
    $successUrl = new ReflectionProperty($checkout, 'successUrl');
    $returnUrl = new ReflectionProperty($checkout, 'returnUrl');
    $metadata = new ReflectionProperty($checkout, 'metadata');
    $customerMetadata = new ReflectionProperty($checkout, 'customerMetadata');
    $allowDiscountCodes = new ReflectionProperty($checkout, 'allowDiscountCodes');

    expect($session)->toBeInstanceOf(PolarCheckoutSession::class)
        ->and($checkout)->toBeInstanceOf(Checkout::class)
        ->and($products->getValue($checkout))->toBe(['prod_monthly'])
        ->and($successUrl->getValue($checkout))->toBe('https://example.com/billing/success')
        ->and($returnUrl->getValue($checkout))->toBe('https://example.com/billing')
        ->and($metadata->getValue($checkout))->toBe(['plan_price_id' => 10])
        ->and($customerMetadata->getValue($checkout))->toMatchArray([
            'billable_id' => (string) $billable->getKey(),
            'billable_type' => PolarProviderTestBillable::class,
            'subscription_type' => 'primary',
            'account_kind' => 'team',
        ])
        ->and($allowDiscountCodes->getValue($checkout))->toBeFalse();
});

it('resolves billables through the canonical Polar customer table', function () {
    $billable = PolarProviderTestBillable::query()->create();
    Customer::query()->create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'polar_id' => 'customer_polar_123',
    ]);

    $resolved = $this->provider->findBillableByCustomerId('customer_polar_123');

    expect($resolved)->toBeInstanceOf(PolarProviderTestBillable::class)
        ->and($resolved?->getKey())->toBe($billable->getKey());
});

it('dry runs one Polar product per plan price', function () {
    $plan = Plan::factory()->create(['slug' => 'growth']);
    $plan->prices()->delete();
    PlanPrice::factory()->for($plan)->create([
        'interval' => Interval::MONTH,
        'polar_product_id' => null,
    ]);
    PlanPrice::factory()->for($plan)->create([
        'interval' => Interval::YEAR,
        'polar_product_id' => 'prod_yearly',
    ]);

    $result = $this->provider->syncProducts([$plan->load('prices')], ['dry_run' => true]);

    expect($result['created'])->toHaveCount(1)
        ->and($result['updated'])->toHaveCount(1)
        ->and($result['errors'])->toBeEmpty()
        ->and($result['created'][0]['interval'])->toBe('month')
        ->and($result['updated'][0]['interval'])->toBe('year');
});

it('maps local price and trial changes into Polar product requests', function () {
    $plan = Plan::factory()->create([
        'name' => 'Growth',
        'trial_days' => 14,
    ]);
    $planPrice = $plan->defaultPrice()->firstOrFail();
    $planPrice->update([
        'price' => 49.95,
        'currency' => 'USD',
        'interval' => Interval::MONTH,
    ]);
    $provider = new TestablePolarProvider;

    $createRequest = $provider->createRequest($plan, $planPrice);
    $updateRequest = $provider->updateRequest($plan, $planPrice);

    expect($createRequest)->toBeInstanceOf(Components\ProductCreateRecurring::class)
        ->and($createRequest->recurringInterval)->toBe(Components\SubscriptionRecurringInterval::Month)
        ->and($createRequest->trialInterval)->toBe(Components\TrialInterval::Day)
        ->and($createRequest->trialIntervalCount)->toBe(14)
        ->and($createRequest->prices[0])->toBeInstanceOf(Components\ProductPriceFixedCreate::class)
        ->and($createRequest->prices[0]->priceAmount)->toBe(4995)
        ->and($updateRequest->prices[0])->toBeInstanceOf(Components\ProductPriceFixedCreate::class)
        ->and($updateRequest->prices[0]->priceAmount)->toBe(4995)
        ->and($updateRequest->trialIntervalCount)->toBe(14);
});

it('finds plan prices by Polar product id', function () {
    $planPrice = PlanPrice::factory()->create([
        'polar_product_id' => 'prod_lookup',
    ]);

    expect(PlanPrice::findByProviderPriceId('prod_lookup')?->is($planPrice))->toBeTrue()
        ->and($planPrice->getProviderPriceId())->toBe('prod_lookup');

    $planPrice->setProviderPriceId('prod_updated');

    expect($planPrice->polar_product_id)->toBe('prod_updated');
});

it('maps immediate upgrades and scheduled downgrades to Polar proration behaviors', function () {
    $provider = new TestablePolarProvider;

    expect($provider->behavior(SubscriptionChangeTiming::Immediate))
        ->toBe(SubscriptionProrationBehavior::Invoice)
        ->and($provider->behavior(SubscriptionChangeTiming::NextPeriod))
        ->toBe(SubscriptionProrationBehavior::NextPeriod);
});

it('clears Polar pending updates through the current API', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://sandbox-api.polar.sh/v1/subscriptions/sub_pending_123' => Http::response([
            'id' => 'sub_pending_123',
        ]),
    ]);
    config()->set('polar.access_token', 'polar_test_token');
    config()->set('polar.server', 'sandbox');
    $billable = PolarProviderTestBillable::query()->create();
    $billable->subscriptions()->create([
        'type' => 'default',
        'polar_id' => 'sub_pending_123',
        'status' => 'active',
        'product_id' => 'prod_current',
    ]);

    $this->provider->cancelPendingSubscriptionChange($billable);

    Http::assertSent(fn ($request) => $request->method() === 'PATCH'
        && $request->url() === 'https://sandbox-api.polar.sh/v1/subscriptions/sub_pending_123'
        && array_key_exists('pending_update', $request->data())
        && $request['pending_update'] === null);
});

it('reports support for both immediate and next-period plan change timings', function () {
    expect($this->provider->supportsTiming(SubscriptionChangeTiming::Immediate))->toBeTrue()
        ->and($this->provider->supportsTiming(SubscriptionChangeTiming::NextPeriod))->toBeTrue();
});
