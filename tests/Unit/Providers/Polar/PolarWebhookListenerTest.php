<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Danestves\LaravelPolar\Billable as PolarBillable;
use Danestves\LaravelPolar\Customer;
use Danestves\LaravelPolar\Events\WebhookHandled;
use Develupers\PlanUsage\Actions\Subscription\ApplyPlanChangeAction;
use Develupers\PlanUsage\Actions\Subscription\ConfirmPendingPlanChangeAction;
use Develupers\PlanUsage\Actions\Subscription\DeleteSubscriptionAction;
use Develupers\PlanUsage\Actions\Subscription\SyncPlanWithBillableAction;
use Develupers\PlanUsage\Contracts\Billable;
use Develupers\PlanUsage\Enums\Interval;
use Develupers\PlanUsage\Enums\Period;
use Develupers\PlanUsage\Enums\SubscriptionChangeStatus;
use Develupers\PlanUsage\Enums\SubscriptionChangeTiming;
use Develupers\PlanUsage\Models\BillingWebhookEvent;
use Develupers\PlanUsage\Models\Feature;
use Develupers\PlanUsage\Models\Plan;
use Develupers\PlanUsage\Models\SubscriptionPlanChange;
use Develupers\PlanUsage\Providers\Polar\PolarProvider;
use Develupers\PlanUsage\Providers\Polar\PolarWebhookListener;
use Develupers\PlanUsage\Services\QuotaEnforcer;
use Develupers\PlanUsage\Support\SubscriptionStateLock;
use Develupers\PlanUsage\Traits\HasPlanFeatures;
use Illuminate\Database\Eloquent\Model;

class PolarWebhookTestBillable extends Model implements Billable
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
function polarPlanUsagePayload(
    PolarWebhookTestBillable $billable,
    string $type,
    string $productId,
    string $modifiedAt = '2026-07-09T12:00:00Z',
    ?array $pendingUpdate = null,
    string $status = 'active'
): array {
    return [
        'type' => $type,
        'timestamp' => $modifiedAt,
        'data' => [
            'id' => 'sub_webhook_123',
            'status' => $status,
            'product_id' => $productId,
            'customer_id' => 'customer_webhook_123',
            'customer' => [
                'metadata' => [
                    'billable_id' => (string) $billable->getKey(),
                    'billable_type' => $billable->getMorphClass(),
                    'subscription_type' => 'default',
                ],
            ],
            'current_period_start' => '2026-07-01T00:00:00Z',
            'current_period_end' => '2026-08-01T00:00:00Z',
            'ends_at' => $type === 'subscription.canceled' ? '2026-08-01T00:00:00Z' : null,
            'modified_at' => $modifiedAt,
            'cancel_at_period_end' => $type === 'subscription.canceled',
            'pending_update' => $pendingUpdate,
        ],
    ];
}

beforeEach(function () {
    config()->set('plan-usage.billing.provider', 'polar');
    config()->set('plan-usage.models.billable', PolarWebhookTestBillable::class);
    config()->set('plan-usage.subscription.default_plan_id', null);

    $this->credits = Feature::factory()->quota()->create([
        'slug' => 'webhook-credits',
        'reset_period' => Period::MONTH,
    ]);
    $this->starter = Plan::factory()->create(['slug' => 'webhook-starter']);
    $this->growth = Plan::factory()->create(['slug' => 'webhook-growth']);
    $this->starterPrice = $this->starter->defaultPrice()->first();
    $this->starterPrice->update(['polar_product_id' => 'prod_webhook_starter']);
    $this->growthPrice = $this->growth->defaultPrice()->first();
    $this->growthPrice->update(['polar_product_id' => 'prod_webhook_growth']);
    $this->starter->features()->attach($this->credits->id, ['value' => '1000']);
    $this->growth->features()->attach($this->credits->id, ['value' => '5000']);

    $this->billable = PolarWebhookTestBillable::query()->create([
        'plan_id' => $this->growth->id,
        'plan_price_id' => $this->growthPrice->id,
    ]);
    $this->billable->load('plan.features');
    $this->billable->syncQuotasWithPlan();
    Customer::query()->create([
        'billable_type' => $this->billable->getMorphClass(),
        'billable_id' => $this->billable->getKey(),
        'polar_id' => 'customer_webhook_123',
    ]);
    $this->billable->subscriptions()->create([
        'type' => 'default',
        'polar_id' => 'sub_webhook_123',
        'status' => 'active',
        'product_id' => 'prod_webhook_growth',
        'current_period_end' => '2026-08-01 00:00:00',
    ]);

    $this->listener = new PolarWebhookListener(
        new PolarProvider,
        new SyncPlanWithBillableAction,
        new DeleteSubscriptionAction,
        new ConfirmPendingPlanChangeAction(new ApplyPlanChangeAction(app(QuotaEnforcer::class))),
        new SubscriptionStateLock,
    );
});

