<?php

declare(strict_types=1);

use Develupers\PlanUsage\Contracts\Billable;
use Develupers\PlanUsage\Contracts\SubscriptionLifecycleProvider;
use Develupers\PlanUsage\Enums\SubscriptionChangeTiming;
use Develupers\PlanUsage\Providers\Paddle\PaddleProvider;
use Develupers\PlanUsage\Support\ProviderSubscriptionChange;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Laravel\Paddle\Subscription as PaddleSubscription;

/**
 * Real Cashier Paddle subscription subclass with the API-calling methods stubbed
 * so the provider can be exercised without hitting Paddle. Extending the real class
 * keeps the `instanceof PaddleSubscription` guard and the Eloquent magic attributes.
 */
class FakePaddleLifecycleSubscription extends PaddleSubscription
{
    public ?string $swappedTo = null;

    public bool $wasCancelledNow = false;

    public bool $wasCancelled = false;

    public bool $wasStopCancelation = false;

    public function swapAndInvoice($items, array $options = [])
    {
        $this->swappedTo = is_array($items) ? ($items[0] ?? null) : $items;

        return $this;
    }

    public function asPaddleSubscription(?string $include = null)
    {
        return [
            'current_billing_period' => [
                'starts_at' => '2026-07-01T00:00:00Z',
                'ends_at' => '2026-08-01T00:00:00Z',
            ],
        ];
    }

    public function cancel(bool $cancelNow = false)
    {
        $this->wasCancelled = true;

        return $this;
    }

    public function cancelNow()
    {
        $this->wasCancelledNow = true;

        return $this;
    }

    public function stopCancelation()
    {
        $this->wasStopCancelation = true;

        return $this;
    }
}

function paddleLifecycleBillable(mixed $subscription): Model
{
    $billable = Mockery::mock(Model::class.', '.Billable::class);
    $billable->shouldReceive('subscription')->with('default')->andReturn($subscription);

    return $billable;
}

beforeEach(function () {
    $this->provider = new PaddleProvider;
});

it('implements the subscription lifecycle contract', function () {
    expect($this->provider)->toBeInstanceOf(SubscriptionLifecycleProvider::class);
});

it('applies an immediate plan change through swapAndInvoice', function () {
    $subscription = new FakePaddleLifecycleSubscription;
    $subscription->paddle_id = 'sub_paddle_123';

    $change = $this->provider->changeSubscription(
        paddleLifecycleBillable($subscription),
        'pri_growth',
        SubscriptionChangeTiming::Immediate,
    );

    expect($subscription->swappedTo)->toBe('pri_growth')
        ->and($change)->toBeInstanceOf(ProviderSubscriptionChange::class)
        ->and($change->providerSubscriptionId)->toBe('sub_paddle_123')
        ->and($change->currentProductId)->toBe('pri_growth')
        ->and($change->pendingProductId)->toBeNull()
        ->and($change->periodStart->toDateString())->toBe('2026-07-01')
        ->and($change->periodEnd->toDateString())->toBe('2026-08-01');
});

it('rejects scheduled (next-period) plan changes', function () {
    $billable = Mockery::mock(Model::class.', '.Billable::class);

    expect(fn () => $this->provider->changeSubscription(
        $billable,
        'pri_growth',
        SubscriptionChangeTiming::NextPeriod,
    ))->toThrow(ValidationException::class, 'Paddle supports immediate plan changes only.');
});

it('cancels immediately via cancelNow', function () {
    $subscription = new FakePaddleLifecycleSubscription;

    $this->provider->cancelSubscription(paddleLifecycleBillable($subscription), immediately: true);

    expect($subscription->wasCancelledNow)->toBeTrue()
        ->and($subscription->wasCancelled)->toBeFalse();
});

it('cancels at period end via cancel', function () {
    $subscription = new FakePaddleLifecycleSubscription;

    $this->provider->cancelSubscription(paddleLifecycleBillable($subscription));

    expect($subscription->wasCancelled)->toBeTrue()
        ->and($subscription->wasCancelledNow)->toBeFalse();
});

it('un-cancels via stopCancelation rather than resume', function () {
    $subscription = new FakePaddleLifecycleSubscription;

    $this->provider->resumeSubscription(paddleLifecycleBillable($subscription));

    expect($subscription->wasStopCancelation)->toBeTrue();
});

it('rejects cancelling a non-existent pending change', function () {
    $billable = Mockery::mock(Model::class.', '.Billable::class);

    expect(fn () => $this->provider->cancelPendingSubscriptionChange($billable))
        ->toThrow(ValidationException::class, 'Paddle does not support scheduled plan changes');
});

it('fails when the billable has no subscription', function () {
    expect(fn () => $this->provider->cancelSubscription(paddleLifecycleBillable(null)))
        ->toThrow(ValidationException::class, 'No active Paddle subscription found.');
});
