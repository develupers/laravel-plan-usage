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
use Develupers\PlanUsage\Exceptions\DeferredWebhookReplayException;
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
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class PolarWebhookTestBillable extends Model implements Billable
{
    use HasPlanFeatures;
    use PolarBillable;

    public $timestamps = false;

    protected $table = 'test_billables';

    protected $guarded = [];
}

class PolarWebhookTrashedBillable extends PolarWebhookTestBillable
{
    use SoftDeletes;
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
    $this->travelTo(CarbonImmutable::parse('2026-07-16 00:00:00 UTC'));
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

    // The quota window is the feature's calendar reset period (next reset
    // = Aug 1), independent of the missing billing-period fields — the
    // freshly granted quota must not be immediately expired.
    expect($planChange->fresh()->status)->toBe(SubscriptionChangeStatus::Applied)
        ->and($this->billable->fresh()->plan_id)->toBe($this->starter->id)
        ->and($quota->reset_at?->toDateTimeString())->toBe('2026-08-01 00:00:00')
        ->and($quota->limit)->toBe(1000.0);
});

it('keeps entitlements during a period-end cancellation', function () {
    $this->travelTo(CarbonImmutable::parse('2026-07-09 12:00:00 UTC'));

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
    // laravel-polar creates a local row for every subscription, typed from
    // the checkout metadata — the add-on has its own, non-default row.
    $this->billable->subscriptions()->create([
        'type' => 'addon',
        'polar_id' => 'sub_addon_999',
        'status' => 'active',
        'product_id' => 'prod_webhook_growth',
    ]);
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

it('revokes when a canceled subscription grace period has already ended', function () {
    $this->travelTo(CarbonImmutable::parse('2026-07-09 12:00:00 UTC'));

    // Polar 'canceled' is a grace period only while the effective end is in
    // the future — a delayed delivery after ends_at must revoke, not KEEP.
    $payload = polarPlanUsagePayload(
        $this->billable,
        'subscription.canceled',
        'prod_webhook_growth',
        status: 'canceled',
    );
    $payload['data']['ends_at'] = '2026-07-01T00:00:00Z'; // already past

    $this->listener->handle(new WebhookHandled($payload));

    expect($this->billable->fresh()->plan_id)->toBeNull()
        ->and(BillingWebhookEvent::query()->whereNotNull('processed_at')->count())->toBe(1);
});

it('does not apply a matured pending change while the subscription is past_due (KEEP)', function () {
    // A next-period upgrade whose renewal payment failed: Polar reports
    // past_due with the target product and the pending update gone. KEEP must
    // not apply the unpaid upgrade.
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

    $this->listener->handle(new WebhookHandled(polarPlanUsagePayload(
        $this->billable,
        'subscription.updated',
        'prod_webhook_starter', // provider already swapped to the target
        status: 'past_due',
    )));

    // Plan unchanged, pending change still pending, event processed.
    expect($this->billable->fresh()->plan_id)->toBe($this->growth->id)
        ->and(SubscriptionPlanChange::query()->firstOrFail()->status)->toBe(SubscriptionChangeStatus::Pending)
        ->and(BillingWebhookEvent::query()->whereNotNull('processed_at')->count())->toBe(1);
});

it('never lets a historical subscription event revoke a lifetime plan', function () {
    // Cancel-then-buy-lifetime: the old default subscription row still exists
    // and its delayed terminal event must not wipe the lifetime plan.
    $lifetimePlan = Plan::factory()->create(['slug' => 'webhook-lifetime', 'is_lifetime' => true]);
    $this->billable->update(['plan_id' => $lifetimePlan->id, 'plan_price_id' => null]);

    $this->listener->handle(new WebhookHandled(polarPlanUsagePayload(
        $this->billable,
        'subscription.revoked',
        'prod_webhook_growth',
        status: 'canceled',
    )));

    expect($this->billable->fresh()->plan_id)->toBe($lifetimePlan->id)
        ->and(BillingWebhookEvent::query()->whereNotNull('ignored_at')->count())->toBe(1);
});

it('defers an event that beats subscription.created and replays it once the row exists', function () {
    // Polar emits created/active/updated within milliseconds; queued jobs
    // can process `active` before `created` has inserted the local row.
    // Ignoring it used to make `created` look stale too — dropping the
    // grant for both and leaving a paid account planless until reconcile.
    $billable = PolarWebhookTestBillable::query()->create([]);
    Customer::query()->create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'polar_id' => 'customer_new_1',
    ]);

    $active = polarPlanUsagePayload($billable, 'subscription.active', 'prod_webhook_growth', modifiedAt: '2026-07-09T12:00:01Z');
    $active['data']['id'] = 'sub_new_1';
    $active['data']['customer_id'] = 'customer_new_1';

    $this->listener->handle(new WebhookHandled($active));

    $deferred = BillingWebhookEvent::query()->firstOrFail();

    expect($billable->fresh()->plan_id)->toBeNull()
        ->and($deferred->processed_at)->toBeNull()
        ->and($deferred->ignored_at)->toBeNull()
        ->and($deferred->last_error)->toBe(PolarWebhookListener::DEFERRED_ERROR);

    // laravel-polar's created handler inserts the row (from the created
    // payload, still `incomplete`), then dispatches WebhookHandled.
    $billable->subscriptions()->create([
        'type' => 'default',
        'polar_id' => 'sub_new_1',
        'status' => 'incomplete',
        'product_id' => 'prod_webhook_growth',
    ]);
    $created = polarPlanUsagePayload($billable, 'subscription.created', 'prod_webhook_growth', modifiedAt: '2026-07-09T12:00:00Z', status: 'incomplete');
    $created['data']['id'] = 'sub_new_1';
    $created['data']['customer_id'] = 'customer_new_1';

    $this->listener->handle(new WebhookHandled($created));

    $row = $billable->subscriptions()->where('polar_id', 'sub_new_1')->firstOrFail();

    expect($billable->fresh()->plan_id)->toBe($this->growth->id)
        ->and(BillingWebhookEvent::query()->whereNotNull('processed_at')->count())->toBe(2)
        ->and(BillingWebhookEvent::query()->whereNotNull('ignored_at')->count())->toBe(0)
        ->and($deferred->fresh()->last_error)->toBeNull()
        // The replayed payload also brought the local row up to date —
        // laravel-polar skipped it because the row did not exist yet.
        ->and($row->status->value)->toBe('active');
});

