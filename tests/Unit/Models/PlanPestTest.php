<?php

declare(strict_types=1);

use Develupers\PlanUsage\Models\Feature;
use Develupers\PlanUsage\Models\Plan;
use Develupers\PlanUsage\Models\PlanFeature;

describe('Plan Model', function () {

    it('has proper fillable attributes', function () {
        $plan = new Plan;

        expect($plan->getFillable())->toContain(
            'name',
            'description',
            'stripe_product_id',
            'stripe_price_id',
            'price',
            'currency',
            'interval',
            'is_active',
            'metadata'
        );
    });

    it('casts attributes correctly', function () {
        $plan = Plan::factory()->create([
            'price' => '99.99',
            'is_active' => 1,
            'metadata' => ['key' => 'value'],
        ]);

        expect($plan->price)->toBeFloat()
            ->and($plan->is_active)->toBeBool()
            ->and($plan->metadata)->toBeArray();
    });

    it('has features relationship', function () {
        $plan = Plan::factory()->create();
        $features = Feature::factory()->count(3)->create();

        foreach ($features as $feature) {
            PlanFeature::create([
                'plan_id' => $plan->id,
                'feature_id' => $feature->id,
                'value' => '100',
            ]);
        }

        expect($plan->features)->toHaveCount(3)
            ->and($plan->features->first())->toBeModel(Feature::class);
    });

    it('has plan features relationship', function () {
        $plan = Plan::factory()->create();
        $feature = Feature::factory()->create();

        PlanFeature::create([
            'plan_id' => $plan->id,
            'feature_id' => $feature->id,
            'value' => '100',
        ]);

        expect($plan->planFeatures)->toHaveCount(1)
            ->and($plan->planFeatures->first())->toBeModel(PlanFeature::class);
    });

    it('can retrieve feature by slug', function () {
        $plan = Plan::factory()->create();
        $feature = Feature::factory()->create(['slug' => 'test-feature']);

        PlanFeature::create([
            'plan_id' => $plan->id,
            'feature_id' => $feature->id,
            'value' => '50',
        ]);

        $retrievedFeature = $plan->getFeature('test-feature');

        expect($retrievedFeature)->not->toBeNull()
            ->and($retrievedFeature->id)->toBe($feature->id);
    });

    it('returns null for non-existent feature', function () {
        $plan = Plan::factory()->create();

        expect($plan->getFeature('non-existent'))->toBeNull();
    });

    it('can get feature value', function () {
        $plan = Plan::factory()->create();
        $feature = Feature::factory()->create([
            'slug' => 'test-feature',
            'type' => 'limit',
        ]);

        PlanFeature::create([
            'plan_id' => $plan->id,
            'feature_id' => $feature->id,
            'value' => '250',
        ]);

        expect($plan->getFeatureValue('test-feature'))->toBe(250.0);
    });

    it('checks if plan has feature', function () {
        $plan = Plan::factory()->create();
        $feature = Feature::factory()->create(['slug' => 'included-feature']);

        PlanFeature::create([
            'plan_id' => $plan->id,
            'feature_id' => $feature->id,
            'value' => '1',
        ]);

        expect($plan->hasFeature('included-feature'))->toBeTrue()
            ->and($plan->hasFeature('excluded-feature'))->toBeFalse();
    });

    it('scopes to active plans', function () {
        Plan::factory()->count(3)->create(['is_active' => true]);
        Plan::factory()->count(2)->create(['is_active' => false]);

        $activePlans = Plan::active()->get();

        expect($activePlans)->toHaveCount(3)
            ->and($activePlans->every(fn ($plan) => $plan->is_active === true))->toBeTrue();
    });

    it('scopes to monthly plans', function () {
        Plan::factory()->count(2)->monthly()->create();
        Plan::factory()->count(3)->yearly()->create();

        $monthlyPlans = Plan::monthly()->get();

        expect($monthlyPlans)->toHaveCount(2)
            ->and($monthlyPlans->every(fn ($plan) => $plan->interval === 'monthly'))->toBeTrue();
    });

    it('scopes to yearly plans', function () {
        Plan::factory()->count(2)->monthly()->create();
        Plan::factory()->count(3)->yearly()->create();

        $yearlyPlans = Plan::yearly()->get();

        expect($yearlyPlans)->toHaveCount(3)
            ->and($yearlyPlans->every(fn ($plan) => $plan->interval === 'yearly'))->toBeTrue();
    });
});

describe('Plan Model with datasets', function () {

    it('handles different intervals correctly', function (string $interval) {
        $plan = Plan::factory()->create(['interval' => $interval]);

        expect($plan->interval)->toBe($interval)
            ->and($plan->isMonthly())->toBe($interval === 'monthly')
            ->and($plan->isYearly())->toBe($interval === 'yearly');
    })->with('plan_intervals');
});
