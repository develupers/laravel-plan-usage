<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Database\Factories;

use Develupers\PlanUsage\Enums\Period;
use Develupers\PlanUsage\Models\Feature;
use Illuminate\Database\Eloquent\Factories\Factory;

class FeatureFactory extends Factory
{
    protected $model = Feature::class;

    public function definition(): array
    {
        $slug = $this->faker->unique()->slug(2);

        return [
            'name' => ucwords(str_replace('-', ' ', $slug)),
            'slug' => $slug,
            'description' => $this->faker->sentence(),
            'type' => $this->faker->randomElement(['boolean', 'limit', 'quota']),
            'unit' => $this->faker->randomElement(['requests', 'users', 'projects', 'gb', null]),
            'aggregation_method' => $this->faker->randomElement(['sum', 'count', 'max', 'last']),
            'reset_period' => $this->faker->randomElement([Period::HOURLY->value, Period::DAILY->value, Period::WEEKLY->value, Period::MONTHLY->value, Period::YEARLY->value, null]),
            'stripe_meter_id' => $this->faker->boolean(30) ? 'meter_'.$this->faker->unique()->regexify('[A-Za-z0-9]{24}') : null,
            'metadata' => [
                'category' => $this->faker->randomElement(['api', 'storage', 'users', 'features']),
                'display_order' => $this->faker->numberBetween(1, 100),
            ],
        ];
    }

    public function boolean(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'boolean',
            'unit' => null,
            'reset_period' => null,
        ]);
    }

    public function limit(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'limit',
            'aggregation_method' => 'max',
        ]);
    }

    public function quota(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'quota',
            'aggregation_method' => 'sum',
            'reset_period' => $this->faker->randomElement([Period::MONTHLY->value, Period::WEEKLY->value, Period::DAILY->value]),
        ]);
    }

    public function metered(): static
    {
        return $this->state(fn (array $attributes) => [
            'stripe_meter_id' => 'meter_'.$this->faker->unique()->regexify('[A-Za-z0-9]{24}'),
        ]);
    }
}