it('records a pending downgrade without applying the future product', function () {
    $planChange = SubscriptionPlanChange::query()->create([
        'billable_type' => $this->billable->getMorphClass(),
        'billable_id' => $this->billable->getKey(),
        'provider' => 'polar',
        'subscription_type' => 'default',
        'provider_subscription_id' => 'sub_webhook_123',
        'from_plan_price_id' => $this->growthPrice->id,
        'to_plan_price_id' => $this->starterPrice->id,
        'timing' => SubscriptionChangeTiming::NextPeriod,
        'status' => SubscriptionChangeStatus::Pending,
    ]);

    $this->listener->handle(new WebhookHandled(polarPlanUsagePayload(
        $this->billable,
        'subscription.updated',
        'prod_webhook_growth',
        pendingUpdate: [
            'id' => 'pending_webhook_123',
            'product_id' => 'prod_webhook_starter',
            'applies_at' => '2026-08-01T00:00:00Z',
        ],
    )));

    expect($this->billable->fresh()->plan_id)->toBe($this->growth->id)
        ->and($this->billable->fresh()->plan_price_id)->toBe($this->growthPrice->id)
        ->and($planChange->fresh()->status)->toBe(SubscriptionChangeStatus::Pending)
        ->and($planChange->fresh()->provider_change_id)->toBe('pending_webhook_123');
});

it('applies the pending downgrade only when the current Polar product changes', function () {
    $planChange = SubscriptionPlanChange::query()->create([
        'billable_type' => $this->billable->getMorphClass(),
        'billable_id' => $this->billable->getKey(),
        'provider' => 'polar',
        'subscription_type' => 'default',
        'provider_subscription_id' => 'sub_webhook_123',
        'from_plan_price_id' => $this->growthPrice->id,
        'to_plan_price_id' => $this->starterPrice->id,
        'timing' => SubscriptionChangeTiming::NextPeriod,
        'status' => SubscriptionChangeStatus::Pending,
    ]);

    $this->listener->handle(new WebhookHandled(polarPlanUsagePayload(
        $this->billable,
        'subscription.updated',
        'prod_webhook_starter',
        modifiedAt: '2026-08-01T00:00:01Z',
    )));

    $quota = $this->billable->fresh()->quotas()->where('feature_id', $this->credits->id)->firstOrFail();

    expect($this->billable->fresh()->plan_id)->toBe($this->starter->id)
        ->and($this->billable->fresh()->plan_price_id)->toBe($this->starterPrice->id)
        ->and($planChange->fresh()->status)->toBe(SubscriptionChangeStatus::Applied)
        ->and($quota->limit)->toBe(1000.0)
        ->and($quota->used)->toBe(0.0);
});

it('repairs a stranded immediate change without wiping usage', function () {
    CarbonImmutable::setTestNow('2026-07-16 00:00:00');
    // Billable is mid-cycle on starter (1000 credits, 200 used); an immediate
    // upgrade to growth crashed after the provider call, leaving the pending
    // record behind. The webhook echoing the applied change must repair it
    // with immediate semantics: prorate the limit, preserve usage — never
    // reset usage as if a new period had started.
    $this->billable->update([
        'plan_id' => $this->starter->id,
        'plan_price_id' => $this->starterPrice->id,
    ]);
    $this->billable->unsetRelation('plan');
    $this->billable->syncQuotasWithPlan();
    $this->billable->quotas()
        ->where('feature_id', $this->credits->id)
        ->update(['used' => 200]);
    SubscriptionPlanChange::query()->create([
        'billable_type' => $this->billable->getMorphClass(),
        'billable_id' => $this->billable->getKey(),
        'provider' => 'polar',
        'subscription_type' => 'default',
        'provider_subscription_id' => 'sub_webhook_123',
        'from_plan_price_id' => $this->starterPrice->id,
        'to_plan_price_id' => $this->growthPrice->id,
        'timing' => SubscriptionChangeTiming::Immediate,
        'status' => SubscriptionChangeStatus::Pending,
    ]);

    $this->listener->handle(new WebhookHandled(polarPlanUsagePayload(
        $this->billable,
        'subscription.updated',
        'prod_webhook_growth',
    )));

    $quota = $this->billable->fresh()->quotas()->where('feature_id', $this->credits->id)->firstOrFail();

    // Prorated upgrade: 1000 + (5000 - 1000) × (16 remaining days / 31)
    expect($this->billable->fresh()->plan_id)->toBe($this->growth->id)
        ->and($quota->used)->toBe(200.0)
        ->and($quota->limit)->toBe(3064.5161);

    CarbonImmutable::setTestNow();
});

