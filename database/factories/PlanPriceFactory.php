<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Database\Factories;

use Develupers\PlanUsage\Enums\Interval;
use Develupers\PlanUsage\Models\Plan;
use Develupers\PlanUsage\Models\PlanPrice;
use Illuminate\Database\Eloquent\Factories\Factory;

class PlanPriceFactory extends Factory
{
    protected $model = PlanPrice::class;

    public function definition(): array
    {
        // Provider identifier columns (stripe_price_id, paddle_price_id,
        // polar_product_id) are intentionally omitted:
        // consumer schemas only contain the selected provider's column, so an
        // unconditional default would fail with "column not found" on every
        // single-provider install. Set them explicitly where needed.
        return [
            'plan_id' => Plan::factory(),
            'price' => $this->faker->randomFloat(2, 10, 500),
            'currency' => 'usd',
            'interval' => $this->faker->randomElement([Interval::MONTH->value, Interval::YEAR->value]),
            'is_active' => true,
            'is_default' => false,
            'metadata' => [
                'description' => $this->faker->sentence(),
            ],
        ];
    }

    public function monthly(): static
    {
        return $this->state(fn (array $attributes) => [
            'interval' => Interval::MONTH->value,
        ]);
    }

    public function yearly(): static
    {
        return $this->state(fn (array $attributes) => [
            'interval' => Interval::YEAR->value,
        ]);
    }

    public function default(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_default' => true,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
