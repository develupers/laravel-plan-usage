<?php

declare(strict_types=1);

use Develupers\PlanUsage\Actions\Subscription\CancelSubscriptionAction;
use Illuminate\Validation\ValidationException;
use Laravel\Cashier\Subscription;

beforeEach(function () {
    $this->action = new CancelSubscriptionAction;
});

it('cancels subscription at period end by default', function () {
    $subscription = Mockery::mock(Subscription::class);
    $subscription->shouldReceive('canceled')->once()->andReturn(false);
    $subscription->shouldReceive('cancel')->once();

    $billable = createMockBillable();
    $billable->shouldReceive('subscription')
        ->with('default')
        ->once()
        ->andReturn($subscription);

    $this->action->execute($billable);
});

it('cancels subscription immediately when specified', function () {
    $subscription = Mockery::mock(Subscription::class);
    $subscription->shouldReceive('canceled')->once()->andReturn(false);
    $subscription->shouldReceive('cancelNow')->once();

    $billable = createMockBillable();
    $billable->shouldReceive('subscription')
        ->with('default')
        ->once()
        ->andReturn($subscription);

    $this->action->execute($billable, immediately: true);
});

it('throws exception when no subscription found', function () {
    $billable = createMockBillable();
    $billable->shouldReceive('subscription')
        ->with('default')
        ->once()
        ->andReturn(null);

    $this->action->execute($billable);
})->throws(ValidationException::class, 'No active subscription found.');

it('throws exception when subscription already cancelled', function () {
    $subscription = Mockery::mock(Subscription::class);
    $subscription->shouldReceive('canceled')->once()->andReturn(true);

    $billable = createMockBillable();
    $billable->shouldReceive('subscription')
        ->with('default')
        ->once()
        ->andReturn($subscription);

    $this->action->execute($billable);
})->throws(ValidationException::class, 'Subscription is already cancelled.');

it('cancels custom named subscription', function () {
    $subscription = Mockery::mock(Subscription::class);
    $subscription->shouldReceive('canceled')->once()->andReturn(false);
    $subscription->shouldReceive('cancel')->once();

    $billable = createMockBillable();
    $billable->shouldReceive('subscription')
        ->with('premium')
        ->once()
        ->andReturn($subscription);

    $this->action->execute($billable, subscriptionName: 'premium');
});

it('cancels all active subscriptions', function () {
    $subscription1 = Mockery::mock(Subscription::class);
    $subscription1->shouldReceive('canceled')->once()->andReturn(false);
    $subscription1->shouldReceive('cancel')->once();

    $subscription2 = Mockery::mock(Subscription::class);
    $subscription2->shouldReceive('canceled')->once()->andReturn(false);
    $subscription2->shouldReceive('cancel')->once();

    $subscription3 = Mockery::mock(Subscription::class);
    $subscription3->shouldReceive('canceled')->once()->andReturn(true); // Already cancelled

    $subscriptions = collect([$subscription1, $subscription2, $subscription3]);
    $activeQuery = Mockery::mock();
    $activeQuery->shouldReceive('get')->once()->andReturn($subscriptions);
    $activeQuery->shouldReceive('active')->once()->andReturn($activeQuery);

    $billable = createMockBillable();
    $billable->shouldReceive('subscriptions')->once()->andReturn($activeQuery);

    $this->action->cancelAll($billable);
});

it('resumes cancelled subscription in grace period', function () {
    $subscription = Mockery::mock(Subscription::class);
    $subscription->shouldReceive('onGracePeriod')->once()->andReturn(true);
    $subscription->shouldReceive('resume')->once();

    $billable = createMockBillable();
    $billable->shouldReceive('subscription')
        ->with('default')
        ->once()
        ->andReturn($subscription);

    $this->action->resume($billable);
});

it('throws exception when resuming subscription not in grace period', function () {
    $subscription = Mockery::mock(Subscription::class);
    $subscription->shouldReceive('onGracePeriod')->once()->andReturn(false);

    $billable = createMockBillable();
    $billable->shouldReceive('subscription')
        ->with('default')
        ->once()
        ->andReturn($subscription);

    $this->action->resume($billable);
})->throws(ValidationException::class, 'Subscription is not in grace period and cannot be resumed.');
