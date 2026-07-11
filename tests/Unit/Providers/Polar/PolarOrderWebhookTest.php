<?php

declare(strict_types=1);

use Danestves\LaravelPolar\Billable as PolarBillable;
use Danestves\LaravelPolar\Customer;
use Danestves\LaravelPolar\Events\WebhookHandled;
use Develupers\PlanUsage\Actions\Subscription\ApplyPlanChangeAction;
use Develupers\PlanUsage\Actions\Subscription\ConfirmPendingPlanChangeAction;
use Develupers\PlanUsage\Actions\Subscription\DeleteSubscriptionAction;
use Develupers\PlanUsage\Actions\Subscription\SyncPlanWithBillableAction;
use Develupers\PlanUsage\Contracts\Billable;
use Develupers\PlanUsage\Enums\Interval;
use Develupers\PlanUsage\Models\BillingWebhookEvent;
use Develupers\PlanUsage\Models\Feature;
use Develupers\PlanUsage\Models\Plan;
use Develupers\PlanUsage\Providers\Polar\PolarProvider;
use Develupers\PlanUsage\Providers\Polar\PolarWebhookListener;
use Develupers\PlanUsage\Services\QuotaEnforcer;
use Develupers\PlanUsage\Support\SubscriptionStateLock;
use Develupers\PlanUsage\Traits\HasPlanFeatures;
use Illuminate\Database\Eloquent\Model;

class PolarOrderTestBillable extends Model implements Billable
{
    use HasPlanFeatures;
    use PolarBillable;

    public $timestamps = false;

    protected $table = 'test_billables';

    protected $guarded = [];
}

/**
 * @return array<string, mixed>
 */
function polarOrderPayload(
    PolarOrderTestBillable $billable,
    string $type,
    string $productId,
    string $status = 'paid',
    ?string $subscriptionId = null,
    string $timestamp = '2026-07-09T12:00:00Z'
): array {
    return [
        'type' => $type,
        'timestamp' => $timestamp,
        'data' => [
            'id' => 'order_lifetime_123',
            'status' => $status,
            'product_id' => $productId,
            'subscription_id' => $subscriptionId,
            'customer_id' => 'customer_order_123',
            'customer' => [
                'metadata' => [
                    'billable_id' => (string) $billable->getKey(),
                    'billable_type' => $billable->getMorphClass(),
                ],
            ],
            'billing_reason' => $subscriptionId === null ? 'purchase' : 'subscription_cycle',
            'modified_at' => $timestamp,
        ],
    ];
}

beforeEach(function () {
    config()->set('plan-usage.billing.provider', 'polar');
    config()->set('plan-usage.models.billable', PolarOrderTestBillable::class);
    config()->set('plan-usage.subscription.default_plan_id', null);

    $this->credits = Feature::factory()->quota()->create([
        'slug' => 'order-lifetime-credits',
        'reset_period' => null,
    ]);
    $this->lifetimePlan = Plan::factory()->create([
        'slug' => 'order-lifetime-plan',
        'is_lifetime' => true,
    ]);
    $this->lifetimePrice = $this->lifetimePlan->defaultPrice()->firstOrFail();
    $this->lifetimePrice->update([
        'interval' => Interval::LIFETIME,
        'polar_product_id' => 'prod_order_lifetime',
    ]);
    $this->lifetimePlan->features()->attach($this->credits->id, ['value' => '2500']);

    $this->billable = PolarOrderTestBillable::query()->create();
    Customer::query()->create([
        'billable_type' => $this->billable->getMorphClass(),
        'billable_id' => $this->billable->getKey(),
        'polar_id' => 'customer_order_123',
    ]);

    $this->listener = new PolarWebhookListener(
        new PolarProvider,
        new SyncPlanWithBillableAction,
        new DeleteSubscriptionAction,
        new ConfirmPendingPlanChangeAction(new ApplyPlanChangeAction(app(QuotaEnforcer::class))),
        new SubscriptionStateLock,
    );
});

it('assigns the lifetime plan when a one-time order is paid', function () {
    $this->listener->handle(new WebhookHandled(polarOrderPayload(
        $this->billable,
        'order.paid',
        'prod_order_lifetime',
    )));

    $quota = $this->billable->fresh()->quotas()->where('feature_id', $this->credits->id)->firstOrFail();

    expect($this->billable->fresh()->plan_id)->toBe($this->lifetimePlan->id)
        ->and($this->billable->fresh()->plan_price_id)->toBe($this->lifetimePrice->id)
        ->and($quota->limit)->toBe(2500.0)
        ->and($quota->used)->toBe(0.0)
        ->and($quota->reset_at)->toBeNull()
        ->and(BillingWebhookEvent::query()->whereNotNull('processed_at')->count())->toBe(1);
});

