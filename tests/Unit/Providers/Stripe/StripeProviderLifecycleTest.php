<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Develupers\PlanUsage\Contracts\Billable;
use Develupers\PlanUsage\Contracts\SubscriptionLifecycleProvider;
use Develupers\PlanUsage\Enums\SubscriptionChangeTiming;
use Develupers\PlanUsage\Providers\Stripe\StripeProvider;
use Develupers\PlanUsage\Support\ProviderSubscriptionChange;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Laravel\Cashier\Subscription as CashierSubscription;
use Stripe\Subscription;

/**
 * Real Cashier subscription subclass with the API-calling methods stubbed so the
 * provider can be exercised without hitting Stripe. Extending the real class keeps
 * the `instanceof CashierSubscription` guard and the Eloquent magic attributes.
 */
class FakeStripeLifecycleSubscription extends CashierSubscription
{
    public ?string $swappedTo = null;

    public bool $wasCancelledNow = false;

    public bool $wasCancelled = false;

    public bool $wasResumed = false;

    public function swapAndInvoice(string|array $prices, array $options = [])
    {
        $this->swappedTo = is_array($prices) ? ($prices[0] ?? null) : $prices;

        return $this;
    }

    public function asStripeSubscription(array $expand = [])
    {
        return Subscription::constructFrom([
            'id' => 'sub_stripe_123',
            'items' => [
                'data' => [
                    [
                        'current_period_start' => CarbonImmutable::parse('2026-07-01T00:00:00Z')->getTimestamp(),
                        'current_period_end' => CarbonImmutable::parse('2026-08-01T00:00:00Z')->getTimestamp(),
                    ],
                ],
            ],
        ]);
    }

    public function cancel()
    {
        $this->wasCancelled = true;

        return $this;
    }

    public function cancelNow()
    {
        $this->wasCancelledNow = true;

        return $this;
    }

    public function resume()
    {
        $this->wasResumed = true;

        return $this;
    }
}

function stripeLifecycleBillable(mixed $subscription): Model
{
    $billable = Mockery::mock(Model::class.', '.Billable::class);
    $billable->shouldReceive('subscription')->with('default')->andReturn($subscription);

    return $billable;
}

beforeEach(function () {
    $this->provider = new StripeProvider;
});

it('implements the subscription lifecycle contract', function () {
    expect($this->provider)->toBeInstanceOf(SubscriptionLifecycleProvider::class);
});

it('applies an immediate plan change through swapAndInvoice', function () {
    $subscription = new FakeStripeLifecycleSubscription;
    $subscription->stripe_id = 'sub_stripe_123';

    $change = $this->provider->changeSubscription(
        stripeLifecycleBillable($subscription),
        'price_growth',
        SubscriptionChangeTiming::Immediate,
    );

    expect($subscription->swappedTo)->toBe('price_growth')
        ->and($change)->toBeInstanceOf(ProviderSubscriptionChange::class)
        ->and($change->providerSubscriptionId)->toBe('sub_stripe_123')
        ->and($change->currentProductId)->toBe('price_growth')
        ->and($change->pendingProductId)->toBeNull()
        ->and($change->periodStart->toDateString())->toBe('2026-07-01')
        ->and($change->periodEnd->toDateString())->toBe('2026-08-01');
});

it('rejects scheduled (next-period) plan changes', function () {
    $billable = Mockery::mock(Model::class.', '.Billable::class);

    expect(fn () => $this->provider->changeSubscription(
        $billable,
        'price_growth',
        SubscriptionChangeTiming::NextPeriod,
    ))->toThrow(ValidationException::class, 'Stripe supports immediate plan changes only.');
});

it('cancels immediately via cancelNow', function () {
    $subscription = new FakeStripeLifecycleSubscription;

    $this->provider->cancelSubscription(stripeLifecycleBillable($subscription), immediately: true);

    expect($subscription->wasCancelledNow)->toBeTrue()
        ->and($subscription->wasCancelled)->toBeFalse();
});

it('cancels at period end via cancel', function () {
    $subscription = new FakeStripeLifecycleSubscription;

    $this->provider->cancelSubscription(stripeLifecycleBillable($subscription));

    expect($subscription->wasCancelled)->toBeTrue()
        ->and($subscription->wasCancelledNow)->toBeFalse();
});

it('resumes a subscription', function () {
    $subscription = new FakeStripeLifecycleSubscription;

    $this->provider->resumeSubscription(stripeLifecycleBillable($subscription));

    expect($subscription->wasResumed)->toBeTrue();
});

it('rejects cancelling a non-existent pending change', function () {
    $billable = Mockery::mock(Model::class.', '.Billable::class);

    expect(fn () => $this->provider->cancelPendingSubscriptionChange($billable))
        ->toThrow(ValidationException::class, 'Stripe does not support scheduled plan changes');
});

it('fails when the billable has no subscription', function () {
    expect(fn () => $this->provider->cancelSubscription(stripeLifecycleBillable(null)))
        ->toThrow(ValidationException::class, 'No active Stripe subscription found.');
});

it('reports supported plan change timings', function () {
    expect($this->provider->supportsTiming(SubscriptionChangeTiming::Immediate))->toBeTrue()
        ->and($this->provider->supportsTiming(SubscriptionChangeTiming::NextPeriod))->toBeFalse();
});