it('applies a pending change safely when the webhook omits billing period fields', function () {
    $this->starterPrice->update(['interval' => Interval::MONTH]);
    $planChange = SubscriptionPlanChange::query()->create([
        'billable_type' => $this->billable->getMorphClass(),
        'billable_id' => $this->billable->getKey(),
        'provider' => 'polar',
        'subscription_type' => 'default',
        'provider_subscription_id' => 'sub_webhook_123',
        'from_plan_price_id' => $this->growthPrice->id,
        'to_plan_price_id' => $this->starterPrice->id,
        'timing' => SubscriptionChangeTiming::NextPeriod,
        'status' => SubscriptionChangeStatus::Pending,
    ]);

    $payload = polarPlanUsagePayload(
        $this->billable,
        'subscription.updated',
        'prod_webhook_starter',
        modifiedAt: '2026-08-01T00:00:01Z',
    );
    unset($payload['data']['current_period_end']);

    $this->listener->handle(new WebhookHandled($payload));

    $quota = $this->billable->fresh()->quotas()->where('feature_id', $this->credits->id)->firstOrFail();

    // Fallback period end = period start + target price interval (1 month),
    // NOT "now" — the freshly granted quota must not be immediately expired.
    expect($planChange->fresh()->status)->toBe(SubscriptionChangeStatus::Applied)
        ->and($this->billable->fresh()->plan_id)->toBe($this->starter->id)
        ->and($quota->reset_at?->toDateTimeString())->toBe('2026-08-01 00:00:00')
        ->and($quota->limit)->toBe(1000.0);
});

it('keeps entitlements during a period-end cancellation', function () {
    $this->listener->handle(new WebhookHandled(polarPlanUsagePayload(
        $this->billable,
        'subscription.canceled',
        'prod_webhook_growth',
        status: 'canceled',
    )));

    expect($this->billable->fresh()->plan_id)->toBe($this->growth->id)
        ->and($this->billable->fresh()->plan_price_id)->toBe($this->growthPrice->id);
});

it('revokes entitlements only when Polar revokes the subscription', function () {
    $this->listener->handle(new WebhookHandled(polarPlanUsagePayload(
        $this->billable,
        'subscription.revoked',
        'prod_webhook_growth',
        status: 'unpaid',
    )));

    expect($this->billable->fresh()->plan_id)->toBeNull()
        ->and($this->billable->fresh()->plan_price_id)->toBeNull();
});

it('deduplicates webhook deliveries durably', function () {
    $event = new WebhookHandled(polarPlanUsagePayload(
        $this->billable,
        'subscription.updated',
        'prod_webhook_growth',
    ));

    $this->listener->handle($event);
    $this->listener->handle($event);

    expect(BillingWebhookEvent::query()->count())->toBe(1)
        ->and(BillingWebhookEvent::query()->first()?->processed_at)->not->toBeNull();
});

it('ignores older subscription events after a newer event is processed', function () {
    $this->listener->handle(new WebhookHandled(polarPlanUsagePayload(
        $this->billable,
        'subscription.updated',
        'prod_webhook_growth',
        modifiedAt: '2026-07-10T12:00:00Z',
    )));
    $this->listener->handle(new WebhookHandled(polarPlanUsagePayload(
        $this->billable,
        'subscription.updated',
        'prod_webhook_starter',
        modifiedAt: '2026-07-09T12:00:00Z',
    )));

    expect($this->billable->fresh()->plan_id)->toBe($this->growth->id)
        ->and(BillingWebhookEvent::query()->whereNotNull('ignored_at')->count())->toBe(1);
});

