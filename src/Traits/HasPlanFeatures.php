<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Traits;

use Develupers\PlanUsage\Models\Feature;
use Develupers\PlanUsage\Models\Plan;
use Develupers\PlanUsage\Models\Quota;
use Develupers\PlanUsage\Models\Usage;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;
use Laravel\Cashier\Billable;

trait HasPlanFeatures
{
    use Billable;
    use EnforcesQuotas;
    use TracksUsage;

    /**
     * Get the plan that the billable is subscribed to.
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(config('plan-usage.models.plan', Plan::class));
    }

    /**
     * Get all quotas for the billable.
     */
    public function quotas(): MorphMany
    {
        return $this->morphMany(config('plan-usage.models.quota', Quota::class), 'billable');
    }

    /**
     * Get all usage records for the billable.
     */
    public function usage(): MorphMany
    {
        return $this->morphMany(config('plan-usage.models.usage', Usage::class), 'billable');
    }

    /**
     * Subscribe to a plan.
     */
    public function subscribeToPlan(Plan $plan, array $options = []): self
    {
        // Update the plan_id on the billable model
        $this->plan_id = $plan->id;
        $this->save();

        // Initialize quotas for the new plan
        $this->initializeQuotasForPlan($plan);

        // If Stripe integration is enabled and plan has a Stripe price ID
        if (config('plan-usage.stripe.enabled') && $plan->stripe_price_id) {
            // Create or update Stripe subscription
            if ($this->subscribed()) {
                $this->subscription()->swap($plan->stripe_price_id);
            } else {
                $this->newSubscription('default', $plan->stripe_price_id)
                    ->create($options['payment_method'] ?? null);
            }
        }

        return $this;
    }

    /**
     * Initialize quotas for a plan.
     */
    public function initializeQuotasForPlan(Plan $plan): void
    {
        foreach ($plan->features as $feature) {
            if ($feature->type === 'quota' || $feature->type === 'limit') {
                $this->quotas()->updateOrCreate(
                    ['feature_id' => $feature->id],
                    [
                        'limit' => $feature->pivot->value,
                        'used' => 0,
                        'reset_at' => $feature->getNextResetDate(),
                    ]
                );
            }
        }
    }

    /**
     * Check if the billable's plan includes a feature.
     * 
     * This checks if the feature exists in the plan, not if it can be used.
     * For usage checks, use canUseFeature() instead.
     * 
     * @param string $featureSlug The feature to check
     * @return bool True if the plan includes this feature
     */
    public function hasFeature(string $featureSlug): bool
    {
        if (! $this->plan) {
            return false;
        }

        $feature = $this->plan->features()->where('slug', $featureSlug)->first();

        if (! $feature) {
            return false;
        }

        // For boolean features, check if enabled
        if ($feature->type === 'boolean') {
            return (bool) $feature->pivot->value;
        }

        // For limit/quota features, check if within limits
        if ($feature->type === 'limit' || $feature->type === 'quota') {
            $quota = $this->quotas()->where('feature_id', $feature->id)->first();

            return $quota ? ! $quota->isExceeded() : true;
        }

        return false;
    }

    /**
     * Get the value/limit for a feature.
     */
    public function getFeatureValue(string $featureSlug): mixed
    {
        if (! $this->plan) {
            return null;
        }

        return $this->plan->getFeatureValue($featureSlug);
    }

    /**
     * Get the remaining quota for a feature.
     */
    public function getFeatureRemaining(string $featureSlug): ?float
    {
        $feature = Feature::where('slug', $featureSlug)->first();

        if (! $feature || ! in_array($feature->type, ['limit', 'quota'])) {
            return null;
        }

        $quota = $this->quotas()->where('feature_id', $feature->id)->first();

        return $quota ? $quota->remaining() : null;
    }

    /**
     * Get the usage percentage for a feature.
     */
    public function getFeatureUsagePercentage(string $featureSlug): ?float
    {
        $feature = Feature::where('slug', $featureSlug)->first();

        if (! $feature || ! in_array($feature->type, ['limit', 'quota'])) {
            return null;
        }

        $quota = $this->quotas()->where('feature_id', $feature->id)->first();

        return $quota ? $quota->usagePercentage() : 0;
    }

    /**
     * Get all features with their current status.
     */
    public function getFeaturesStatus(): Collection
    {
        if (! $this->plan) {
            return collect();
        }

        return $this->plan->features->map(function ($feature) {
            $status = [
                'feature' => $feature->slug,
                'name' => $feature->name,
                'type' => $feature->type,
                'enabled' => $this->hasFeature($feature->slug),
            ];

            if (in_array($feature->type, ['limit', 'quota'])) {
                $quota = $this->quotas()->where('feature_id', $feature->id)->first();
                if ($quota) {
                    $status['limit'] = $quota->limit;
                    $status['used'] = $quota->used;
                    $status['remaining'] = $quota->remaining();
                    $status['percentage'] = $quota->usagePercentage();
                }
            }

            return $status;
        });
    }

    /**
     * Reset all quotas that need resetting.
     */
    public function resetExpiredQuotas(): void
    {
        $this->quotas()
            ->whereNotNull('reset_at')
            ->where('reset_at', '<=', now())
            ->each(function ($quota) {
                $quota->reset();
            });
    }

    /**
     * Sync quotas with current plan.
     */
    public function syncQuotasWithPlan(): void
    {
        if (! $this->plan) {
            return;
        }

        // Remove quotas for features not in the current plan
        $planFeatureIds = $this->plan->features->pluck('id');
        $this->quotas()->whereNotIn('feature_id', $planFeatureIds)->delete();

        // Update or create quotas for current plan features
        $this->initializeQuotasForPlan($this->plan);
    }
}
