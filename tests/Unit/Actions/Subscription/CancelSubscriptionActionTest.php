<?php

declare(strict_types=1);

use Develupers\PlanUsage\Actions\Subscription\ApplyPlanChangeAction;
use Develupers\PlanUsage\Actions\Subscription\CancelSubscriptionAction;
use Develupers\PlanUsage\Actions\Subscription\ChangeSubscriptionPlanAction;
use Develupers\PlanUsage\Actions\Subscription\DeleteSubscriptionAction;
use Develupers\PlanUsage\Contracts\Billable;
use Develupers\PlanUsage\Contracts\BillingProvider;
use Develupers\PlanUsage\Contracts\SubscriptionLifecycleProvider;
use Develupers\PlanUsage\Enums\SubscriptionChangeTiming;
use Develupers\PlanUsage\Models\PlanPrice;
use Develupers\PlanUsage\Support\SubscriptionStateLock;
use Illuminate\Database\Eloquent\Model;
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

it('removes local entitlements after a lifecycle provider confirms immediate revocation', function () {
    $subscription = Mockery::mock(Subscription::class);
    $subscription->shouldReceive('canceled')->once()->andReturn(false);
    $billable = Mockery::mock(Model::class.', '.Billable::class);
    $billable->shouldReceive('refresh')->andReturnSelf();
    $billable->shouldReceive('unsetRelation')->andReturnSelf();
    $billable->shouldReceive('subscription')->with('default')->once()->andReturn($subscription);
    $provider = Mockery::mock(BillingProvider::class.', '.SubscriptionLifecycleProvider::class);
    $provider->shouldReceive('cancelSubscription')
        ->once()
        ->with($billable, true, 'default');
    $deleteSubscription = Mockery::mock(DeleteSubscriptionAction::class);
    $deleteSubscription->shouldReceive('execute')->once()->with($billable);

    (new CancelSubscriptionAction($provider, $deleteSubscription))
        ->execute($billable, immediately: true);
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

it('resumes a polar-style period-end cancellation that never enters a local grace period', function () {
    // Polar keeps a scheduled cancellation ACTIVE with cancel_at_period_end,
    // so laravel-polar's onGracePeriod() (canceled status + future end date)
    // never passes — the old local gate made un-cancelling impossible there.
    $subscription = new class
    {
        public function cancelled(): bool
        {
            return false;
        }

        public function onGracePeriod(): bool
        {
            return false;
        }
    };

    $billable = Mockery::mock(Model::class.', '.Billable::class);
    $billable->shouldReceive('refresh')->andReturnSelf();
    $billable->shouldReceive('unsetRelation')->andReturnSelf();
    $billable->shouldReceive('subscription')->with('default')->once()->andReturn($subscription);

    $provider = Mockery::mock(BillingProvider::class.', '.SubscriptionLifecycleProvider::class);
    $provider->shouldReceive('resumeSubscription')
        ->once()
        ->with($billable, 'default');

    (new CancelSubscriptionAction($provider))->resume($billable);
});

it('refuses to resume a subscription that has fully ended', function () {
    $subscription = new class
    {
        public function cancelled(): bool
        {
            return true;
        }

        public function onGracePeriod(): bool
        {
            return false;
        }
    };

    $billable = Mockery::mock(Model::class.', '.Billable::class);
    $billable->shouldReceive('refresh')->andReturnSelf();
    $billable->shouldReceive('unsetRelation')->andReturnSelf();
    $billable->shouldReceive('subscription')->with('default')->once()->andReturn($subscription);

    $provider = Mockery::mock(BillingProvider::class.', '.SubscriptionLifecycleProvider::class);
    $provider->shouldNotReceive('resumeSubscription');

    expect(fn () => (new CancelSubscriptionAction($provider))->resume($billable))
        ->toThrow(ValidationException::class, 'Subscription has ended and cannot be resumed.');
});

it('does not revoke the plan when immediately cancelling a non-default subscription', function () {
    // Cancelling an add-on must not delete the entitlement the still-active
    // default subscription controls.
    $subscription = Mockery::mock(Subscription::class);
    $subscription->shouldReceive('canceled')->once()->andReturn(false);

    $billable = Mockery::mock(Model::class.', '.Billable::class);
    $billable->shouldReceive('refresh')->andReturnSelf();
    $billable->shouldReceive('unsetRelation')->andReturnSelf();
    $billable->shouldReceive('subscription')->with('addon')->once()->andReturn($subscription);

    $provider = Mockery::mock(BillingProvider::class.', '.SubscriptionLifecycleProvider::class);
    $provider->shouldReceive('cancelSubscription')
        ->once()
        ->with($billable, true, 'addon');

    $deleteSubscription = Mockery::mock(DeleteSubscriptionAction::class);
    $deleteSubscription->shouldNotReceive('execute');

    (new CancelSubscriptionAction($provider, $deleteSubscription))
        ->execute($billable, immediately: true, subscriptionName: 'addon');
});

it('rejects a managed plan change for a non-default subscription name', function () {
    $provider = Mockery::mock(BillingProvider::class.', '.SubscriptionLifecycleProvider::class);
    $provider->shouldReceive('name')->andReturn('polar');
    $billable = Mockery::mock(Model::class.', '.Billable::class);
    $price = PlanPrice::factory()->create();

    $action = new ChangeSubscriptionPlanAction(
        $provider,
        Mockery::mock(ApplyPlanChangeAction::class),
        new SubscriptionStateLock,
    );

    expect(fn () => $action->execute($billable, $price, SubscriptionChangeTiming::Immediate, 'addon'))
        ->toThrow(ValidationException::class, "Managed plan changes only apply to the 'default' subscription");
});
