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
     * @param  Billable  $billable  The billable entity
     * @param  bool  $immediately  Whether to cancel immediately or at period end
     * @param  string  $subscriptionName  The subscription name (default: 'default')
     *
     * @throws ValidationException
     */
    public function execute(Billable $billable, bool $immediately = false, string $subscriptionName = 'default'): void
    {
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

        if ($this->billingProvider instanceof SubscriptionCancellationProvider && $billable instanceof Model) {
            $cancel = function () use ($billable, $immediately, $subscriptionName): void {
                $this->billingProvider->cancelSubscription($billable, $immediately, $subscriptionName);

                if ($immediately) {
                    // Local revocation is not optional on immediate cancel —
                    // resolve the action if it was not injected.
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
        $subscriptions = $billable->subscriptions()->active()->get();

        if ($subscriptions->isEmpty()) {
            throw ValidationException::withMessages([
                'subscription' => ['No active subscriptions found.'],
            ]);
        }

        $process = function () use ($billable, $subscriptions, $immediately): void {
            $cancelledThroughLifecycleProvider = false;

            foreach ($subscriptions as $subscription) {
                if ($this->isCancelled($subscription)) {
                    continue;
                }

                if ($this->billingProvider instanceof SubscriptionCancellationProvider && $billable instanceof Model) {
                    $subscriptionName = method_exists($subscription, 'getAttribute')
                        ? (string) ($subscription->getAttribute('type') ?? 'default')
                        : 'default';
                    $this->billingProvider->cancelSubscription($billable, $immediately, $subscriptionName);
                    $cancelledThroughLifecycleProvider = true;

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

            if ($immediately && $cancelledThroughLifecycleProvider) {
                // Local revocation is not optional on immediate cancel —
                // resolve the action if it was not injected.
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
     * @param  string  $subscriptionName  The subscription name (default: 'default')
     *
     * @throws ValidationException
     */
    public function resume(Billable $billable, string $subscriptionName = 'default'): void
    {
        $subscription = $billable->subscription($subscriptionName);

        if (! $subscription) {
            throw ValidationException::withMessages([
                'subscription' => ['No subscription found.'],
            ]);
        }

        if ($this->billingProvider instanceof SubscriptionCancellationProvider && $billable instanceof Model) {
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

            $resume = fn () => $this->billingProvider->resumeSubscription($billable, $subscriptionName);

            $this->stateLock !== null
                ? $this->stateLock->block($billable, $resume, waitSeconds: 5)
                : $resume();

            return;
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