it('restores the local subscription row after a stale terminal event overwrote it', function () {
    // laravel-polar syncs the row in DELIVERY order before this listener
    // runs; a late `revoked` flips a live row to ended even though the
    // listener ignores it — which would unlock a duplicate checkout.
    $this->listener->handle(new WebhookHandled(polarPlanUsagePayload(
        $this->billable,
        'subscription.updated',
        'prod_webhook_growth',
        modifiedAt: '2026-07-10T12:00:00Z',
    )));

    $row = $this->billable->subscriptions()->where('polar_id', 'sub_webhook_123')->firstOrFail();
    // What laravel-polar's revoked handler would have written from the stale payload.
    $row->update(['status' => 'canceled', 'ends_at' => '2026-07-01 00:00:00']);

    $stale = polarPlanUsagePayload($this->billable, 'subscription.revoked', 'prod_webhook_growth', modifiedAt: '2026-07-09T12:00:00Z', status: 'canceled');
    $stale['data']['ends_at'] = '2026-07-01T00:00:00Z';

    $this->listener->handle(new WebhookHandled($stale));

    $row = $row->fresh();

    expect($this->billable->fresh()->plan_id)->toBe($this->growth->id)
        ->and(BillingWebhookEvent::query()->whereNotNull('ignored_at')->count())->toBe(1)
        ->and($row->status->value)->toBe('active')
        ->and($row->ends_at)->toBeNull()
        ->and($this->billable->fresh()->hasLiveSubscription())->toBeTrue();
});

it('does not let a webhook older than a pending change cancel it', function () {
    // The user cancelled a scheduled downgrade and immediately scheduled a
    // new one. The delayed `updated` echo of the CANCEL (no pending_update,
    // product = current) must not cancel the fresh record Polar still holds.
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
        modifiedAt: now()->subHour()->toIso8601ZuluString(),
    )));

    expect($planChange->fresh()->status)->toBe(SubscriptionChangeStatus::Pending)
        ->and(BillingWebhookEvent::query()->whereNotNull('processed_at')->count())->toBe(1);

    // Provider state observed AFTER the record was created is authoritative.
    $this->listener->handle(new WebhookHandled(polarPlanUsagePayload(
        $this->billable,
        'subscription.updated',
        'prod_webhook_growth',
        modifiedAt: now()->addMinute()->toIso8601ZuluString(),
    )));

    expect($planChange->fresh()->status)->toBe(SubscriptionChangeStatus::Cancelled);
});

