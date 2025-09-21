<?php

declare(strict_types=1);

use Develupers\PlanUsage\Enums\Interval;
use Develupers\PlanUsage\Models\Feature;
use Develupers\PlanUsage\Models\Plan;
use Develupers\PlanUsage\Models\PlanFeature;
use Develupers\PlanUsage\Models\PlanPrice;

describe('Plan Model', function () {

    it('has proper fillable attributes', function () {
        $plan = new Plan;

        expect($plan->getFillable())
            ->toBeArray()
            ->and($plan->getFillable())->toContain(
                'name',
                'slug',
                'display_name',
                'description',
                'stripe_product_id',
                'trial_days',
                'sort_order',
                'is_active',
                'type',
                'metadata'
            );
    });

    it('casts attributes correctly', function () {
        $plan = Plan::factory()->create([
            'trial_days' => '14',
            'is_active' => 1,
            'metadata' => ['tier' => 'pro'],
        ]);

        expect($plan->trial_days)->toBeInt()
            ->and($plan->is_active)->toBeBool()
            ->and($plan->metadata)->toBeArray();
    });

    it('has prices relationship with default variant', function () {
        $plan = Plan::factory()->create();

        expect($plan->prices)->not->toBeEmpty()
            ->and($plan->defaultPrice)->not->toBeNull()
            ->and($plan->defaultPrice->is_default)->toBeTrue();
    });

    it('returns price by interval helpers', function () {
        $plan = Plan::factory()->create();

        $monthly = PlanPrice::factory()->default()->monthly()->create([
            'plan_id' => $plan->id,
            'price' => 10,
        ]);

        $yearly = PlanPrice::factory()->yearly()->create([
            'plan_id' => $plan->id,
            'price' => 100,
            'is_default' => false,
        ]);

        expect($plan->getMonthlyPrice()?->id)->toBe($monthly->id)
            ->and($plan->getYearlyPrice()?->id)->toBe($yearly->id)
            ->and($plan->getPriceByInterval(Interval::YEAR)?->id)->toBe($yearly->id);
    });

    it('finds plan by Stripe price id', function () {
        $plan = Plan::factory()->create();
        $price = PlanPrice::factory()->default()->monthly()->create([
            'plan_id' => $plan->id,
            'stripe_price_id' => 'price_test_123',
        ]);

        $found = Plan::findByStripePriceId('price_test_123');

        expect($found)->not->toBeNull()
            ->and($found->id)->toBe($plan->id)
            ->and($found->defaultPrice->id)->toBe($price->id);
    });

    it('determines if plan is free based on default price', function () {
        $freePlan = Plan::factory()->create();
        PlanPrice::factory()->default()->monthly()->create([
            'plan_id' => $freePlan->id,
            'price' => 0,
        ]);

        $paidPlan = Plan::factory()->create();
        PlanPrice::factory()->default()->monthly()->create([
            'plan_id' => $paidPlan->id,
            'price' => 25,
        ]);

        expect($freePlan->isFree())->toBeTrue()
            ->and($paidPlan->isFree())->toBeFalse();
    });

    it('exposes feature relationships', function () {
        $plan = Plan::factory()->create();
        $features = Feature::factory()->count(2)->create();

        foreach ($features as $feature) {
            PlanFeature::create([
                'plan_id' => $plan->id,
                'feature_id' => $feature->id,
                'value' => '5',
            ]);
        }

        expect($plan->features)->toHaveCount(2)
            ->and($plan->planFeatures)->toHaveCount(2);
    });

    it('gets feature value with casting', function () {
        $plan = Plan::factory()->create();
        $feature = Feature::factory()->create([
            'slug' => 'limit-feature',
            'type' => 'limit',
        ]);

        PlanFeature::create([
            'plan_id' => $plan->id,
            'feature_id' => $feature->id,
            'value' => '250',
        ]);

        expect($plan->getFeatureValue('limit-feature'))->toBe(250.0);
    });

    it('scopes to active plans only', function () {
        Plan::factory()->count(2)->create(['is_active' => true]);
        Plan::factory()->create(['is_active' => false]);

        expect(Plan::active()->count())->toBe(2);
    });
});

describe('Plan type helpers', function () {

    it('has correct type constants and helpers', function () {
        expect(Plan::TYPE_PUBLIC)->toBe('public')
            ->and(Plan::TYPE_LEGACY)->toBe('legacy')
            ->and(Plan::TYPE_PRIVATE)->toBe('private');

        $publicPlan = Plan::factory()->create(['type' => 'public']);
        $legacyPlan = Plan::factory()->legacy()->create();
        $privatePlan = Plan::factory()->private()->create();

        expect($publicPlan->isPublic())->toBeTrue()
            ->and($legacyPlan->isLegacy())->toBeTrue()
            ->and($privatePlan->isPrivate())->toBeTrue();
    });

    it('scopes by plan type', function () {
        Plan::factory()->count(3)->create(['type' => 'public']);
        Plan::factory()->count(2)->legacy()->create();

        expect(Plan::ofType('public')->count())->toBe(3)
            ->and(Plan::ofType('legacy')->count())->toBe(2);
    });
});
