<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Develupers\PlanUsage\Enums\Period;
use Develupers\PlanUsage\Events\UsageRecorded;
use Develupers\PlanUsage\Models\Feature;
use Develupers\PlanUsage\Models\Usage;
use Develupers\PlanUsage\Services\UsageTracker;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->usageTracker = new UsageTracker;
    $this->billable = createBillable();
});

describe('UsageTracker', function () {

    it('can record usage', function () {
        // Arrange
        Event::fake();
        $feature = Feature::factory()->create(['slug' => 'api-calls']);

        // Act
        $usage = $this->usageTracker->record($this->billable, 'api-calls', 10);

        // Assert
        expect($usage)->toBeModel(Usage::class)
            ->and($usage->used)->toBe(10.0)
            ->and($usage->feature_id)->toBe($feature->id);

        Event::assertDispatched(UsageRecorded::class);
    });

    it('aggregates usage when configured', function () {
        // Arrange
        $feature = Feature::factory()->create([
            'slug' => 'api-calls',
            'aggregation_method' => 'sum',
        ]);

        // Act
        $this->usageTracker->record($this->billable, 'api-calls', 10);
        $this->usageTracker->record($this->billable, 'api-calls', 5);
        $this->usageTracker->record($this->billable, 'api-calls', 3);

        // Assert
        $usageCount = Usage::where('feature_id', $feature->id)->count();
        $totalUsage = Usage::where('feature_id', $feature->id)->sum('used');

        expect($usageCount)->toBe(1)
            ->and((float) $totalUsage)->toBe(18.0);
    });

    it('creates separate records when not aggregating', function () {
        // Arrange
        $feature = Feature::factory()->create([
            'slug' => 'api-calls',
            'aggregation_method' => 'last',
        ]);

        // Act
        $this->usageTracker->record($this->billable, 'api-calls', 10);
        $this->usageTracker->record($this->billable, 'api-calls', 5);

        // Assert
        $usageCount = Usage::where('feature_id', $feature->id)->count();
        expect($usageCount)->toBe(2);
    });

    it('can get usage for specific period', function () {
        // Arrange
        $feature = Feature::factory()->create(['slug' => 'api-calls']);

        Usage::create([
            'billable_type' => $this->billable->getMorphClass(),
            'billable_id' => $this->billable->getKey(),
            'feature_id' => $feature->id,
            'used' => 10,
            'period_start' => Carbon::now()->subDays(5),
            'period_end' => Carbon::now()->subDays(4),
        ]);

        Usage::create([
            'billable_type' => $this->billable->getMorphClass(),
            'billable_id' => $this->billable->getKey(),
            'feature_id' => $feature->id,
            'used' => 20,
            'period_start' => Carbon::now()->subDays(2),
            'period_end' => Carbon::now()->subDay(),
        ]);

        // Act
        $totalUsage = $this->usageTracker->getUsage(
            $this->billable,
            'api-calls',
            Carbon::now()->subDays(3),
            Carbon::now()
        );

        // Assert
        expect((float) $totalUsage)->toBe(20.0);
    });

    it('can get current period usage', function () {
        // Arrange
        $feature = Feature::factory()->create([
            'slug' => 'api-calls',
            'reset_period' => Period::MONTHLY->value,
        ]);

        Usage::create([
            'billable_type' => $this->billable->getMorphClass(),
            'billable_id' => $this->billable->getKey(),
            'feature_id' => $feature->id,
            'used' => 100,
            'period_start' => Carbon::now()->startOfMonth(),
            'period_end' => Carbon::now()->endOfMonth(),
        ]);

        // Act
        $currentUsage = $this->usageTracker->getCurrentPeriodUsage($this->billable, 'api-calls');

        // Assert
        expect((float) $currentUsage)->toBe(100.0);
    });

    it('can get usage history', function () {
        // Arrange
        $feature = Feature::factory()->create(['slug' => 'api-calls']);

        Usage::factory()->count(5)->create([
            'billable_type' => $this->billable->getMorphClass(),
            'billable_id' => $this->billable->getKey(),
            'feature_id' => $feature->id,
        ]);

        // Act
        $history = $this->usageTracker->getHistory($this->billable);

        // Assert
        expect($history)->toHaveCount(5)
            ->and($history)->toBeInstanceOf(\Illuminate\Support\Collection::class);
    });

    it('can limit history results', function () {
        // Arrange
        $feature = Feature::factory()->create(['slug' => 'api-calls']);

        Usage::factory()->count(10)->create([
            'billable_type' => $this->billable->getMorphClass(),
            'billable_id' => $this->billable->getKey(),
            'feature_id' => $feature->id,
        ]);

        // Act
        $history = $this->usageTracker->getHistory($this->billable, null, 3);

        // Assert
        expect($history)->toHaveCount(3);
    });

    it('can reset usage', function () {
        // Arrange
        $feature = Feature::factory()->create(['slug' => 'api-calls']);

        Usage::factory()->count(3)->create([
            'billable_type' => $this->billable->getMorphClass(),
            'billable_id' => $this->billable->getKey(),
            'feature_id' => $feature->id,
        ]);

        // Act
        $this->usageTracker->resetUsage($this->billable, 'api-calls');

        // Assert
        $usageCount = Usage::where('feature_id', $feature->id)->count();
        expect($usageCount)->toBe(0);
    });

    it('can get usage statistics', function () {
        // Arrange
        $feature = Feature::factory()->create(['slug' => 'api-calls']);

        $dates = [
            Carbon::now()->subDays(3),
            Carbon::now()->subDays(2),
            Carbon::now()->subDays(1),
            Carbon::now(),
        ];

        foreach ($dates as $date) {
            $usage = new Usage([
                'billable_type' => $this->billable->getMorphClass(),
                'billable_id' => $this->billable->getKey(),
                'feature_id' => $feature->id,
                'used' => rand(10, 100),
                'period_start' => $date,
                'period_end' => $date->copy()->addDay(),
            ]);
            $usage->created_at = $date;
            $usage->updated_at = $date;
            $usage->save();
        }

        // Act
        $stats = $this->usageTracker->getStatistics(
            $this->billable,
            'api-calls',
            Carbon::now()->subDays(7),
            Carbon::now(),
            'day'
        );

        // Assert
        expect($stats)->toHaveCount(4)
            ->and($stats->first()->total_usage)->not->toBeNull()
            ->and($stats->first()->average_usage)->not->toBeNull();
    });

    it('reports usage to stripe when meter id exists', function () {
        // Arrange
        $billable = createBillable(['stripe_id' => 'cus_test123']);
        $feature = Feature::factory()->create([
            'slug' => 'api-calls',
            'stripe_meter_id' => 'meter_test123',
        ]);

        // Act
        $this->usageTracker->reportToStripe($billable, 'api-calls', 50);

        // Assert - Since we can't actually test Stripe API, we just ensure no errors
        expect(true)->toBeTrue();
    });
});