it('resolves a soft-deleted billable from the checkout metadata', function () {
    // The subscription keeps emitting events during a deletion recovery
    // window; a restore must find the state those events left behind.
    Schema::table('test_billables', function (Blueprint $table): void {
        $table->timestamp('deleted_at')->nullable();
    });
    $billable = PolarWebhookTrashedBillable::query()->create([
        'plan_id' => $this->growth->id,
        'plan_price_id' => $this->growthPrice->id,
    ]);
    $billable->subscriptions()->create([
        'type' => 'default',
        'polar_id' => 'sub_trashed_1',
        'status' => 'active',
        'product_id' => 'prod_webhook_growth',
    ]);
    $billable->delete();

    $payload = polarPlanUsagePayload($billable, 'subscription.revoked', 'prod_webhook_growth', status: 'canceled');
    $payload['data']['id'] = 'sub_trashed_1';
    $payload['data']['customer_id'] = 'customer_unknown';

    $this->listener->handle(new WebhookHandled($payload));

    expect(PolarWebhookTrashedBillable::withTrashed()->findOrFail($billable->getKey())->plan_id)->toBeNull()
        ->and(BillingWebhookEvent::query()->whereNotNull('processed_at')->count())->toBe(1);
});

it('keeps processing webhooks when the payload column has not been published yet', function () {
    Schema::table('billing_webhook_events', function (Blueprint $table): void {
        $table->dropColumn('payload');
    });
    BillingWebhookEvent::flushSchemaCache();

    $this->listener->handle(new WebhookHandled(polarPlanUsagePayload(
        $this->billable,
        'subscription.updated',
        'prod_webhook_starter',
    )));

    expect($this->billable->fresh()->plan_id)->toBe($this->starter->id)
        ->and(BillingWebhookEvent::query()->whereNotNull('processed_at')->count())->toBe(1);
});

it('leaves a stale event retryable when restoring the local row fails', function () {
    $this->listener->handle(new WebhookHandled(polarPlanUsagePayload(
        $this->billable,
        'subscription.updated',
        'prod_webhook_growth',
        modifiedAt: '2026-07-10T12:00:00Z',
    )));

    // Corrupt the stored payload the repair reads from so sync() throws.
    $processed = BillingWebhookEvent::query()->firstOrFail();
    $payload = $processed->payload;
    $payload['data']['status'] = 'not-a-status';
    $processed->update(['payload' => $payload]);

    $row = $this->billable->subscriptions()->where('polar_id', 'sub_webhook_123')->firstOrFail();
    $row->update(['status' => 'canceled', 'ends_at' => '2026-07-01 00:00:00']);

    $stale = polarPlanUsagePayload($this->billable, 'subscription.revoked', 'prod_webhook_growth', modifiedAt: '2026-07-09T12:00:00Z', status: 'canceled');

    expect(fn () => $this->listener->handle(new WebhookHandled($stale)))->toThrow(ValueError::class);

    $staleEvent = BillingWebhookEvent::query()->where('event_type', 'subscription.revoked')->firstOrFail();

    // Not acknowledged: a redelivery (or the next drain) retries the repair.
    expect($staleEvent->ignored_at)->toBeNull()
        ->and($staleEvent->processed_at)->toBeNull()
        ->and($staleEvent->last_error)->not->toBeNull();

    $payload['data']['status'] = 'active';
    $processed->update(['payload' => $payload]);

    $this->listener->handle(new WebhookHandled($stale));

    expect($staleEvent->fresh()->ignored_at)->not->toBeNull()
        ->and($row->fresh()->status->value)->toBe('active');
});

