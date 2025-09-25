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

/**
 * Create a mock billable that satisfies Cashier requirements.
 */
function createMockBillable()
{
    $billable = Mockery::mock(\Develupers\PlanUsage\Contracts\Billable::class);
    $billable->shouldAllowMockingProtectedMethods();

    // Add the methods that the actions check for
    $billable->shouldReceive('subscription')->byDefault();
    $billable->shouldReceive('subscribed')->byDefault();
    $billable->shouldReceive('createOrGetStripeCustomer')->byDefault();
    $billable->shouldReceive('newSubscription')->byDefault();
    $billable->shouldReceive('subscriptions')->byDefault();
    $billable->shouldReceive('checkout')->byDefault();

    return $billable;
}

function createBillable(array $attributes = []): \Develupers\PlanUsage\Contracts\Billable
{
    return new class($attributes) extends \Illuminate\Database\Eloquent\Model implements \Develupers\PlanUsage\Contracts\Billable
    {
        use \Develupers\PlanUsage\Traits\HasPlanFeatures;
        use \Laravel\Cashier\Billable;

        public $plan_id;

        public $plan_price_id;

        public $plan_changed_at;

        public $stripe_id;

        public $pm_type;

        public $pm_last_four;

        public $trial_ends_at;

        public $attributes = [];

        protected $table = 'test_billables';

        protected $fillable = ['*'];

        public function __construct(array $attributes = [])
        {
            parent::__construct();
            $this->plan_id = $attributes['plan_id'] ?? null;
            $this->plan_price_id = $attributes['plan_price_id'] ?? null;
            $this->stripe_id = $attributes['stripe_id'] ?? 'cus_'.uniqid();
            $this->pm_type = $attributes['pm_type'] ?? null;
            $this->pm_last_four = $attributes['pm_last_four'] ?? null;
            $this->trial_ends_at = $attributes['trial_ends_at'] ?? null;
            $this->attributes = array_merge($attributes, ['id' => $attributes['id'] ?? rand(100000, 999999)]);
            $this->setAttribute('id', $this->attributes['id']);
        }

        public function getMorphClass(): string
        {
            // Use configured billable model or a generic test default
            $defaultClass = config('plan-usage.models.billable')
                ?? config('cashier.model')
                ?? 'Test\\Billable\\Model';

            return $this->attributes['morph_class'] ?? $defaultClass;
        }

        public function getKey(): int
        {
            return $this->attributes['id'] ?? 1;
        }

        public function save(array $options = []): bool
        {
            $this->plan_changed_at = now();
            // Ensure plan_id is accessible after save
            $this->attributes['plan_id'] = $this->plan_id;
            $this->attributes['plan_price_id'] = $this->plan_price_id;
            $this->attributes['stripe_id'] = $this->stripe_id;
            $this->attributes['pm_type'] = $this->pm_type;
            $this->attributes['pm_last_four'] = $this->pm_last_four;
            $this->attributes['trial_ends_at'] = $this->trial_ends_at;

            return true;
        }

        public function reportUsage(string $meterId, int $quantity): void
        {
            // Mock Stripe usage reporting
        }

        // Add required methods from Billable contract
        public function quotas()
        {
            return $this->hasMany(\Develupers\PlanUsage\Models\Quota::class, 'billable_id')
                ->where('billable_type', $this->getMorphClass());
        }

        public function usage()
        {
            return $this->hasMany(\Develupers\PlanUsage\Models\Usage::class, 'billable_id')
                ->where('billable_type', $this->getMorphClass());
        }

        public function plan()
        {
            return $this->belongsTo(\Develupers\PlanUsage\Models\Plan::class, 'plan_id');
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
