<?php

declare(strict_types=1);

use Develupers\PlanUsage\Enums\Period;
use Develupers\PlanUsage\Events\QuotaExceeded;
use Develupers\PlanUsage\Events\QuotaWarning;
use Develupers\PlanUsage\Models\Feature;
use Develupers\PlanUsage\Models\Plan;
use Develupers\PlanUsage\Models\PlanFeature;
use Develupers\PlanUsage\Models\Quota;
use Develupers\PlanUsage\Services\QuotaEnforcer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->quotaEnforcer = new QuotaEnforcer;
    $this->billable = createBillable();
});

describe('QuotaEnforcer', function () {

    it('can check if billable can use feature', function () {
        // Arrange
        $billable = createBillable();
        $billable->plan_id = null;
        $feature = Feature::factory()->create(['slug' => 'api-calls']);

        Quota::create([
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => $billable->getKey(),
            'feature_id' => $feature->id,
            'limit' => 100,
            'used' => 50,
        ]);

        // Act & Assert
        expect($this->quotaEnforcer->canUse($billable, 'api-calls', 30))->toBeTrue()
            ->and($this->quotaEnforcer->canUse($billable, 'api-calls', 60))->toBeFalse();
    });

    it('allows unlimited usage when limit is null', function () {
        // Arrange
        $billable = createBillable();
        $billable->plan_id = null;
        $feature = Feature::factory()->create(['slug' => 'api-calls']);

        Quota::create([
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => $billable->getKey(),
            'feature_id' => $feature->id,
            'limit' => null,
            'used' => 999999,
        ]);

        // Act & Assert
        expect($this->quotaEnforcer->canUse($billable, 'api-calls', 10000))->toBeTrue();
    });

    it('enforces quota and dispatches exceeded event', function () {
        // Arrange
        Event::fake();
        $feature = Feature::factory()->create(['slug' => 'api-calls']);

        Quota::create([
            'billable_type' => $this->billable->getMorphClass(),
            'billable_id' => $this->billable->getKey(),
            'feature_id' => $feature->id,
            'limit' => 100,
            'used' => 95,
        ]);

        // Act
        $enforced = $this->quotaEnforcer->enforce($this->billable, 'api-calls', 10);

        // Assert
        expect($enforced)->toBeFalse();
        Event::assertDispatched(QuotaExceeded::class);
    });

    it('creates quota if not exists', function () {
        // Arrange
        $feature = Feature::factory()->quota()->create(['slug' => 'api-calls']);
        $plan = Plan::factory()->create();
        $this->billable->plan_id = $plan->id;

        PlanFeature::create([
            'plan_id' => $plan->id,
            'feature_id' => $feature->id,
            'value' => '100',
        ]);

        // Act
        $quota = $this->quotaEnforcer->getOrCreateQuota($this->billable, 'api-calls');

        // Assert
        expect($quota)->toBeModel(Quota::class)
            ->and($quota->limit)->toBe(100.0)
            ->and($quota->used)->toBe(0.0);
    });

    it('increments quota usage', function () {
        // Arrange
        $billable = createBillable();
        $billable->plan_id = null; // Explicitly set to null
        $feature = Feature::factory()->create(['slug' => 'api-calls']);

        Quota::create([
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => $billable->getKey(),
            'feature_id' => $feature->id,
            'limit' => 100,
            'used' => 10,
        ]);

        // Act
        $this->quotaEnforcer->increment($billable, 'api-calls', 5);

        // Assert
        $quota = Quota::where('feature_id', $feature->id)->first();
        expect($quota->used)->toBe(15.0);
    });

    it('decrements quota usage', function () {
        // Arrange
        $billable = createBillable();
        $billable->plan_id = null;
        $feature = Feature::factory()->create(['slug' => 'api-calls']);

        Quota::create([
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => $billable->getKey(),
            'feature_id' => $feature->id,
            'limit' => 100,
            'used' => 50,
        ]);

        // Act
        $this->quotaEnforcer->decrement($billable, 'api-calls', 20);

        // Assert
        $quota = Quota::where('feature_id', $feature->id)->first();
        expect($quota->used)->toBe(30.0);
    });

    it('prevents negative usage when decrementing', function () {
        // Arrange
        $billable = createBillable();
        $billable->plan_id = null;
        $feature = Feature::factory()->create(['slug' => 'api-calls']);

        Quota::create([
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => $billable->getKey(),
            'feature_id' => $feature->id,
            'limit' => 100,
            'used' => 10,
        ]);

        // Act
        $this->quotaEnforcer->decrement($billable, 'api-calls', 20);

        // Assert
        $quota = Quota::where('feature_id', $feature->id)->first();
        expect($quota->used)->toBe(0.0);
    });

    it('resets quota', function () {
        // Arrange
        $billable = createBillable();
        $billable->plan_id = null;
        $feature = Feature::factory()->create([
            'slug' => 'api-calls',
            'reset_period' => Period::MONTH->value,
        ]);

        $quota = Quota::create([
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => $billable->getKey(),
            'feature_id' => $feature->id,
            'limit' => 100,
            'used' => 75,
        ]);

        // Act
        $this->quotaEnforcer->reset($billable, 'api-calls');

        // Assert
        $quota->refresh();
        expect($quota->used)->toBe(0.0);
    });

    it('calculates remaining quota correctly', function () {
        // Arrange
        $billable = createBillable();
        $billable->plan_id = null;
        $feature = Feature::factory()->create(['slug' => 'api-calls']);

        Quota::create([
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => $billable->getKey(),
            'feature_id' => $feature->id,
            'limit' => 100,
            'used' => 75,
        ]);

        // Act
        $remaining = $this->quotaEnforcer->getRemaining($billable, 'api-calls');

        // Assert
        expect($remaining)->toBe(25.0);
    });

    it('calculates usage percentage', function () {
        // Arrange
        $billable = createBillable();
        $billable->plan_id = null;
        $feature = Feature::factory()->create(['slug' => 'api-calls']);

        Quota::create([
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => $billable->getKey(),
            'feature_id' => $feature->id,
            'limit' => 100,
            'used' => 75,
        ]);

        // Act
        $percentage = $this->quotaEnforcer->getUsagePercentage($billable, 'api-calls');

        // Assert
        expect($percentage)->toBe(75.0);
    });

    it('handles zero limit correctly for usage percentage and limit reached', function () {
        // Arrange
        $billable = createBillable();
        $billable->plan_id = null;
        $feature = Feature::factory()->create(['slug' => 'zero-limit-feature']);

        // Zero limit with no usage — limit is already reached, percentage is 100%
        $quota = Quota::create([
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => $billable->getKey(),
            'feature_id' => $feature->id,
            'limit' => 0,
            'used' => 0,
        ]);

        expect($this->quotaEnforcer->getUsagePercentage($billable, 'zero-limit-feature'))->toBe(100.0)
            ->and($quota->usagePercentage())->toBe(100.0)
            ->and($quota->isLimitReached())->toBeTrue();

        // Zero limit with usage — still at limit
        $quota->update(['used' => 5]);

        expect($this->quotaEnforcer->getUsagePercentage($billable, 'zero-limit-feature'))->toBe(100.0)
            ->and($quota->fresh()->usagePercentage())->toBe(100.0)
            ->and($quota->fresh()->isLimitReached())->toBeTrue();
    });

    it('returns null usage percentage for unlimited features', function () {
        // Arrange
        $billable = createBillable();
        $billable->plan_id = null;
        $feature = Feature::factory()->create(['slug' => 'unlimited-feature']);

        Quota::create([
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => $billable->getKey(),
            'feature_id' => $feature->id,
            'limit' => null,
            'used' => 999,
        ]);

        // Act & Assert
        expect($this->quotaEnforcer->getUsagePercentage($billable, 'unlimited-feature'))->toBeNull();
    });

    it('rejects negative amounts', function () {
        $billable = createBillable();

        expect(fn () => $this->quotaEnforcer->canUse($billable, 'any-feature', -1))
            ->toThrow(InvalidArgumentException::class, 'Amount must be a positive number.')
            ->and(fn () => $this->quotaEnforcer->enforce($billable, 'any-feature', -5))
            ->toThrow(InvalidArgumentException::class)
            ->and(fn () => $this->quotaEnforcer->increment($billable, 'any-feature', -10))
            ->toThrow(InvalidArgumentException::class)
            ->and(fn () => $this->quotaEnforcer->decrement($billable, 'any-feature', -1))
            ->toThrow(InvalidArgumentException::class);
    });

    it('rejects zero amounts', function () {
        $billable = createBillable();

        expect(fn () => $this->quotaEnforcer->canUse($billable, 'any-feature', 0))
            ->toThrow(InvalidArgumentException::class, 'Amount must be a positive number.')
            ->and(fn () => $this->quotaEnforcer->enforce($billable, 'any-feature', 0))
            ->toThrow(InvalidArgumentException::class)
            ->and(fn () => $this->quotaEnforcer->increment($billable, 'any-feature', 0))
            ->toThrow(InvalidArgumentException::class)
            ->and(fn () => $this->quotaEnforcer->decrement($billable, 'any-feature', 0))
            ->toThrow(InvalidArgumentException::class);
    });

    it('dispatches warning event at threshold', function () {
        // Arrange
        Event::fake();
        config(['plan-usage.quotas.warning_threshold' => 80]);

        $billable = createBillable();
        $billable->plan_id = null;
        $feature = Feature::factory()->create(['slug' => 'api-calls']);

        Quota::create([
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => $billable->getKey(),
            'feature_id' => $feature->id,
            'limit' => 100,
            'used' => 75,
        ]);

        // Act
        $this->quotaEnforcer->increment($billable, 'api-calls', 10);

        // Assert
        Event::assertDispatched(QuotaWarning::class, function ($event) {
            return $event->threshold >= 80 && $event->threshold < 100;
        });
    });

    it('auto resets expired quotas', function () {
        // Arrange
        $billable = createBillable();
        $billable->plan_id = null;
        $feature = Feature::factory()->create([
            'slug' => 'api-calls',
            'reset_period' => Period::MONTH->value,
        ]);

        $quota = Quota::create([
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => $billable->getKey(),
            'feature_id' => $feature->id,
            'limit' => 100,
            'used' => 75,
            'reset_at' => Carbon::now()->subHour(),
        ]);

        // Act
        $this->quotaEnforcer->increment($billable, 'api-calls', 5);

        // Assert
        $quota->refresh();
        expect($quota->used)->toBe(5.0)
            ->and($quota->reset_at)->toBeInstanceOf(Carbon::class)
            ->and($quota->reset_at->isFuture())->toBeTrue();
    });

    it('caches quotas for performance', function () {
        // Arrange
        $feature = Feature::factory()->create(['slug' => 'api-calls']);

        Quota::create([
            'billable_type' => $this->billable->getMorphClass(),
            'billable_id' => $this->billable->getKey(),
            'feature_id' => $feature->id,
            'limit' => 100,
            'used' => 50,
        ]);

        // Act
        $quotas1 = $this->quotaEnforcer->getAllQuotas($this->billable);
        $quotas2 = $this->quotaEnforcer->getAllQuotas($this->billable);

        // Assert
        $cacheKey = "plan-usage.billable.{$this->billable->getMorphClass()}.{$this->billable->getKey()}.quotas";
        expect(Cache::has($cacheKey))->toBeTrue()
            ->and($quotas1->toArray())->toBe($quotas2->toArray());
    });

    it('syncs quotas with plan changes', function () {
        // Arrange
        $feature = Feature::factory()->create(['slug' => 'api-calls']);
        $plan = Plan::factory()->create();
        $this->billable->plan_id = $plan->id;

        PlanFeature::create([
            'plan_id' => $plan->id,
            'feature_id' => $feature->id,
            'value' => '200',
        ]);

        Quota::create([
            'billable_type' => $this->billable->getMorphClass(),
            'billable_id' => $this->billable->getKey(),
            'feature_id' => $feature->id,
            'limit' => 100,
            'used' => 50,
        ]);

        // Act
        $this->quotaEnforcer->syncWithPlan($this->billable);

        // Assert
        $quota = Quota::where('feature_id', $feature->id)->first();
        expect($quota->limit)->toBe(200.0);
    });
});