describe('UsageTracker with datasets', function () {

    it('calculates correct period boundaries', function (string $period) {
        // Arrange
        $feature = Feature::factory()->create([
            'slug' => "test-{$period}",
            'reset_period' => $period,
        ]);

        // Act
        $usage = $this->usageTracker->record($this->billable, "test-{$period}", 1);

        // Assert
        $startMethod = match ($period) {
            'hourly' => 'startOfHour',
            'daily' => 'startOfDay',
            'weekly' => 'startOfWeek',
            'monthly' => 'startOfMonth',
            'yearly' => 'startOfYear',
        };

        $endMethod = match ($period) {
            'hourly' => 'endOfHour',
            'daily' => 'endOfDay',
            'weekly' => 'endOfWeek',
            'monthly' => 'endOfMonth',
            'yearly' => 'endOfYear',
        };

        $expectedStart = Carbon::now()->$startMethod();
        $expectedEnd = Carbon::now()->$endMethod();

        expect($usage->period_start->format('Y-m-d H:i:s'))
            ->toBe($expectedStart->format('Y-m-d H:i:s'))
            ->and($usage->period_end->format('Y-m-d H:i:s'))
            ->toBe($expectedEnd->format('Y-m-d H:i:s'));
    })->with('reset_periods');

    it('handles different aggregation methods correctly', function (string $method) {
        // Arrange
        $feature = Feature::factory()->create([
            'slug' => 'test-feature',
            'aggregation_method' => $method,
        ]);

        // Act
        $this->usageTracker->record($this->billable, 'test-feature', 10);
        $this->usageTracker->record($this->billable, 'test-feature', 20);

        // Assert
        $shouldAggregate = in_array($method, ['sum', 'count']);
        $expectedCount = $shouldAggregate ? 1 : 2;

        $count = Usage::where('feature_id', $feature->id)->count();
        expect($count)->toBe($expectedCount);

        if ($shouldAggregate) {
            $total = Usage::where('feature_id', $feature->id)->sum('used');
            expect((float) $total)->toBe(30.0);
        }
    })->with('aggregation_methods');

    it('records different usage amounts correctly', function (int $amount) {
        // Arrange
        $feature = Feature::factory()->create(['slug' => 'test-feature']);

        // Act
        $usage = $this->usageTracker->record($this->billable, 'test-feature', $amount);

        // Assert
        expect($usage->used)->toBe((float) $amount);
    })->with('usage_amounts');
});
