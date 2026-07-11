<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Actions\Subscription;

use Develupers\PlanUsage\Contracts\Billable;
use Develupers\PlanUsage\Contracts\BillingProvider;
use Develupers\PlanUsage\Contracts\SubscriptionPlanChangeProvider;
use Develupers\PlanUsage\Events\SubscriptionPlanChangeCancelled;
use Develupers\PlanUsage\Models\SubscriptionPlanChange;
use Develupers\PlanUsage\Support\SubscriptionStateLock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

class CancelPendingPlanChangeAction
{
    public function __construct(
        private BillingProvider $billingProvider,
        private SubscriptionStateLock $stateLock,
    ) {}

    /**
     * @param  Model&Billable  $billable
     */
    public function execute(Model $billable, string $subscriptionName = 'default'): SubscriptionPlanChange
    {
        if (! $this->billingProvider instanceof SubscriptionPlanChangeProvider) {
            throw ValidationException::withMessages([
                'subscription' => ["{$this->billingProvider->name()} does not support pending plan changes."],
            ]);
        }

        // Serialized with the plan-change action and webhook processing so a
        // cancellation cannot race the webhook that applies the same change.
        return $this->stateLock->block($billable, function () use ($billable, $subscriptionName): SubscriptionPlanChange {
            /** @var class-string<SubscriptionPlanChange> $planChangeModel */
            $planChangeModel = config('plan-usage.models.subscription_plan_change', SubscriptionPlanChange::class);

            $planChange = $planChangeModel::query()
                ->pending()
                ->where('billable_type', $billable->getMorphClass())
                ->where('billable_id', $billable->getKey())
                ->where('provider', $this->billingProvider->name())
                ->where('subscription_type', $subscriptionName)
                ->latest('id')
                ->first();

            if ($planChange === null) {
                throw ValidationException::withMessages([
                    'subscription' => ['No pending plan change found.'],
                ]);
            }

            $this->billingProvider->cancelPendingSubscriptionChange($billable, $subscriptionName);
            $planChange->markCancelled();

            Event::dispatch(new SubscriptionPlanChangeCancelled($billable, $planChange));

            return $planChange->refresh();
        }, waitSeconds: 5);
    }
}
