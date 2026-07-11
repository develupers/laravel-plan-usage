<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Traits;

use Develupers\PlanUsage\Actions\Subscription\CancelPendingPlanChangeAction;
use Develupers\PlanUsage\Actions\Subscription\ChangeSubscriptionPlanAction;
use Develupers\PlanUsage\Contracts\BillingProvider;
use Develupers\PlanUsage\Enums\SubscriptionChangeTiming;
use Develupers\PlanUsage\Models\Feature;
use Develupers\PlanUsage\Models\Plan;
use Develupers\PlanUsage\Models\PlanPrice;
use Develupers\PlanUsage\Models\Quota;
use Develupers\PlanUsage\Models\SubscriptionPlanChange;
use Develupers\PlanUsage\Models\Usage;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Trait for models that can subscribe to plans and use features.
 *
 * IMPORTANT: You must also add the appropriate Cashier Billable trait to your model:
 * - For Stripe: use Laravel\Cashier\Billable;
 * - For Paddle: use Laravel\Paddle\Billable;
 * - For Polar: use Danestves\LaravelPolar\Billable;
 *
 * Example:
 * ```php
 * class Account extends Model {
 *     use \Laravel\Cashier\Billable;  // or \Laravel\Paddle\Billable
 *     use \Develupers\PlanUsage\Traits\HasPlanFeatures;
 * }
 * ```
 */
trait HasPlanFeatures
{
    use EnforcesQuotas;
    use TracksUsage;

    /**
     * Boot the trait and register model events.
     */
    public static function bootHasPlanFeatures(): void
    {
        static::creating(function ($model) {
            // Only assign default plan if plan_id is not already set
            if ($model->plan_id === null) {
                $defaultPlanId = config('plan-usage.subscription.default_plan_id');

                if ($defaultPlanId) {
                    $model->plan_id = $defaultPlanId;
                }
            }
        });
    }

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
     *
     * This method updates the local plan association. For billing provider
     * subscription management, use the appropriate Cashier methods directly
     * or through the BillingProvider abstraction.
     */
    public function subscribeToPlan(Plan $plan, array $options = []): self
    {
        $plan->loadMissing(['defaultPrice', 'prices']);

        $selectedPlanPrice = $this->resolvePlanPrice($plan, $options);

        $defaultPlanPrice = $plan->defaultPrice
            ?? $plan->prices
                ->where('is_active', true)
                ->sortByDesc(fn ($price) => $price->is_default)
                ->first();

        $planPriceToAssign = $selectedPlanPrice ?? $defaultPlanPrice;

        // Validate that a requested price was found when one was specified
        $priceWasRequested = isset($options['plan_price_id'])
            || isset($options['price_id'])
            || isset($options['stripe_price_id'])
            || isset($options['paddle_price_id'])
            || isset($options['polar_product_id']);

        if ($priceWasRequested && ! $selectedPlanPrice) {
            throw new \InvalidArgumentException(
                'The specified price does not belong to this plan.'
            );
        }

        // Resolve the provider price up front so billing capability can be
        // validated BEFORE any local entitlements are granted.
        $billingEnabled = config('plan-usage.stripe.enabled', false)
            || config('plan-usage.billing.provider') !== null;

        $priceId = null;

        if ($billingEnabled && $planPriceToAssign) {
            $priceId = $options['price_id']
                ?? $options['stripe_price_id']
                ?? $options['paddle_price_id']
                ?? $options['polar_product_id']
                ?? $planPriceToAssign->getProviderPriceId();

            // Checkout-based providers (Polar, Paddle) expose subscribed() but not
            // newSubscription() — their subscriptions are created through checkout.
            // Fail loud here instead of silently granting a paid plan with no
            // provider subscription or payment behind it.
            if ($priceId
                && method_exists($this, 'subscribed')
                && ! $this->subscribed()
                && ! method_exists($this, 'newSubscription')) {
                throw new \LogicException(
                    'This billable cannot create a provider subscription directly. '
                    .'Create the subscription through the provider checkout flow '
                    .'(CreateCheckoutSessionAction) and let the webhook assign the plan, '
                    .'or use changePlan() to switch an existing subscription.'
                );
            }
        }

        // Wrap plan assignment + quota sync in a transaction to prevent race conditions
        DB::transaction(function () use ($plan, $planPriceToAssign) {
            $this->plan_id = $plan->id;

            if ($planPriceToAssign && $this->supportsPlanPriceColumn()) {
                $this->plan_price_id = $planPriceToAssign->id;
            }

            $this->save();

            // A plan relation loaded earlier in the request still points at the
            // OLD plan; drop it so quota sync reads the plan we just assigned.
            $this->unsetRelation('plan');

            // Sync quotas: remove orphaned quotas from old plan, initialize new ones
            $this->syncQuotasWithPlan();
        });

        $this->quotaEnforcer()->clearQuotaCache($this);

        // Handle billing provider subscription if configured
        if ($priceId && method_exists($this, 'subscribed')) {
            // Create or update subscription using Cashier methods
            if ($this->subscribed()) {
                $this->subscription()->swap($priceId);
            } elseif (method_exists($this, 'newSubscription')) {
                $this->newSubscription('default', $priceId)
                    ->create($options['payment_method'] ?? null);
            }
        }

        return $this;
    }

