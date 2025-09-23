<?php

declare(strict_types=1);

use Develupers\PlanUsage\Models\Feature;
use Develupers\PlanUsage\Models\Plan;
use Develupers\PlanUsage\Models\PlanFeature;
use Develupers\PlanUsage\Services\PlanManager;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->planManager = new PlanManager;
});

describe('PlanManager', function () {

    it('can get all plans', function () {
        Plan::factory()->count(3)->create();

        $plans = $this->planManager->getAllPlans();

        expect($plans)->toHaveCount(3)
            ->and($plans)->toBeInstanceOf(\Illuminate\Support\Collection::class);
    });

    it('caches plans for performance', function () {
        Plan::factory()->count(2)->create();

        $plans1 = $this->planManager->getAllPlans();
        $plans2 = $this->planManager->getAllPlans();

        expect(Cache::has('plan-usage.plans'))->toBeTrue()
            ->and($plans1->toArray())->toBe($plans2->toArray());
    });

    it('can find plan by id', function () {
        $plan = Plan::factory()->create()->refresh();

        $foundPlan = $this->planManager->findPlan($plan->id);

        expect($foundPlan)->not->toBeNull()
            ->and($foundPlan->id)->toBe($plan->id);
    });

    it('can find plan by stripe price id', function () {
        $plan = Plan::factory()->create()->refresh();
        // Update the existing default price created by the factory
        $plan->defaultPrice->update(['stripe_price_id' => 'price_test123']);
        $price = $plan->defaultPrice;

        $foundPlan = $this->planManager->findPlan('price_test123');

        expect($foundPlan)->not->toBeNull()
            ->and($foundPlan->id)->toBe($plan->id)
            ->and($foundPlan->defaultPrice->id)->toBe($price->id);
    });

    it('returns null for non-existent plan', function () {
        $foundPlan = $this->planManager->findPlan(999);

        expect($foundPlan)->toBeNull();
    });

    it('can get plan features', function () {
        $plan = Plan::factory()->create()->refresh();
        $feature = Feature::factory()->create();

        PlanFeature::create([
            'plan_id' => $plan->id,
            'feature_id' => $feature->id,
            'value' => '100',
        ]);

        $features = $this->planManager->getPlanFeatures($plan->id);

        expect($features)->toHaveCount(1)
            ->and($features->first()->feature_id)->toBe($feature->id);
    });

    it('can check if plan has feature', function () {
        $plan = Plan::factory()->create()->refresh();
        $feature = Feature::factory()->create(['slug' => 'test-feature']);

        PlanFeature::create([
            'plan_id' => $plan->id,
            'feature_id' => $feature->id,
            'value' => '1',
        ]);

        expect($this->planManager->planHasFeature($plan->id, 'test-feature'))->toBeTrue()
            ->and($this->planManager->planHasFeature($plan->id, 'non-existent'))->toBeFalse();
    });

    it('can get feature value with correct type casting', function () {
        $plan = Plan::factory()->create()->refresh();

        $booleanFeature = Feature::factory()->create([
            'slug' => 'boolean-feature',
            'type' => 'boolean',
        ]);

        PlanFeature::create([
            'plan_id' => $plan->id,
            'feature_id' => $booleanFeature->id,
            'value' => '1',
        ]);

        $limitFeature = Feature::factory()->create([
            'slug' => 'limit-feature',
            'type' => 'limit',
        ]);

        PlanFeature::create([
            'plan_id' => $plan->id,
            'feature_id' => $limitFeature->id,
            'value' => '500',
        ]);

        $booleanValue = $this->planManager->getFeatureValue($plan->id, 'boolean-feature');
        $limitValue = $this->planManager->getFeatureValue($plan->id, 'limit-feature');
        $nullValue = $this->planManager->getFeatureValue($plan->id, 'non-existent');

        expect($booleanValue)->toBeTrue()
            ->and($limitValue)->toBe(500.0)
            ->and($nullValue)->toBeNull();
    });

    it('can compare two plans', function () {
        $plan1 = Plan::factory()->create()->refresh();
        $plan2 = Plan::factory()->create()->refresh();

        $feature = Feature::factory()->create([
            'slug' => 'test-feature',
            'type' => 'limit',
        ]);

        PlanFeature::create([
            'plan_id' => $plan1->id,
            'feature_id' => $feature->id,
            'value' => '100',
        ]);

        PlanFeature::create([
            'plan_id' => $plan2->id,
            'feature_id' => $feature->id,
            'value' => '200',
        ]);

        $comparison = $this->planManager->comparePlans($plan1->id, $plan2->id);

        expect($comparison)->toHaveKey('test-feature')
            ->and($comparison['test-feature']['difference'])->toBe(100.0);
    });

    it('can clear cache for specific plan', function () {
        $plan = Plan::factory()->create()->refresh();
        $this->planManager->findPlan($plan->id);

        expect(Cache::has("plan-usage.plan.{$plan->id}"))->toBeTrue();

        $this->planManager->clearCache($plan->id);

        expect(Cache::has("plan-usage.plan.{$plan->id}"))->toBeFalse();
    });

    it('can clear all cache', function () {
        $this->planManager->getAllPlans();

        expect(Cache::has('plan-usage.plans'))->toBeTrue();

        $this->planManager->clearCache();

        expect(Cache::has('plan-usage.plans'))->toBeFalse();
    });
});
