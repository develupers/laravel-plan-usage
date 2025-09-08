<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Database\Factories;

use Develupers\PlanUsage\Models\Feature;
use Develupers\PlanUsage\Models\Usage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

class UsageFactory extends Factory
{
    protected $model = Usage::class;

    public function definition(): array
    {
        $periodStart = $this->faker->dateTimeBetween('-30 days', 'now');
        $periodEnd = Carbon::instance($periodStart)->addDay();

        return [
            'billable_type' => 'App\\Models\\Account',
            'billable_id' => $this->faker->numberBetween(1, 100),
            'feature_id' => Feature::factory(),
            'used' => $this->faker->randomFloat(2, 0.01, 100),
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'metadata' => [
                'ip' => $this->faker->ipv4(),
                'user_agent' => $this->faker->userAgent(),
                'action' => $this->faker->randomElement(['api_call', 'file_upload', 'report_generation']),
            ],
        ];
    }

    public function forBillable(string $type, int $id): static
    {
        return $this->state(fn (array $attributes) => [
            'billable_type' => $type,
            'billable_id' => $id,
        ]);
    }

    public function forFeature(int $featureId): static
    {
        return $this->state(fn (array $attributes) => [
            'feature_id' => $featureId,
        ]);
    }

    public function withAmount(float $amount): static
    {
        return $this->state(fn (array $attributes) => [
            'used' => $amount,
        ]);
    }

    public function currentPeriod(): static
    {
        return $this->state(fn (array $attributes) => [
            'period_start' => Carbon::now()->startOfMonth(),
            'period_end' => Carbon::now()->endOfMonth(),
        ]);
    }
}