    /**
     * Change the subscription to a different plan price through the billing provider.
     *
     * Requires a provider that implements SubscriptionLifecycleProvider
     * (Stripe, Paddle, or Polar). Immediate changes swap and prorate now;
     * NextPeriod schedules the change for the next renewal (Polar only).
     */
    public function changePlan(
        PlanPrice $targetPlanPrice,
        SubscriptionChangeTiming|string $timing = SubscriptionChangeTiming::Immediate,
        string $subscriptionName = 'default'
    ): SubscriptionPlanChange {
        if (is_string($timing)) {
            $timing = SubscriptionChangeTiming::from($timing);
        }

        return app(ChangeSubscriptionPlanAction::class)
            ->execute($this, $targetPlanPrice, $timing, $subscriptionName);
    }

    /**
     * Cancel a pending (scheduled) plan change before it takes effect.
     */
    public function cancelPendingPlanChange(string $subscriptionName = 'default'): SubscriptionPlanChange
    {
        return app(CancelPendingPlanChangeAction::class)->execute($this, $subscriptionName);
    }

    /**
     * Get the pending (scheduled) plan change, if any.
     */
    public function pendingPlanChange(string $subscriptionName = 'default'): ?SubscriptionPlanChange
    {
        // Read-only accessor: a misconfigured/uninstalled provider must not
        // turn a view-level check into an exception. No resolvable provider
        // also means no pending change the package could act on.
        try {
            $provider = app(BillingProvider::class)->name();
        } catch (\Throwable) {
            return null;
        }

        /** @var class-string<SubscriptionPlanChange> $planChangeModel */
        $planChangeModel = config('plan-usage.models.subscription_plan_change', SubscriptionPlanChange::class);

        return $planChangeModel::query()
            ->pending()
            ->where('billable_type', $this->getMorphClass())
            ->where('billable_id', $this->getKey())
            ->where('provider', $provider)
            ->where('subscription_type', $subscriptionName)
            ->latest('id')
            ->first();
    }

    /**
     * Resolve the plan price from options.
     */
    protected function resolvePlanPrice(Plan $plan, array $options): ?PlanPrice
    {
        if (isset($options['plan_price_id'])) {
            return $plan->prices->firstWhere('id', (int) $options['plan_price_id']);
        }

        if (isset($options['price_id'])) {
            return $plan->prices
                ->first(fn ($price) => $price->getProviderPriceId() === $options['price_id']);
        }

        if (isset($options['stripe_price_id'])) {
            return $plan->prices->firstWhere('stripe_price_id', $options['stripe_price_id']);
        }

        if (isset($options['paddle_price_id'])) {
            return $plan->prices->firstWhere('paddle_price_id', $options['paddle_price_id']);
        }

        if (isset($options['polar_product_id'])) {
            return $plan->prices->firstWhere('polar_product_id', $options['polar_product_id']);
        }

        return null;
    }

    /**
     * Determine if the underlying table supports the plan_price_id column.
     */
    protected function supportsPlanPriceColumn(): bool
    {
        static $supportsPlanPrice;

        if ($supportsPlanPrice === null) {
            $supportsPlanPrice = Schema::hasColumn($this->getTable(), 'plan_price_id');
        }

        return $supportsPlanPrice;
    }

    /**
     * Initialize quotas for a plan.
     *
     * On create: sets limit, used=0, and reset_at.
     * On update: only updates the limit (preserves existing usage).
     */
    public function initializeQuotasForPlan(Plan $plan): void
    {
        foreach ($plan->features as $feature) {
            if ($feature->type === 'quota' || $feature->type === 'limit') {
                $quota = $this->quotas()->firstOrCreate(
                    ['feature_id' => $feature->id],
                    [
                        'limit' => $feature->pivot->value,
                        'used' => 0,
                        'reset_at' => $feature->getNextResetDate(),
                    ]
                );

                // If quota already existed, only update the limit
                if (! $quota->wasRecentlyCreated) {
                    $quota->update(['limit' => $feature->pivot->value]);
                }
            }
        }
    }

