<?php

use Develupers\PlanUsage\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

uses(TestCase::class)->in('Feature', 'Unit');
uses(RefreshDatabase::class)->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

expect()->extend('toBeModel', function (string $model) {
    return $this->toBeInstanceOf($model);
});

/*
|--------------------------------------------------------------------------
| Helper Functions
|--------------------------------------------------------------------------
*/

function createBillable(array $attributes = []): \Illuminate\Database\Eloquent\Model
{
    return new class($attributes) extends \Illuminate\Database\Eloquent\Model
    {
        public $plan_id;

        public $plan_changed_at;

        public $stripe_id;

        public $attributes = [];

        protected $table = 'test_billables';

        protected $fillable = ['*'];

        public function __construct(array $attributes = [])
        {
            parent::__construct();
            $this->plan_id = $attributes['plan_id'] ?? null;
            $this->stripe_id = $attributes['stripe_id'] ?? 'cus_'.uniqid();
            $this->attributes = array_merge($attributes, ['id' => $attributes['id'] ?? rand(100000, 999999)]);
            $this->setAttribute('id', $this->attributes['id']);
        }

        public function getMorphClass(): string
        {
            return $this->attributes['morph_class'] ?? 'App\\Models\\Account';
        }

        public function getKey(): int
        {
            return $this->attributes['id'] ?? 1;
        }

        public function save(array $options = []): bool
        {
            $this->plan_changed_at = now();
            // Ensure plan_id is accessible after save
            if (property_exists($this, 'plan_id')) {
                $this->attributes['plan_id'] = $this->plan_id;
            }

            return true;
        }

        public function reportUsage(string $meterId, int $quantity): void
        {
            // Mock Stripe usage reporting
        }
    };
}

/*
|--------------------------------------------------------------------------
| Datasets
|--------------------------------------------------------------------------
*/

dataset('feature_types', [
    'boolean' => ['boolean'],
    'limit' => ['limit'],
    'quota' => ['quota'],
]);

dataset('reset_periods', [
    'hourly' => [\Develupers\PlanUsage\Enums\Period::HOUR->value],
    'daily' => [\Develupers\PlanUsage\Enums\Period::DAY->value],
    'weekly' => [\Develupers\PlanUsage\Enums\Period::WEEK->value],
    'monthly' => [\Develupers\PlanUsage\Enums\Period::MONTH->value],
    'yearly' => [\Develupers\PlanUsage\Enums\Period::YEAR->value],
]);

dataset('aggregation_methods', [
    'sum' => ['sum'],
    'count' => ['count'],
    'max' => ['max'],
    'last' => ['last'],
]);

dataset('plan_intervals', [
    'monthly' => [\Develupers\PlanUsage\Enums\Interval::MONTH->value],
    'yearly' => [\Develupers\PlanUsage\Enums\Interval::YEAR->value],
]);

dataset('usage_amounts', [
    'small' => [1],
    'medium' => [50],
    'large' => [100],
    'very large' => [1000],
]);

dataset('quota_limits', [
    'small limit' => [100],
    'medium limit' => [1000],
    'large limit' => [10000],
    'unlimited' => [null],
]);
