<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Actions\Subscription;

use Develupers\PlanUsage\Contracts\Billable;
use Develupers\PlanUsage\Contracts\BillingProvider;
use Develupers\PlanUsage\Contracts\SubscriptionCancellationProvider;
use Develupers\PlanUsage\Support\SubscriptionStateLock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CancelSubscriptionAction
{
    public function __construct(
        private ?BillingProvider $billingProvider = null,
        private ?DeleteSubscriptionAction $deleteSubscription = null,
        private ?SubscriptionStateLock $stateLock = null,
    ) {}

    /**
     * Cancel the billable's subscription.
     *
     * Only cancelling the configured default-type subscription revokes the
     * local plan — cancelling an add-on (custom-named) subscription cancels
     * it at the provider without touching the entitlement the default
     * subscription controls.
     *
     * @param  Billable  $billable  The billable entity
     * @param  bool  $immediately  Whether to cancel immediately or at period end
     * @param  string|null  $subscriptionName  The subscription name (configured default when omitted)
     *
     * @throws ValidationException
     */
    public function execute(Billable $billable, bool $immediately = false, ?string $subscriptionName = null): void
    {
        $defaultName = config('plan-usage.subscription.default_name', 'default');
        $subscriptionName ??= $defaultName;

        if ($this->billingProvider instanceof SubscriptionCancellationProvider && $billable instanceof Model) {
            $cancel = function () use ($billable, $immediately, $subscriptionName, $defaultName): void {
                // Resolve and validate under the lock against fresh state: a
                // pre-lock snapshot could cancel a subscription that was
                // replaced (and then wipe the newly granted plan).
                $billable->refresh();
                $billable->unsetRelation('subscriptions');
                $subscription = $billable->subscription($subscriptionName);

                if (! $subscription) {
                    throw ValidationException::withMessages([
                        'subscription' => ['No active subscription found.'],
                    ]);
                }

                if ($this->isCancelled($subscription)) {
                    throw ValidationException::withMessages([
                        'subscription' => ['Subscription is already cancelled.'],
                    ]);
                }

                $this->billingProvider->cancelSubscription($billable, $immediately, $subscriptionName);

                // Local revocation is not optional on immediate cancel — but
                // only the default-type subscription controls the plan.
                if ($immediately && $subscriptionName === $defaultName) {
                    ($this->deleteSubscription ?? app(DeleteSubscriptionAction::class))->execute($billable);
                }
            };

            // Serialize with plan changes and webhook processing when the
            // shared lock is available (container-resolved instances).
            $this->stateLock !== null
                ? $this->stateLock->block($billable, $cancel, waitSeconds: 5)
                : $cancel();

            return;
        }

        $subscription = $billable->subscription($subscriptionName);

        if (! $subscription) {
            throw ValidationException::withMessages([
                'subscription' => ['No active subscription found.'],
            ]);
        }

        if ($this->isCancelled($subscription)) {
            throw ValidationException::withMessages([
                'subscription' => ['Subscription is already cancelled.'],
            ]);
        }

        if ($immediately) {
            if (! method_exists($subscription, 'cancelNow')) {
                throw ValidationException::withMessages([
                    'subscription' => ['Immediate cancellation is not supported by the configured billing provider.'],
                ]);
            }

            $subscription->cancelNow();
        } else {
            $subscription->cancel();
        }
    }

    /**
     * Cancel all subscriptions for the billable.
     *
     * @param  Billable  $billable  The billable entity
     * @param  bool  $immediately  Whether to cancel immediately or at period end
     *
     * @throws ValidationException
     */
    public function cancelAll(Billable $billable, bool $immediately = false): void
    {
        $defaultName = config('plan-usage.subscription.default_name', 'default');

        $process = function () use ($billable, $immediately, $defaultName): void {
            // Resolved inside the (possibly locked) closure so a concurrent
            // change is never acted on from a stale collection.
            if ($billable instanceof Model) {
                $billable->refresh();
                $billable->unsetRelation('subscriptions');
            }

            $subscriptions = $billable->subscriptions()->active()->get();

            if ($subscriptions->isEmpty()) {
                throw ValidationException::withMessages([
                    'subscription' => ['No active subscriptions found.'],
                ]);
            }

            $cancelledDefaultSubscription = false;

            foreach ($subscriptions as $subscription) {
                if ($this->isCancelled($subscription)) {
                    continue;
                }

                if ($this->billingProvider instanceof SubscriptionCancellationProvider && $billable instanceof Model) {
                    $subscriptionName = method_exists($subscription, 'getAttribute')
                        ? (string) ($subscription->getAttribute('type') ?? $defaultName)
                        : $defaultName;
                    $this->billingProvider->cancelSubscription($billable, $immediately, $subscriptionName);

                    if ($subscriptionName === $defaultName) {
                        $cancelledDefaultSubscription = true;
                    }

                    continue;
                }

                if ($immediately) {
                    if (! method_exists($subscription, 'cancelNow')) {
                        throw ValidationException::withMessages([
                            'subscription' => ['Immediate cancellation is not supported by the configured billing provider.'],
                        ]);
                    }

                    $subscription->cancelNow();
                } else {
                    $subscription->cancel();
                }
            }

            // Only the default-type subscription controls the plan: revoking
            // because an add-on was cancelled would delete an entitlement the
            // still-active default subscription pays for.
            if ($immediately && $cancelledDefaultSubscription) {
                ($this->deleteSubscription ?? app(DeleteSubscriptionAction::class))->execute($billable);
            }
        };

        // Serialize with plan changes and webhook processing when the shared
        // lock is available and provider cancels will mutate local state.
        if ($this->billingProvider instanceof SubscriptionCancellationProvider
            && $billable instanceof Model
            && $this->stateLock !== null) {
            $this->stateLock->block($billable, $process, waitSeconds: 5);

            return;
        }

        $process();
    }

    /**
     * Resume a cancelled subscription if still in grace period.
     *
     * @param  Billable  $billable  The billable entity
     * @param  string|null  $subscriptionName  The subscription name (configured default when omitted)
     *
     * @throws ValidationException
     */
    public function resume(Billable $billable, ?string $subscriptionName = null): void
    {
        $subscriptionName ??= config('plan-usage.subscription.default_name', 'default');

        if ($this->billingProvider instanceof SubscriptionCancellationProvider && $billable instanceof Model) {
            $resume = function () use ($billable, $subscriptionName): void {
                // Resolve and validate under the lock against fresh state.
                $billable->refresh();
                $billable->unsetRelation('subscriptions');
                $subscription = $billable->subscription($subscriptionName);

                if (! $subscription) {
                    throw ValidationException::withMessages([
                        'subscription' => ['No subscription found.'],
                    ]);
                }

                // Only a fully ended subscription is locally un-resumable. The
                // grace-period shape is provider-specific — Polar keeps a
                // period-end cancellation ACTIVE with cancel_at_period_end, so
                // onGracePeriod() (canceled + future end) never passes there.
                // Delegate everything else and let the provider validate remotely.
                if ($this->isCancelled($subscription) && ! $subscription->onGracePeriod()) {
                    throw ValidationException::withMessages([
                        'subscription' => ['Subscription has ended and cannot be resumed.'],
                    ]);
                }

                $this->billingProvider->resumeSubscription($billable, $subscriptionName);
            };

            $this->stateLock !== null
                ? $this->stateLock->block($billable, $resume, waitSeconds: 5)
                : $resume();

            return;
        }

        $subscription = $billable->subscription($subscriptionName);

        if (! $subscription) {
            throw ValidationException::withMessages([
                'subscription' => ['No subscription found.'],
            ]);
        }

        if (! $subscription->onGracePeriod()) {
            throw ValidationException::withMessages([
                'subscription' => ['Subscription is not in grace period and cannot be resumed.'],
            ]);
        }

        $subscription->resume();
    }

    private function isCancelled(object $subscription): bool
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