describe('QuotaEnforcer trigger_once', function () {

    it('fires warning event only once per threshold when trigger_once is true', function () {
        // Arrange
        Event::fake();
        config(['plan-usage.quota.trigger_once' => true]);
        config(['plan-usage.quota.warning_thresholds' => [80, 100]]);

        $billable = createBillable();
        $billable->plan_id = null;
        $feature = Feature::factory()->create(['slug' => 'api-calls']);

        Quota::create([
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => $billable->getKey(),
            'feature_id' => $feature->id,
            'limit' => 100,
            'used' => 79,
        ]);

        // Act — first increment crosses 80% threshold
        $this->quotaEnforcer->increment($billable, 'api-calls', 2);

        // Assert — event fired once
        Event::assertDispatchedTimes(QuotaWarning::class, 1);

        // Act — second increment still above 80%
        Event::fake();
        $this->quotaEnforcer->increment($billable, 'api-calls', 1);

        // Assert — event NOT fired again (deduplicated)
        Event::assertNotDispatched(QuotaWarning::class);
    });

    it('fires warning event every time when trigger_once is false', function () {
        // Arrange
        Event::fake();
        config(['plan-usage.quota.trigger_once' => false]);
        config(['plan-usage.quota.warning_thresholds' => [80]]);

        $billable = createBillable();
        $billable->plan_id = null;
        $feature = Feature::factory()->create(['slug' => 'api-calls']);

        Quota::create([
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => $billable->getKey(),
            'feature_id' => $feature->id,
            'limit' => 100,
            'used' => 80,
        ]);

        // Act — two increments both above threshold
        $this->quotaEnforcer->increment($billable, 'api-calls', 1);
        $this->quotaEnforcer->increment($billable, 'api-calls', 1);

        // Assert — event fired both times
        Event::assertDispatchedTimes(QuotaWarning::class, 2);
    });

    it('fires different thresholds independently when trigger_once is true', function () {
        // Arrange
        Event::fake();
        config(['plan-usage.quota.trigger_once' => true]);
        config(['plan-usage.quota.warning_thresholds' => [80, 100]]);

        $billable = createBillable();
        $billable->plan_id = null;
        $feature = Feature::factory()->create(['slug' => 'api-calls']);

        Quota::create([
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => $billable->getKey(),
            'feature_id' => $feature->id,
            'limit' => 100,
            'used' => 79,
        ]);

        // Act — cross 80% threshold
        $this->quotaEnforcer->increment($billable, 'api-calls', 2);
        Event::assertDispatchedTimes(QuotaWarning::class, 1);

        // Act — cross 100% threshold
        Event::fake();
        $this->quotaEnforcer->increment($billable, 'api-calls', 19);

        // Assert — 100% threshold fires (different from 80%)
        Event::assertDispatchedTimes(QuotaWarning::class, 1);
    });

    it('fires exceeded event only once when trigger_once is true', function () {
        // Arrange
        Event::fake();
        config(['plan-usage.quota.trigger_once' => true]);

        $feature = Feature::factory()->create(['slug' => 'api-calls']);

        Quota::create([
            'billable_type' => $this->billable->getMorphClass(),
            'billable_id' => $this->billable->getKey(),
            'feature_id' => $feature->id,
            'limit' => 100,
            'used' => 99,
        ]);

        // Act — first enforce exceeds
        $this->quotaEnforcer->enforce($this->billable, 'api-calls', 5);
        Event::assertDispatchedTimes(QuotaExceeded::class, 1);

        // Act — second enforce also exceeds
        Event::fake();
        $this->quotaEnforcer->enforce($this->billable, 'api-calls', 5);

        // Assert — not fired again
        Event::assertNotDispatched(QuotaExceeded::class);
    });

    it('fires exceeded event every time when trigger_once is false', function () {
        // Arrange
        Event::fake();
        config(['plan-usage.quota.trigger_once' => false]);

        $feature = Feature::factory()->create(['slug' => 'api-calls']);

        Quota::create([
            'billable_type' => $this->billable->getMorphClass(),
            'billable_id' => $this->billable->getKey(),
            'feature_id' => $feature->id,
            'limit' => 100,
            'used' => 99,
        ]);

        // Act
        $this->quotaEnforcer->enforce($this->billable, 'api-calls', 5);
        $this->quotaEnforcer->enforce($this->billable, 'api-calls', 5);

        // Assert — fired both times
        Event::assertDispatchedTimes(QuotaExceeded::class, 2);
    });

    it('resets trigger_once cache when quota resets', function () {
        // Arrange
        Event::fake();
        config(['plan-usage.quota.trigger_once' => true]);
        config(['plan-usage.quota.warning_thresholds' => [80]]);

        $billable = createBillable();
        $billable->plan_id = null;
        $feature = Feature::factory()->create([
            'slug' => 'api-calls',
            'reset_period' => Period::MONTH->value,
        ]);

        Quota::create([
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => $billable->getKey(),
            'feature_id' => $feature->id,
            'limit' => 100,
            'used' => 79,
            'reset_at' => Carbon::now()->addMonth(),
        ]);

        // Act — first trigger at 80%
        $this->quotaEnforcer->increment($billable, 'api-calls', 2);
        Event::assertDispatchedTimes(QuotaWarning::class, 1);

        // Simulate cache expiry (quota reset)
        $cacheKey = "plan-usage.event.{$billable->getMorphClass()}.{$billable->getKey()}.api-calls.warning:80";
        Cache::forget($cacheKey);

        // Act — fires again after cache cleared
        Event::fake();
        $this->quotaEnforcer->increment($billable, 'api-calls', 1);
        Event::assertDispatchedTimes(QuotaWarning::class, 1);
    });
});