    /**
     * Check if the billable's plan includes a feature.
     *
     * This checks if the feature exists in the plan, not if it can be used.
     * For usage checks, use checkQuota() instead.
     *
     * @param  string  $featureSlug  The feature to check
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

            return $quota ? ! $quota->isLimitReached() : true;
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
     * Get feature usage details with limit and used values.
     *
     * Returns null when there is nothing to meter: the feature is not a
     * limit/quota type, or the current plan does not grant it. This is
     * deliberately distinct from a granted-but-zero limit (`limit => 0`) so
     * callers can tell "not applicable" from "exhausted" rather than rendering
     * a missing feature as a full progress bar.
     *
     * A granted-but-unlimited feature (plan value null) returns `limit => null`
     * (and `remaining => null`) — never coerced to 0.
     *
     * @return array{limit: int|float|null, used: int|float, remaining: int|float|null}|null
     */
    public function getFeatureUsage(string $featureSlug): ?array
    {
        $feature = Feature::where('slug', $featureSlug)->first();

        if (! $feature || ! in_array($feature->type, ['limit', 'quota'])) {
            return null;
        }

        // Metered, but this plan doesn't include the feature → nothing to
        // report. Checked via the plan pivot directly (not getFeatureValue,
        // which returns null for BOTH "unlimited" and "not granted").
        if ($this->plan === null || ! $this->plan->features()->where('slug', $featureSlug)->exists()) {
            return null;
        }

        $quota = $this->quotas()->where('feature_id', $feature->id)->first();

        if (! $quota) {
            // Quota not initialized yet — fall back to the plan's granted
            // value. A null value means unlimited; keep it null (not 0).
            $limit = $this->getFeatureValue($featureSlug);

            return ['limit' => $limit, 'used' => 0, 'remaining' => $limit];
        }

        return [
            'limit' => $quota->limit,
            'used' => $quota->used,
            'remaining' => $quota->remaining(),
        ];
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
            ->with('feature')
            ->each(function ($quota) {
                // Re-read under a row lock and re-check expiry: a concurrent
                // consumer may have lazily reset AND incremented this quota,
                // and saving the stale instance would zero the new usage.
                DB::transaction(function () use ($quota): void {
                    $locked = $quota->newQuery()->whereKey($quota->getKey())->lockForUpdate()->first();

                    if ($locked === null || ! $locked->needsReset()) {
                        return;
                    }

                    $locked->setRelation('feature', $quota->feature);
                    $locked->reset();
                });
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

    /**
     * Determine if the billable's subscription has fully ended.
     *
     * Cashier (Stripe and Paddle alike) preserves canceled subscription rows
     * forever, so "a row exists" never means "there is a live subscription".
     * A row that is canceled AND past its grace period is fully ended: the
     * billable may start a fresh checkout, and the row must never be swapped
     * (the provider rejects updates to dead subscriptions).
     */
    public function subscriptionHasEnded(string $type = 'default'): bool
    {
        $subscription = $this->subscription($type);

        return $subscription !== null
            && $this->subscriptionIsCancelled($subscription)
            && ! $subscription->onGracePeriod();
    }

    /**
     * Determine if the billable has a LIVE subscription row of any status.
     *
     * Broader than Cashier's subscribed(): paused and past_due subscriptions
     * are not "valid" but still exist on the provider (resumable / in payment
     * retry). Starting a fresh checkout while one exists would create a
     * duplicate subscription and double-bill the billable — gate checkout on
     * this, not on subscribed() alone.
     */
    public function hasLiveSubscription(string $type = 'default'): bool
    {
        $subscription = $this->subscription($type);

        if ($subscription === null) {
            return false;
        }

        return ! ($this->subscriptionIsCancelled($subscription) && ! $subscription->onGracePeriod());
    }

    /**
     * Normalize the US and UK spellings used by supported billing packages.
     */
    private function subscriptionIsCancelled(object $subscription): bool
    {
        if (method_exists($subscription, 'canceled')) {
            return $subscription->canceled();
        }

        if (method_exists($subscription, 'cancelled')) {
            return $subscription->cancelled();
        }

        return false;
    }
}