it('drains an older deferred delivery on the next event instead of leaving it dangling', function () {
    $billable = PolarWebhookTestBillable::query()->create([]);
    Customer::query()->create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'polar_id' => 'customer_new_2',
    ]);

    $active = polarPlanUsagePayload($billable, 'subscription.active', 'prod_webhook_starter', modifiedAt: '2026-07-09T12:00:01Z');
    $active['data']['id'] = 'sub_new_2';
    $active['data']['customer_id'] = 'customer_new_2';
    $this->listener->handle(new WebhookHandled($active));

    $billable->subscriptions()->create([
        'type' => 'default',
        'polar_id' => 'sub_new_2',
        'status' => 'active',
        'product_id' => 'prod_webhook_growth',
    ]);

    // A NEWER delivery arrives (a mid-period swap to growth). The deferred
    // `active` is older than it, so draining must resolve it as stale — not
    // apply its starter product over the newer state, and not leave it
    // waiting forever.
    $updated = polarPlanUsagePayload($billable, 'subscription.updated', 'prod_webhook_growth', modifiedAt: '2026-07-09T12:00:05Z');
    $updated['data']['id'] = 'sub_new_2';
    $updated['data']['customer_id'] = 'customer_new_2';
    $this->listener->handle(new WebhookHandled($updated));

    $deferred = BillingWebhookEvent::query()->where('event_type', 'subscription.active')->firstOrFail();

    expect($billable->fresh()->plan_id)->toBe($this->growth->id)
        ->and($deferred->ignored_at)->not->toBeNull()
        ->and($deferred->last_error)->toBeNull()
        ->and(BillingWebhookEvent::query()->deferred()->count())->toBe(0);
});

it('fails the job when a deferred replay fails and drains it again on redelivery', function () {
    // Without this, a replay that hits a transient failure — with no newer
    // event ever arriving — would leave the deferred grant stranded.
    $billable = PolarWebhookTestBillable::query()->create([]);
    Customer::query()->create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'polar_id' => 'customer_new_3',
    ]);

    $active = polarPlanUsagePayload($billable, 'subscription.active', 'prod_webhook_growth', modifiedAt: '2026-07-09T12:00:01Z');
    $active['data']['id'] = 'sub_new_3';
    $active['data']['customer_id'] = 'customer_new_3';
    $this->listener->handle(new WebhookHandled($active));

    $billable->subscriptions()->create([
        'type' => 'default',
        'polar_id' => 'sub_new_3',
        'status' => 'incomplete',
        'product_id' => 'prod_webhook_growth',
    ]);
    $created = polarPlanUsagePayload($billable, 'subscription.created', 'prod_webhook_growth', modifiedAt: '2026-07-09T12:00:00Z', status: 'incomplete');
    $created['data']['id'] = 'sub_new_3';
    $created['data']['customer_id'] = 'customer_new_3';

    // The plan sync fails transiently during the replay of `active`.
    $failingSync = Mockery::mock(SyncPlanWithBillableAction::class);
    $failingSync->shouldReceive('execute')->once()->andThrow(new RuntimeException('deadlock'));
    $failingListener = new PolarWebhookListener(
        new PolarProvider,
        $failingSync,
        new DeleteSubscriptionAction,
        new ConfirmPendingPlanChangeAction(new ApplyPlanChangeAction(app(QuotaEnforcer::class))),
        new SubscriptionStateLock,
    );

    expect(fn () => $failingListener->handle(new WebhookHandled($created)))
        ->toThrow(DeferredWebhookReplayException::class, 'deadlock');

    $createdEvent = BillingWebhookEvent::query()->where('event_type', 'subscription.created')->firstOrFail();
    $activeEvent = BillingWebhookEvent::query()->where('event_type', 'subscription.active')->firstOrFail();

    expect($billable->fresh()->plan_id)->toBeNull()
        ->and($createdEvent->processed_at)->not->toBeNull()
        ->and($createdEvent->last_error)->toBeNull()
        ->and($activeEvent->processed_at)->toBeNull()
        ->and($activeEvent->last_error)->toBe('deadlock');

    // A queue retry / Polar redelivery of the already-processed `created`
    // drains the backlog with a healthy listener.
    $this->listener->handle(new WebhookHandled($created));

    expect($billable->fresh()->plan_id)->toBe($this->growth->id)
        ->and($activeEvent->fresh()->processed_at)->not->toBeNull()
        ->and($activeEvent->fresh()->last_error)->toBeNull()
        ->and(BillingWebhookEvent::query()->count())->toBe(2);
});

it('short-circuits a redelivery when nothing is waiting', function () {
    $event = new WebhookHandled(polarPlanUsagePayload($this->billable, 'subscription.updated', 'prod_webhook_starter'));
    $this->listener->handle($event);

    $sync = Mockery::mock(SyncPlanWithBillableAction::class);
    $sync->shouldReceive('execute')->never();
    $listener = new PolarWebhookListener(
        new PolarProvider,
        $sync,
        new DeleteSubscriptionAction,
        new ConfirmPendingPlanChangeAction(new ApplyPlanChangeAction(app(QuotaEnforcer::class))),
        new SubscriptionStateLock,
    );

    $listener->handle($event);

    expect(BillingWebhookEvent::query()->count())->toBe(1)
        ->and($this->billable->fresh()->plan_id)->toBe($this->starter->id);
});