it('ignores a stale same-second update after a processed revoke', function () {
    // Microsecond precision: the revoke at .900000 dominates the stale
    // update at .100000 even though both share the same wall-clock second.
    $this->listener->handle(new WebhookHandled(polarPlanUsagePayload(
        $this->billable,
        'subscription.revoked',
        'prod_webhook_growth',
        modifiedAt: '2026-07-10T12:00:00.900000Z',
        status: 'unpaid',
    )));

    expect($this->billable->fresh()->plan_id)->toBeNull();

    $this->listener->handle(new WebhookHandled(polarPlanUsagePayload(
        $this->billable,
        'subscription.updated',
        'prod_webhook_growth',
        modifiedAt: '2026-07-10T12:00:00.100000Z',
    )));

    expect($this->billable->fresh()->plan_id)->toBeNull()
        ->and(BillingWebhookEvent::query()->whereNotNull('ignored_at')->count())->toBe(1);
});

it('never restores entitlements from an update with the exact timestamp of a processed revoke', function () {
    // Equal timestamps are ambiguous — terminal dominance must win.
    $this->listener->handle(new WebhookHandled(polarPlanUsagePayload(
        $this->billable,
        'subscription.revoked',
        'prod_webhook_growth',
        modifiedAt: '2026-07-10T12:00:00Z',
        status: 'unpaid',
    )));
    $this->listener->handle(new WebhookHandled(polarPlanUsagePayload(
        $this->billable,
        'subscription.updated',
        'prod_webhook_growth',
        modifiedAt: '2026-07-10T12:00:00Z',
    )));

    expect($this->billable->fresh()->plan_id)->toBeNull()
        ->and(BillingWebhookEvent::query()->whereNotNull('ignored_at')->count())->toBe(1);
});

it('rethrows processing failures and leaves the durable event retryable', function () {
    SubscriptionPlanChange::query()->create([
        'billable_type' => $this->billable->getMorphClass(),
        'billable_id' => $this->billable->getKey(),
        'provider' => 'polar',
        'subscription_type' => 'default',
        'provider_subscription_id' => 'sub_webhook_123',
        'from_plan_price_id' => $this->growthPrice->id,
        'to_plan_price_id' => $this->starterPrice->id,
        'timing' => SubscriptionChangeTiming::NextPeriod,
        'status' => SubscriptionChangeStatus::Pending,
    ]);
    $applyPlanChange = Mockery::mock(ApplyPlanChangeAction::class);
    $applyPlanChange->shouldReceive('execute')->once()->andThrow(new RuntimeException('Quota sync failed'));
    $listener = new PolarWebhookListener(
        new PolarProvider,
        new SyncPlanWithBillableAction,
        new DeleteSubscriptionAction,
        new ConfirmPendingPlanChangeAction($applyPlanChange),
        new SubscriptionStateLock,
    );

    expect(fn () => $listener->handle(new WebhookHandled(polarPlanUsagePayload(
        $this->billable,
        'subscription.updated',
        'prod_webhook_starter',
    ))))->toThrow(RuntimeException::class, 'Quota sync failed');

    $billingEvent = BillingWebhookEvent::query()->firstOrFail();

    expect($billingEvent->processed_at)->toBeNull()
        ->and($billingEvent->ignored_at)->toBeNull()
        ->and($billingEvent->last_error)->toBe('Quota sync failed');
});

it('ignores subscription events for a non-default (add-on) polar subscription', function () {
    // An add-on subscription's lifecycle must not revoke or replace the main
    // plan: its revoked event previously deleted the billable's entitlements.
    $payload = polarPlanUsagePayload($this->billable, 'subscription.revoked', 'prod_webhook_growth');
    $payload['data']['id'] = 'sub_addon_999';

    $this->listener->handle(new WebhookHandled($payload));

    expect($this->billable->fresh()->plan_id)->toBe($this->growth->id)
        ->and(BillingWebhookEvent::query()->whereNotNull('ignored_at')->count())->toBe(1);
});

it('revokes a stale plan when the default subscription reports a non-holding status', function () {
    // incomplete/unpaid previously fell into "ignored", leaving a stale paid
    // plan in place; the shared policy revokes it.
    $this->listener->handle(new WebhookHandled(polarPlanUsagePayload(
        $this->billable,
        'subscription.updated',
        'prod_webhook_growth',
        status: 'incomplete',
    )));

    expect($this->billable->fresh()->plan_id)->toBeNull()
        ->and($this->billable->fresh()->quotas()->count())->toBe(0)
        ->and(BillingWebhookEvent::query()->whereNotNull('processed_at')->count())->toBe(1);
});
