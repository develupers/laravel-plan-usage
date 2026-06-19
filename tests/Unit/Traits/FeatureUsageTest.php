<?php

declare(strict_types=1);

use Develupers\PlanUsage\Models\Feature;
use Develupers\PlanUsage\Models\Plan;
use Develupers\PlanUsage\Models\PlanFeature;

/** @return array{0: object, 1: Plan} */
function billableOnPlan(): array
{
    $plan = Plan::factory()->create();
    $billable = createBillable(['plan_id' => $plan->id]);
    $billable->setRelation('plan', $plan);

    return [$billable, $plan];
}

function grantPlanFeature(Plan $plan, string $slug, string $type, ?string $value): Feature
{
    $feature = Feature::factory()->create(['slug' => $slug, 'type' => $type]);
    PlanFeature::create(['plan_id' => $plan->id, 'feature_id' => $feature->id, 'value' => $value]);

    return $feature;
}

it('returns null for a metered feature the plan does not grant', function () {
    [$billable] = billableOnPlan();
    // Exists globally and is metered, but is NOT attached to this plan.
    Feature::factory()->create(['slug' => 'ungranted-credits', 'type' => 'quota']);

    expect($billable->getFeatureUsage('ungranted-credits'))->toBeNull();
});

it('returns null for a non-existent feature', function () {
    [$billable] = billableOnPlan();

    expect($billable->getFeatureUsage('no-such-feature'))->toBeNull();
});

it('returns null for a non-metered (boolean) feature', function () {
    [$billable, $plan] = billableOnPlan();
    grantPlanFeature($plan, 'priority', 'boolean', '1');

    expect($billable->getFeatureUsage('priority'))->toBeNull();
});

it('reports a granted unlimited feature with null limit/remaining, never 0', function () {
    [$billable, $plan] = billableOnPlan();
    grantPlanFeature($plan, 'unlimited-credits', 'quota', null);

    $usage = $billable->getFeatureUsage('unlimited-credits');

    expect($usage)->not->toBeNull()
        ->and($usage['limit'])->toBeNull()
        ->and($usage['remaining'])->toBeNull()
        ->and($usage['used'])->toBe(0);
});

it('reports a granted metered feature with its limit', function () {
    [$billable, $plan] = billableOnPlan();
    grantPlanFeature($plan, 'metered-credits', 'quota', '1000');

    $usage = $billable->getFeatureUsage('metered-credits');

    expect($usage)->not->toBeNull()
        ->and($usage['limit'])->toBe(1000.0)
        ->and($usage['used'])->toBe(0)
        ->and($usage['remaining'])->toBe(1000.0);
});