// --- overdue payment under the revoke policy: suspend, do not wipe ----------

it('suspends an overdue subscription without deleting its quota rows', function () {
    // The provider is still retrying the payment. Deleting the quota rows
    // here would hand back a fresh allowance when the retry succeeds.
    config()->set('plan-usage.polar.past_due_keeps_entitlements', false);
    $this->billable->quotas()->where('feature_id', $this->credits->id)->update(['used' => 3200]);

    $this->listener->handle(new WebhookHandled(polarPlanUsagePayload(
        $this->billable,
        'subscription.past_due',
        'prod_webhook_growth',
        status: 'past_due',
    )));

    $billable = $this->billable->fresh();
    $quota = $billable->quotas()->where('feature_id', $this->credits->id)->first();

    expect($billable->plan_id)->toBeNull()
        ->and($billable->plan_price_id)->toBeNull()
        ->and($quota)->not->toBeNull()
        ->and((float) $quota->used)->toBe(3200.0);
});

it('restores the plan with its usage intact when the overdue payment is recovered', function () {
    config()->set('plan-usage.polar.past_due_keeps_entitlements', false);
    $this->billable->quotas()->where('feature_id', $this->credits->id)->update(['used' => 3200]);

    $this->listener->handle(new WebhookHandled(polarPlanUsagePayload(
        $this->billable,
        'subscription.past_due',
        'prod_webhook_growth',
        '2026-07-09T12:00:00Z',
        status: 'past_due',
    )));
    $this->listener->handle(new WebhookHandled(polarPlanUsagePayload(
        $this->billable,
        'subscription.active',
        'prod_webhook_growth',
        '2026-07-11T12:00:00Z',
    )));

    $billable = $this->billable->fresh();
    $quota = $billable->quotas()->where('feature_id', $this->credits->id)->first();

    expect($billable->plan_id)->toBe($this->growth->id)
        ->and((float) $quota->limit)->toBe(5000.0)
        ->and((float) $quota->used)->toBe(3200.0);
});

it('deletes the kept quota rows once the overdue subscription is finally revoked', function () {
    config()->set('plan-usage.polar.past_due_keeps_entitlements', false);

    $this->listener->handle(new WebhookHandled(polarPlanUsagePayload(
        $this->billable,
        'subscription.past_due',
        'prod_webhook_growth',
        '2026-07-09T12:00:00Z',
        status: 'past_due',
    )));
    expect($this->billable->fresh()->quotas()->count())->toBe(1);

    $this->listener->handle(new WebhookHandled(polarPlanUsagePayload(
        $this->billable,
        'subscription.revoked',
        'prod_webhook_growth',
        '2026-07-30T12:00:00Z',
        status: 'canceled',
    )));

    expect($this->billable->fresh()->plan_id)->toBeNull()
        ->and($this->billable->fresh()->quotas()->count())->toBe(0);
});

it('leaves the kept quota rows alone when the overdue event repeats', function () {
    config()->set('plan-usage.polar.past_due_keeps_entitlements', false);
    $this->billable->quotas()->where('feature_id', $this->credits->id)->update(['used' => 3200]);

    foreach (['2026-07-09T12:00:00Z', '2026-07-11T12:00:00Z'] as $modifiedAt) {
        $this->listener->handle(new WebhookHandled(polarPlanUsagePayload(
            $this->billable,
            'subscription.updated',
            'prod_webhook_growth',
            $modifiedAt,
            status: 'past_due',
        )));
    }

    expect((float) $this->billable->fresh()->quotas()->where('feature_id', $this->credits->id)->value('used'))->toBe(3200.0);
});

it('still deletes quota rows for a status the provider will not recover from', function () {
    // Only overdue-and-retrying suspends. An unpaid subscription has run out
    // of retries, so its allowance goes with the plan.
    config()->set('plan-usage.polar.past_due_keeps_entitlements', false);

    $this->listener->handle(new WebhookHandled(polarPlanUsagePayload(
        $this->billable,
        'subscription.updated',
        'prod_webhook_growth',
        status: 'unpaid',
    )));

    expect($this->billable->fresh()->plan_id)->toBeNull()
        ->and($this->billable->fresh()->quotas()->count())->toBe(0);
});
