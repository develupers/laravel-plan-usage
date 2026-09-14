<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Develupers\PlanUsage\Actions\Subscription\ApplyPlanChangeAction;
use Develupers\PlanUsage\Actions\Subscription\ConfirmPendingPlanChangeAction;
use Develupers\PlanUsage\Contracts\Billable;
use Develupers\PlanUsage\Enums\Period;
use Develupers\PlanUsage\Enums\SubscriptionChangeStatus;
use Develupers\PlanUsage\Enums\SubscriptionChangeTiming;
use Develupers\PlanUsage\Models\Feature;
use Develupers\PlanUsage\Models\Plan;
use Develupers\PlanUsage\Models\PlanPrice;
use Develupers\PlanUsage\Models\SubscriptionPlanChange;
use Develupers\PlanUsage\Services\QuotaEnforcer;
use Develupers\PlanUsage\Support\ProviderSubscriptionChange;
use Develupers\PlanUsage\Traits\HasPlanFeatures;
use Illuminate\Database\Eloquent\Model;

class ConfirmPendingChangeTestBillable extends Model implements Billable
{
    use HasPlanFeatures;

    public $timestamps = false;

    protected $table = 'test_billables';

    protected $guarded = [];
}

beforeEach(function () {
    config()->set('plan-usage.billing.provider', 'polar');
    config()->set('plan-usage.models.billable', ConfirmPendingChangeTestBillable::class);

    $this->credits = Feature::factory()->quota()->create([
        'slug' => 'confirm-credits',
        'reset_period' => Period::MONTH,
    ]);
    $this->starter = Plan::factory()->create(['slug' => 'confirm-starter']);
    $this->growth = Plan::factory()->create(['slug' => 'confirm-growth']);
    $this->starterPrice = $this->starter->defaultPrice()->first();
    $this->starterPrice->update(['polar_product_id' => 'prod_confirm_starter']);
    $this->growthPrice = $this->growth->defaultPrice()->first();
    $this->growthPrice->update(['polar_product_id' => 'prod_confirm_growth']);
    $this->starter->features()->attach($this->credits->id, ['value' => '1000']);
    $this->growth->features()->attach($this->credits->id, ['value' => '5000']);

    $this->billable = ConfirmPendingChangeTestBillable::query()->create([
        'plan_id' => $this->starter->id,
        'plan_price_id' => $this->starterPrice->id,
    ]);
    $this->billable->load('plan.features');
    $this->billable->syncQuotasWithPlan();
    $this->billable->quotas()->where('feature_id', $this->credits->id)->update(['used' => 200]);

    $this->pending = SubscriptionPlanChange::query()->create([
        'billable_type' => $this->billable->getMorphClass(),
        'billable_id' => $this->billable->getKey(),
        'provider' => 'polar',
        'subscription_type' => 'default',
        'provider_subscription_id' => 'sub_confirm_1',
        'from_plan_price_id' => $this->starterPrice->id,
        'to_plan_price_id' => $this->growthPrice->id,
        'timing' => SubscriptionChangeTiming::NextPeriod,
        'status' => SubscriptionChangeStatus::Pending,
    ]);

    $this->action = new ConfirmPendingPlanChangeAction(new ApplyPlanChangeAction(app(QuotaEnforcer::class)));
    $this->providerChange = fn (PlanPrice $target) => new ProviderSubscriptionChange(
        providerSubscriptionId: 'sub_confirm_1',
        currentProductId: 'prod_confirm_growth',
        pendingProductId: null,
        periodStart: CarbonImmutable::parse('2026-07-01 00:00:00'),
        periodEnd: CarbonImmutable::parse('2026-08-01 00:00:00'),
    );
});

it('applies the entitlements and marks the record applied atomically', function () {
    // A crash between applying quotas and marking the record would leave it
    // pending; the retry would apply it AGAIN and wipe usage accumulated
    // since the first, already-committed application.
    SubscriptionPlanChange::saving(function (SubscriptionPlanChange $change): void {
        if ($change->status === SubscriptionChangeStatus::Applied) {
            throw new RuntimeException('crashed while marking applied');
        }
    });

    expect(fn () => $this->action->execute(
        $this->billable,
        provider: 'polar',
        providerSubscriptionId: 'sub_confirm_1',
        currentProductId: 'prod_confirm_growth',
        pendingUpdate: null,
        providerChange: $this->providerChange,
    ))->toThrow(RuntimeException::class);

    $quota = $this->billable->quotas()->where('feature_id', $this->credits->id)->firstOrFail();

    expect($this->pending->fresh()->status)->toBe(SubscriptionChangeStatus::Pending)
        ->and($this->billable->fresh()->plan_id)->toBe($this->starter->id)
        ->and($quota->limit)->toBe(1000.0)
        ->and($quota->used)->toBe(200.0);
});

it('applies a completed change and marks it applied', function () {
    $change = $this->action->execute(
        $this->billable,
        provider: 'polar',
        providerSubscriptionId: 'sub_confirm_1',
        currentProductId: 'prod_confirm_growth',
        pendingUpdate: null,
        providerChange: $this->providerChange,
    );

    expect($change?->status)->toBe(SubscriptionChangeStatus::Applied)
        ->and($this->billable->fresh()->plan_id)->toBe($this->growth->id);
});

it('ignores provider state observed before the pending change existed', function () {
    $change = $this->action->execute(
        $this->billable,
        provider: 'polar',
        providerSubscriptionId: 'sub_confirm_1',
        currentProductId: 'prod_confirm_starter', // not the target — looks like an out-of-band swap
        pendingUpdate: null,
        providerChange: $this->providerChange,
        observedAt: CarbonImmutable::now()->subMinute(),
    );

    expect($change?->status)->toBe(SubscriptionChangeStatus::Pending);
});

it('cancels the pending change on an out-of-band swap observed after it was created', function () {
    $change = $this->action->execute(
        $this->billable,
        provider: 'polar',
        providerSubscriptionId: 'sub_confirm_1',
        currentProductId: 'prod_confirm_starter',
        pendingUpdate: null,
        providerChange: $this->providerChange,
        observedAt: CarbonImmutable::now()->addMinute(),
    );

    expect($change?->status)->toBe(SubscriptionChangeStatus::Cancelled);
});

it('cancels the pending change on an out-of-band swap when the observation time is unknown', function () {
    // Reconciliation fetches live state and passes no timestamp.
    $change = $this->action->execute(
        $this->billable,
        provider: 'polar',
        providerSubscriptionId: 'sub_confirm_1',
        currentProductId: 'prod_confirm_starter',
        pendingUpdate: null,
        providerChange: $this->providerChange,
    );

    expect($change?->status)->toBe(SubscriptionChangeStatus::Cancelled);
});
