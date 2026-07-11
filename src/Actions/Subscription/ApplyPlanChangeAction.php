<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Actions\Subscription;

use Carbon\CarbonImmutable;
use Develupers\PlanUsage\Contracts\Billable;
use Develupers\PlanUsage\Enums\Period;
use Develupers\PlanUsage\Models\Feature;
use Develupers\PlanUsage\Models\Plan;
use Develupers\PlanUsage\Models\PlanPrice;
use Develupers\PlanUsage\Models\Quota;
use Develupers\PlanUsage\Services\QuotaEnforcer;
use Develupers\PlanUsage\Support\ProviderSubscriptionChange;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ApplyPlanChangeAction
{
    public function __construct(
        private QuotaEnforcer $quotaEnforcer,
    ) {}

    /**
     * @param  Model&Billable  $billable
     * @return array<int, array<string, mixed>>
     */
    public function execute(
        Model $billable,
        PlanPrice $targetPlanPrice,
        ProviderSubscriptionChange $providerChange,
        bool $resetUsage
    ): array {
        $targetPlanPrice->loadMissing('plan.features');
        $targetPlan = $targetPlanPrice->plan;
        $currentPlan = $billable->plan()->with('features')->first();

        if ($currentPlan !== null && ! $currentPlan instanceof Plan) {
            throw new \LogicException('The configured billable plan relation must return a Plan model.');
        }

        $adjustments = [];

        DB::transaction(function () use (
            $billable,
            $targetPlan,
            $targetPlanPrice,
            $currentPlan,
            $providerChange,
            $resetUsage,
            &$adjustments
        ): void {
            $billable->newQuery()->whereKey($billable->getKey())->lockForUpdate()->first();

            $targetFeatureIds = $targetPlan->features->pluck('id');
            $billable->quotas()->whereNotIn('feature_id', $targetFeatureIds)->delete();

            foreach ($targetPlan->features as $feature) {
                if (! in_array($feature->type, ['quota', 'limit'], true)) {
                    continue;
                }

                $quota = $billable->quotas()
                    ->where('feature_id', $feature->id)
                    ->lockForUpdate()
                    ->first();
                $targetLimit = $this->numericLimit($targetPlan->getFeatureValue($feature->slug));
                // Two distinct baselines: what the billable actually holds this
                // period (possibly already prorated by an earlier change) and
                // the current plan's full allowance (the delta reference).
                $currentPlanLimit = $this->currentPlanLimit($currentPlan, $feature);
                $currentPeriodLimit = $quota !== null ? $quota->limit : $currentPlanLimit;
                $period = $this->entitlementPeriod($feature, $providerChange);
                // Non-resetting (lifetime) allowances are one-time buckets: an
                // upgrade must grant the full target because no later reset or
                // renewal would ever true a prorated grant up to the target.
                $fraction = $feature->reset_period === null
                    ? 1.0
                    : $this->remainingFraction($period['start'], $period['end']);

                if ($resetUsage) {
                    $newLimit = $targetLimit;
                    $used = 0.0;
                } elseif ($feature->type === 'quota') {
                    $newLimit = $this->proratedChangeLimit($currentPeriodLimit, $currentPlanLimit, $targetLimit, $fraction);
                    $used = $quota->used ?? 0.0;
                } else {
                    $newLimit = $targetLimit;
                    $used = $quota->used ?? 0.0;
                }

                /** @var class-string<Quota> $quotaModel */
                $quotaModel = config('plan-usage.models.quota', Quota::class);

                $quota ??= new $quotaModel([
                    'billable_type' => $billable->getMorphClass(),
                    'billable_id' => $billable->getKey(),
                    'feature_id' => $feature->id,
                ]);
                $quota->limit = $newLimit;
                $quota->used = $used;
                $quota->reset_at = $feature->reset_period === null ? null : $period['end'];
                $quota->save();

                $adjustments[] = [
                    'feature_id' => $feature->id,
                    'feature_slug' => $feature->slug,
                    'previous_limit' => $currentPeriodLimit,
                    'new_limit' => $newLimit,
                    'remaining_fraction' => $fraction,
                    'reset_at' => $quota->reset_at,
                    'usage_reset' => $resetUsage,
                ];
            }

            $billable->setAttribute('plan_id', $targetPlan->id);
            $billable->setAttribute('plan_price_id', $targetPlanPrice->id);
            $billable->setAttribute('plan_changed_at', now());
            $billable->save();
        });

        $billable->unsetRelation('plan');
        $this->quotaEnforcer->clearQuotaCache($billable);

        return $adjustments;
    }

    private function numericLimit(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    private function currentPlanLimit(?Plan $currentPlan, Feature $feature): ?float
    {
        if ($currentPlan === null) {
            return 0.0;
        }

        $currentFeature = $currentPlan->features->firstWhere('id', $feature->id);

        if ($currentFeature === null) {
            return 0.0;
        }

        return $this->numericLimit($currentPlan->getFeatureValue($feature->slug));
    }

    /**
     * Prorate a mid-cycle plan change.
     *
     * The delta reference is the CURRENT PLAN's full allowance, not the stored
     * period limit — the stored limit may already be prorated by an earlier
     * change in the same period, and using it would compound the grant
     * (1,000 → 5,000 → 15,000 at 50% must yield 8,000, not 9,000).
     */
    private function proratedChangeLimit(
        ?float $currentPeriodLimit,
        ?float $currentPlanLimit,
        ?float $targetLimit,
        float $remainingFraction
    ): ?float {
        // Currently unlimited stays unlimited until reset; upgrading to an
        // unlimited plan grants it immediately.
        if ($currentPeriodLimit === null || $targetLimit === null) {
            return null;
        }

        // Downgrades (and lateral moves) keep the grandfathered period limit
        // until reset trues it up — including when an earlier downgrade left
        // the period limit at or above the new target.
        if ($currentPlanLimit === null
            || $targetLimit <= $currentPlanLimit
            || $currentPeriodLimit >= $targetLimit) {
            return $currentPeriodLimit;
        }

        return round(min(
            $targetLimit,
            $currentPeriodLimit + (($targetLimit - $currentPlanLimit) * $remainingFraction),
        ), 4);
    }

    /**
     * @return array{start: CarbonImmutable, end: CarbonImmutable}
     */
    private function entitlementPeriod(
        Feature $feature,
        ProviderSubscriptionChange $providerChange
    ): array {
        $anchor = $providerChange->periodStart;
        $billingEnd = $providerChange->periodEnd;
        $period = $feature->reset_period;

        if ($period === null) {
            return ['start' => $anchor, 'end' => $billingEnd];
        }

        $now = CarbonImmutable::now();
        $index = 1;
        $start = $anchor;
        $end = $this->addPeriodFromAnchor($anchor, $period, $index);

        while ($end->lessThanOrEqualTo($now) && $end->lessThan($billingEnd)) {
            $start = $end;
            $index++;
            $end = $this->addPeriodFromAnchor($anchor, $period, $index);
        }

        if ($end->greaterThan($billingEnd)) {
            $end = $billingEnd;
        }

        return ['start' => $start, 'end' => $end];
    }

    private function addPeriodFromAnchor(CarbonImmutable $anchor, Period $period, int $amount): CarbonImmutable
    {
        return match ($period) {
            Period::HOUR => $anchor->addHours($amount),
            Period::DAY => $anchor->addDays($amount),
            Period::WEEK => $anchor->addWeeks($amount),
            Period::MONTH => $anchor->addMonthsNoOverflow($amount),
            Period::YEAR => $anchor->addYearsNoOverflow($amount),
        };
    }

    private function remainingFraction(CarbonImmutable $start, CarbonImmutable $end): float
    {
        $totalSeconds = max(1, $start->diffInSeconds($end));
        $remainingSeconds = max(0, CarbonImmutable::now()->diffInSeconds($end, false));

        return min(1.0, max(0.0, $remainingSeconds / $totalSeconds));
    }
}