describe('QuotaEnforcer with datasets', function () {

    it('handles different quota limits correctly', function (?float $limit) {
        // Arrange
        $billable = createBillable();
        $billable->plan_id = null;
        $feature = Feature::factory()->create(['slug' => 'test-feature']);

        Quota::create([
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => $billable->getKey(),
            'feature_id' => $feature->id,
            'limit' => $limit,
            'used' => 50,
        ]);

        // Act & Assert
        if (is_null($limit)) {
            expect($this->quotaEnforcer->canUse($billable, 'test-feature', 9999))->toBeTrue();
            expect($this->quotaEnforcer->getRemaining($billable, 'test-feature'))->toBeNull();
        } else {
            $canUse = $this->quotaEnforcer->canUse($billable, 'test-feature', $limit - 50);
            expect($canUse)->toBeTrue();
            expect($this->quotaEnforcer->getRemaining($billable, 'test-feature'))->toBe($limit - 50);
        }
    })->with('quota_limits');

    it('resets quotas based on period', function (string $period) {
        // Arrange
        $billable = createBillable();
        $billable->plan_id = null;
        $feature = Feature::factory()->create([
            'slug' => "test-{$period}",
            'reset_period' => $period,
        ]);

        $quota = Quota::create([
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => $billable->getKey(),
            'feature_id' => $feature->id,
            'limit' => 100,
            'used' => 50,
            'reset_at' => Carbon::now()->subMinute(),
        ]);

        // Act
        $this->quotaEnforcer->increment($billable, "test-{$period}", 5);

        // Assert
        $quota->refresh();
        expect($quota->used)->toBe(5.0);

        $expectedResetTime = match ($period) {
            'hour' => Carbon::now()->addHour()->startOfHour(),
            'day' => Carbon::now()->addDay()->startOfDay(),
            'week' => Carbon::now()->addWeek()->startOfWeek(),
            'month' => Carbon::now()->addMonth()->startOfMonth(),
            'year' => Carbon::now()->addYear()->startOfYear(),
        };

        expect($quota->reset_at->format('Y-m-d'))->toBe($expectedResetTime->format('Y-m-d'));
    })->with('reset_periods');
});
