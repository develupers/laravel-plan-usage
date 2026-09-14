<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Develupers\PlanUsage\Actions\Subscription\ApplyPlanChangeAction;
use Develupers\PlanUsage\Contracts\Billable;
use Develupers\PlanUsage\Enums\Period;
use Develupers\PlanUsage\Models\Feature;
use Develupers\PlanUsage\Models\Plan;
use Develupers\PlanUsage\Services\QuotaEnforcer;
use Develupers\PlanUsage\Support\ProviderSubscriptionChange;
use Develupers\PlanUsage\Traits\HasPlanFeatures;
use Illuminate\Database\Eloquent\Model;

class ApplyPlanChangeTestBillable extends Model implements Billable
{
    use HasPlanFeatures;

    public $timestamps = false;

    protected $table = 'test_billables';

    protected $guarded = [];
}

beforeEach(function () {
    config()->set('plan-usage.models.billable', ApplyPlanChangeTestBillable::class);

    $this->credits = Feature::factory()->quota()->create([
        'slug' => 'apply-credits',
        'reset_period' => Period::MONTH,
    ]);
    $this->lifetimeCredits = Feature::factory()->quota()->create([
        'slug' => 'apply-lifetime-credits',
        'reset_period' => null,
    ]);
    $this->starter = Plan::factory()->create(['slug' => 'apply-starter']);
    $this->growth = Plan::factory()->create(['slug' => 'apply-growth']);
    $this->growthPrice = $this->growth->defaultPrice()->first();
    $this->starter->features()->attach($this->credits->id, ['value' => '1000']);
    $this->growth->features()->attach($this->credits->id, ['value' => '5000']);
    $this->starter->features()->attach($this->lifetimeCredits->id, ['value' => '10']);
    $this->growth->features()->attach($this->lifetimeCredits->id, ['value' => '50']);

    $this->billable = ApplyPlanChangeTestBillable::query()->create([
        'plan_id' => $this->starter->id,
        'plan_price_id' => $this->starter->defaultPrice()->first()->id,
    ]);
    $this->billable->load('plan.features');
    $this->billable->syncQuotasWithPlan();
    $this->billable->quotas()->where('feature_id', $this->credits->id)->update(['used' => 200]);

    $this->action = new ApplyPlanChangeAction(app(QuotaEnforcer::class));
});

it('anchors a resetting quota to the calendar reset period, not the billing period', function () {
    // Every other quota path (initial sync, Quota::reset, the reset job)
    // uses the feature's calendar period. Anchoring to the billing slice
    // used to put reset_at on the billing day, after which the next normal
    // reset snapped it back to the 1st — two short, over-generous windows.
    $this->travelTo(CarbonImmutable::parse('2026-07-16 00:00:00 UTC'));

    $this->action->execute(
        $this->billable,
        $this->growthPrice,
        new ProviderSubscriptionChange(
            providerSubscriptionId: 'sub_apply_1',
            currentProductId: 'prod_apply_growth',
            pendingProductId: null,
            periodStart: CarbonImmutable::parse('2026-07-10 00:00:00'),
            periodEnd: CarbonImmutable::parse('2026-08-10 00:00:00'),
        ),
        resetUsage: false,
    );

    $quota = $this->billable->quotas()->where('feature_id', $this->credits->id)->firstOrFail();

    // 16 of July's 31 days remain: 1000 + 4000 × 16/31 = 3064.5161.
    expect($quota->reset_at?->toDateTimeString())->toBe('2026-08-01 00:00:00')
        ->and($quota->limit)->toBe(3064.5161)
        ->and($quota->used)->toBe(200.0);
});

it('grants the full target to a non-resetting allowance and keeps reset_at null', function () {
    $this->travelTo(CarbonImmutable::parse('2026-07-16 00:00:00 UTC'));

    $this->action->execute(
        $this->billable,
        $this->growthPrice,
        new ProviderSubscriptionChange(
            providerSubscriptionId: 'sub_apply_1',
            currentProductId: 'prod_apply_growth',
            pendingProductId: null,
            periodStart: CarbonImmutable::parse('2026-07-10 00:00:00'),
            periodEnd: CarbonImmutable::parse('2026-08-10 00:00:00'),
        ),
        resetUsage: false,
    );

    $quota = $this->billable->quotas()->where('feature_id', $this->lifetimeCredits->id)->firstOrFail();

    expect($quota->reset_at)->toBeNull()
        ->and($quota->limit)->toBe(50.0);
});