it('ignores orders that belong to a subscription', function () {
    $this->listener->handle(new WebhookHandled(polarOrderPayload(
        $this->billable,
        'order.paid',
        'prod_order_lifetime',
        subscriptionId: 'sub_renewal_123',
    )));

    expect($this->billable->fresh()->plan_id)->toBeNull()
        ->and(BillingWebhookEvent::query()->whereNotNull('ignored_at')->count())->toBe(1);
});

it('ignores unpaid orders', function () {
    $this->listener->handle(new WebhookHandled(polarOrderPayload(
        $this->billable,
        'order.created',
        'prod_order_lifetime',
        status: 'pending',
    )));

    expect($this->billable->fresh()->plan_id)->toBeNull();
});

it('revokes the lifetime plan when the order is fully refunded', function () {
    $this->billable->update([
        'plan_id' => $this->lifetimePlan->id,
        'plan_price_id' => $this->lifetimePrice->id,
    ]);
    $this->billable->load('plan.features');
    $this->billable->syncQuotasWithPlan();

    $this->listener->handle(new WebhookHandled(polarOrderPayload(
        $this->billable,
        'order.refunded',
        'prod_order_lifetime',
        status: 'refunded',
        timestamp: '2026-07-10T12:00:00Z',
    )));

    expect($this->billable->fresh()->plan_id)->toBeNull()
        ->and($this->billable->fresh()->plan_price_id)->toBeNull()
        ->and($this->billable->quotas()->count())->toBe(0);
});

it('deduplicates order webhook deliveries', function () {
    $event = new WebhookHandled(polarOrderPayload(
        $this->billable,
        'order.paid',
        'prod_order_lifetime',
    ));

    $this->listener->handle($event);
    $this->listener->handle($event);

    expect(BillingWebhookEvent::query()->count())->toBe(1)
        ->and($this->billable->fresh()->plan_id)->toBe($this->lifetimePlan->id);
});

it('ignores paid orders for non-lifetime products', function () {
    $recurringPlan = Plan::factory()->create(['slug' => 'order-recurring-plan']);
    $recurringPrice = $recurringPlan->defaultPrice()->firstOrFail();
    $recurringPrice->update([
        'interval' => Interval::MONTH,
        'polar_product_id' => 'prod_order_recurring',
    ]);

    $this->listener->handle(new WebhookHandled(polarOrderPayload(
        $this->billable,
        'order.paid',
        'prod_order_recurring',
    )));

    expect($this->billable->fresh()->plan_id)->toBeNull()
        ->and(BillingWebhookEvent::query()->whereNotNull('ignored_at')->count())->toBe(1);
});

it('leaves the event retryable when local application fails', function () {
    $sync = Mockery::mock(SyncPlanWithBillableAction::class);
    $sync->shouldReceive('execute')->once()->andThrow(new RuntimeException('Quota sync failed'));
    $failingListener = new PolarWebhookListener(
        new PolarProvider,
        $sync,
        new DeleteSubscriptionAction,
        new ConfirmPendingPlanChangeAction(new ApplyPlanChangeAction(app(QuotaEnforcer::class))),
        new SubscriptionStateLock,
    );
    $event = new WebhookHandled(polarOrderPayload(
        $this->billable,
        'order.paid',
        'prod_order_lifetime',
    ));

    expect(fn () => $failingListener->handle($event))
        ->toThrow(RuntimeException::class, 'Quota sync failed');

    $billingEvent = BillingWebhookEvent::query()->firstOrFail();

    expect($billingEvent->processed_at)->toBeNull()
        ->and($billingEvent->last_error)->toBe('Quota sync failed');

    // The retry (same delivery) succeeds with a working listener.
    $this->listener->handle($event);

    expect($this->billable->fresh()->plan_id)->toBe($this->lifetimePlan->id);
});

it('does not re-grant when a paid replay carries the same timestamp as a processed refund', function () {
    $ambiguousTimestamp = '2026-07-10T12:00:00.250000Z';

    $this->listener->handle(new WebhookHandled(polarOrderPayload(
        $this->billable,
        'order.paid',
        'prod_order_lifetime',
        timestamp: '2026-07-10T11:00:00Z',
    )));
    $this->listener->handle(new WebhookHandled(polarOrderPayload(
        $this->billable,
        'order.refunded',
        'prod_order_lifetime',
        status: 'refunded',
        timestamp: $ambiguousTimestamp,
    )));

    expect($this->billable->fresh()->plan_id)->toBeNull();

    // A delayed order.updated replay still carrying the pre-refund status and
    // an equal (ambiguous) timestamp must not restore the entitlement:
    // order.refunded is terminal for its order's lineage.
    $this->listener->handle(new WebhookHandled(polarOrderPayload(
        $this->billable,
        'order.updated',
        'prod_order_lifetime',
        timestamp: $ambiguousTimestamp,
    )));

    expect($this->billable->fresh()->plan_id)->toBeNull()
        ->and(BillingWebhookEvent::query()->whereNotNull('ignored_at')->count())->toBe(1);
});
