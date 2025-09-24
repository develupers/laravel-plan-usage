<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Contracts;

use Develupers\PlanUsage\Models\Plan;
use Develupers\PlanUsage\Models\PlanPrice;

/**
 * Interface for models that can have subscriptions and plans.
 *
 * This interface defines the contract for billable entities
 * that can subscribe to plans and manage their features.
 */
interface Billable
{
    /**
     * Get the current plan of the billable entity.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function plan();

    /**
     * Get all quotas for the billable.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function quotas();

    /**
     * Get all usage records for the billable.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function usage();

    /**
     * Subscribe to a plan.
     *
     * @param  Plan  $plan  The plan to subscribe to
     * @param  array  $options  Options for subscription (price_id, payment_method, etc.)
     * @return self
     */
    public function subscribeToPlan(Plan $plan, array $options = []): self;

    /**
     * Initialize quotas for a plan.
     *
     * @param  Plan  $plan  The plan to initialize quotas for
     */
    public function initializeQuotasForPlan(Plan $plan): void;

    /**
     * Check if the billable's plan includes a feature.
     *
     * @param  string  $featureSlug  The feature to check
     * @return bool True if the plan includes this feature
     */
    public function hasFeature(string $featureSlug): bool;

    /**
     * Check if the billable can use more of a feature.
     *
     * @param  string  $featureSlug  The feature to check
     * @param  float  $units  Number of units to check
     * @return bool True if the feature can be used
     */
    public function canUseFeature(string $featureSlug, float $units = 1.0): bool;

    /**
     * Record usage of a feature.
     *
     * @param  string  $featureSlug  The feature being used
     * @param  float  $quantity  The quantity used
     * @param  array  $metadata  Additional metadata
     * @return bool True if usage was recorded successfully
     */
    public function recordUsage(string $featureSlug, float $quantity = 1.0, array $metadata = []): bool;

    /**
     * Get the value/limit for a feature.
     *
     * @param  string  $featureSlug  The feature to get value for
     * @return mixed The feature value or null
     */
    public function getFeatureValue(string $featureSlug): mixed;

    /**
     * Get the remaining quota for a feature.
     *
     * @param  string  $featureSlug  The feature to check
     * @return float|null The remaining quota or null
     */
    public function getFeatureRemaining(string $featureSlug): ?float;

    /**
     * Get the usage percentage for a feature.
     *
     * @param  string  $featureSlug  The feature to check
     * @return float|null The usage percentage or null
     */
    public function getFeatureUsagePercentage(string $featureSlug): ?float;

    /**
     * Get feature usage details with limit and used values.
     *
     * @param  string  $featureSlug  The feature to check
     * @return array Array with 'limit', 'used', and 'remaining' keys
     */
    public function getFeatureUsage(string $featureSlug): array;

    /**
     * Get all features with their current status.
     *
     * @return \Illuminate\Support\Collection
     */
    public function getFeaturesStatus();

    /**
     * Reset all quotas that need resetting.
     */
    public function resetExpiredQuotas(): void;

    /**
     * Sync quotas with current plan.
     */
    public function syncQuotasWithPlan(): void;
}