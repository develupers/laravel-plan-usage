<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Commands\Subscription;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Develupers\PlanUsage\Actions\Subscription\ConfirmPendingPlanChangeAction;
use Develupers\PlanUsage\Actions\Subscription\DeleteSubscriptionAction;
use Develupers\PlanUsage\Actions\Subscription\SyncPlanWithBillableAction;
use Develupers\PlanUsage\Contracts\BillingProvider;
use Develupers\PlanUsage\Enums\SubscriptionChangeStatus;
use Develupers\PlanUsage\Events\SubscriptionPlanChangeCancelled;
use Develupers\PlanUsage\Models\PlanPrice;
use Develupers\PlanUsage\Models\SubscriptionPlanChange;
use Develupers\PlanUsage\Providers\Paddle\PaddleProvider;
use Develupers\PlanUsage\Providers\Polar\PolarProvider;
use Develupers\PlanUsage\Providers\Stripe\StripeProvider;
use Develupers\PlanUsage\Support\EntitlementStatusPolicy;
use Develupers\PlanUsage\Support\ProviderSubscriptionChange;
use Develupers\PlanUsage\Support\SubscriptionStateLock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;

class ReconcileSubscriptionsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'subscriptions:reconcile
                            {--dry-run : Show what would happen without making changes}
                            {--force : Skip confirmation prompt}
                            {--provider= : Override the billing provider (stripe, paddle, or polar)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reconcile local subscriptions with billing provider status to handle missed webhooks';

    /**
     * Execute the console command.
     */
    public function handle(BillingProvider $defaultProvider): int
    {
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');
        $providerName = $this->option('provider');

        // Get the provider to use
        $provider = $providerName
            ? $this->resolveProvider($providerName)
            : $defaultProvider;

        if (! $provider) {
            return Command::FAILURE;
        }

        // Check if the provider is installed
        if (! $provider->isInstalled()) {
            $this->error("The {$provider->name()} provider is not installed.");

            return Command::FAILURE;
        }

        if ($dryRun) {
            $this->info('Running in DRY-RUN mode - no changes will be made');
        }

        $this->info("Using {$provider->name()} billing provider");

        // Get the billable model class from config
        $billableClass = $this->getBillableClass();

        if (! $billableClass) {
            $this->error('Billable model class not configured. Please set plan-usage.models.billable in config.');

            return Command::FAILURE;
        }

        // Reconcile every billable with subscription rows: expired ones (to
        // revoke), active ones (to correct price drift left by missed or
        // out-of-order webhooks), and planless ones (to recover a missed
        // initial checkout). Billables with a plan but NO subscription rows
        // (lifetime purchases, manually granted plans) are never touched here.
        $query = $billableClass::whereHas('subscriptions');

        $count = $query->count();

        if ($count === 0) {
            $this->info('No subscriptions found that need reconciliation');

            return Command::SUCCESS;
        }

        $this->info("Found {$count} billable(s) with subscriptions to check");

        if (! $dryRun && ! $force) {
            if (! $this->confirm("Do you want to reconcile {$count} subscription(s)?")) {
                $this->info('Reconciliation cancelled');

                return Command::SUCCESS;
            }
        }

        $processed = 0;
        $removed = 0;
        $reactivated = 0;
        $updated = 0;
        $errors = 0;
        $skipped = 0;

        // Remote state is fetched individually INSIDE the per-billable lock:
        // a batch snapshot taken before processing can be stale by the time
        // the lock is held (e.g. regranting a plan a cancellation webhook
        // just revoked).

        $query->each(function ($billable) use ($provider, $dryRun, &$processed, &$removed, &$reactivated, &$updated, &$errors, &$skipped) {
            $processed++;
            $billableIdentifier = $this->getBillableIdentifier($billable);
            $this->info("\nChecking {$billableIdentifier}");

            try {
                // Get the local subscription
                $subscription = $billable->subscription(config('plan-usage.subscription.default_name', 'default'));

                if (! $subscription) {
                    // Only the default-type subscription controls the plan. A
                    // billable whose rows are all custom-typed (or a lifetime
                    // holder) must not lose its plan here — no-subscription
                    // enforcement belongs to EnforcePlanSubscriptionsJob.
                    $this->line('  Skipped: no default subscription to reconcile against');
                    $skipped++;

                    return;
                }

                // Handle provider-specific reconciliation
                $result = $this->reconcileWithProvider(
                    $provider,
                    $billable,
                    $subscription,
                    $dryRun
                );

                switch ($result) {
                    case 'removed':
                        $removed++;
                        break;
                    case 'reactivated':
                        $reactivated++;
                        break;
                    case 'updated':
                        $updated++;
                        break;
                    case 'skipped':
                        $skipped++;
                        break;
                    case 'error':
                        $errors++;
                        break;
                }
            } catch (\Throwable $e) {
                $this->error("  Unexpected Error: {$e->getMessage()}");

                Log::error('ReconcileSubscriptions: Unexpected error checking subscription', [
                    'billable_type' => get_class($billable),
                    'billable_id' => $billable->getKey(),
                    'provider' => $provider->name(),
                    'error' => $e->getMessage(),
                ]);

                $errors++;
            }
        });

        // Summary
        $this->newLine();
        $this->info('Reconciliation Summary:');
        $this->info("  Processed: {$processed}");

        if ($removed > 0) {
            $this->info("  Plans Removed: {$removed}");
        }
        if ($reactivated > 0) {
            $this->warn("  Reactivated: {$reactivated}");
        }
        if ($updated > 0) {
            $this->info("  Updated: {$updated}");
        }
        if ($skipped > 0) {
            $this->info("  Skipped: {$skipped}");
        }
        if ($errors > 0) {
            $this->error("  Errors: {$errors}");
        }

        if ($dryRun) {
            $this->newLine();
            $this->comment('This was a dry run. No changes were made.');
            $this->comment('Run without --dry-run to apply changes.');
        }

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Resolve a specific billing provider by name.
     */
    protected function resolveProvider(string $name): ?BillingProvider
    {
        try {
            return match ($name) {
                'stripe' => new StripeProvider,
                'paddle' => new PaddleProvider,
                'polar' => new PolarProvider,
                default => throw new \InvalidArgumentException("Unknown provider: {$name}"),
            };
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());
            $this->info('Supported providers: stripe, paddle, polar');

            return null;
        }
    }

    /**
     * Reconcile a subscription with the billing provider.
     *
     * @return string Result status: 'removed', 'reactivated', 'updated', 'skipped', or 'error'
     */
    /**
     * Converge the billable's plan to the remote subscription's price. The
     * same-plan guard in SyncPlanWithBillableAction makes this a no-op when
     * already aligned, so it is safe to call for every live subscription.
     */
    protected function syncPlanPriceFromRemote($billable, ?string $priceId, bool $dryRun, string $column): bool
    {
        if (! is_string($priceId) || $priceId === '') {
            return false;
        }

        /** @var class-string<PlanPrice> $planPriceModel */
        $planPriceModel = config('plan-usage.models.plan_price', PlanPrice::class);
        $targetPrice = $planPriceModel::query()->where($column, $priceId)->first();

        if ($targetPrice === null) {
            $this->warn("  Remote price {$priceId} has no matching plan price");

            return false;
        }

        if ((int) $billable->getAttribute('plan_price_id') === $targetPrice->id) {
            return false;
        }

        if ($dryRun) {
            $this->line("  [DRY-RUN] Would sync plan to remote price {$priceId}");

            return true;
        }

        $synced = app(SyncPlanWithBillableAction::class)->execute($billable, $targetPrice);

        if ($synced) {
            $this->info("  Synced plan to remote price {$priceId}");
        }

        return $synced;
    }

    protected function reconcileWithProvider(
        BillingProvider $provider,
        $billable,
        $subscription,
        bool $dryRun
    ): string {
        if ($provider->name() === 'stripe') {
            // Serialize with plan-change actions and webhook processing.
            return app(SubscriptionStateLock::class)->block(
                $billable,
                fn (): string => $this->reconcileStripeSubscription($billable, $subscription, $dryRun)
            );
        }

        if ($provider->name() === 'paddle') {
            // Serialize with plan-change actions and webhook processing.
            return app(SubscriptionStateLock::class)->block(
                $billable,
                fn (): string => $this->reconcilePaddleSubscription($billable, $subscription, $dryRun)
            );
        }

        if ($provider instanceof PolarProvider) {
            return $this->reconcilePolarSubscription($provider, $billable, $subscription, $dryRun);
        }

        $this->warn("  Provider {$provider->name()} reconciliation not implemented");

        return 'skipped';
    }

    protected function reconcilePolarSubscription(
        PolarProvider $provider,
        $billable,
        $subscription,
        bool $dryRun
    ): string {
        try {
            $subscriptionId = $subscription->polar_id ?? null;

            if (! is_string($subscriptionId) || $subscriptionId === '') {
                $this->warn('  No Polar subscription ID found');

                return 'skipped';
            }

            // Serialize with plan-change actions and webhook processing.
            return app(SubscriptionStateLock::class)->block($billable, function () use ($provider, $billable, $subscription, $dryRun, $subscriptionId): string {
                // Authoritative fetch inside the lock — never a pre-lock
                // snapshot, which could regrant state a concurrent webhook
                // just changed.
                $remoteSubscription = $provider->fetchSubscription($subscriptionId);

                if ($remoteSubscription === null) {
                    $this->warn('  Polar subscription could not be fetched');

                    return 'error';
                }

                $status = $remoteSubscription->status->value;
                $this->info("  Polar status: {$status}");

                if (in_array($status, ['canceled', 'unpaid'], true)
                    && $remoteSubscription->endedAt !== null
                    && CarbonImmutable::instance($remoteSubscription->endedAt)->isPast()) {
                    if (! $dryRun) {
                        app(DeleteSubscriptionAction::class)->execute($billable);
                        $this->cancelPolarPendingChanges($billable, $subscriptionId);
                    }

                    $this->warn($dryRun
                        ? '  [DRY-RUN] Would remove plan from billable'
                        : '  Removed plan from billable');

                    return 'removed';
                }

                $changed = false;

                if (! $remoteSubscription->cancelAtPeriodEnd && $subscription->ends_at !== null) {
                    if (! $dryRun) {
                        $subscription->ends_at = null;
                        $subscription->save();
                    }

                    $changed = true;
                }

                if (! $dryRun) {
                    $changed = $this->reconcilePolarPlan(
                        $billable,
                        $remoteSubscription,
                        $subscriptionId
                    ) || $changed;
                }

                return $changed ? 'updated' : 'skipped';
            });
        } catch (\Throwable $exception) {
            $this->error("  Polar Error: {$exception->getMessage()}");
            Log::error('ReconcileSubscriptions: Failed to reconcile Polar subscription', [
                'billable_type' => get_class($billable),
                'billable_id' => $billable->getKey(),
                'error' => $exception->getMessage(),
            ]);

            return 'error';
        }
    }

    protected function reconcilePolarPlan($billable, $remoteSubscription, string $subscriptionId): bool
    {
        /** @var class-string<PlanPrice> $planPriceModel */
        $planPriceModel = config('plan-usage.models.plan_price', PlanPrice::class);
        $currentPlanPrice = $planPriceModel::query()
            ->where('polar_product_id', $remoteSubscription->productId)
            ->first();
        // Shared with the webhook listener so confirmation policy (refresh /
        // apply / cancel a pending change) lives in exactly one place.
        $pendingChange = app(ConfirmPendingPlanChangeAction::class)->execute(
            $billable,
            provider: 'polar',
            providerSubscriptionId: $subscriptionId,
            currentProductId: $remoteSubscription->productId,
            pendingUpdate: $remoteSubscription->pendingUpdate !== null ? [
                'id' => $remoteSubscription->pendingUpdate->id,
                'product_id' => $remoteSubscription->pendingUpdate->productId,
                'applies_at' => $remoteSubscription->pendingUpdate->appliesAt,
            ] : null,
            providerChange: fn (PlanPrice $target) => new ProviderSubscriptionChange(
                providerSubscriptionId: $remoteSubscription->id,
                currentProductId: $remoteSubscription->productId,
                pendingProductId: null,
                periodStart: CarbonImmutable::instance($remoteSubscription->currentPeriodStart),
                periodEnd: CarbonImmutable::instance($remoteSubscription->currentPeriodEnd),
            ),
        );

        // A refreshed (still pending) or just-applied change fully explains the
        // remote state; a cancelled one falls through to the plan sync below.
        if ($pendingChange !== null && $pendingChange->status !== SubscriptionChangeStatus::Cancelled) {
            return true;
        }

        if ($currentPlanPrice !== null && (int) $billable->getAttribute('plan_price_id') !== $currentPlanPrice->id) {
            app(SyncPlanWithBillableAction::class)->execute($billable, $currentPlanPrice);

            return true;
        }

        return $pendingChange !== null;
    }

    protected function cancelPolarPendingChanges($billable, string $subscriptionId): void
    {
        $this->planChangeModel()::query()
            ->where('provider', 'polar')
            ->where('provider_subscription_id', $subscriptionId)
            ->where('status', SubscriptionChangeStatus::Pending)
            ->get()
            ->each(function (SubscriptionPlanChange $planChange) use ($billable): void {
                $planChange->markCancelled();
                Event::dispatch(new SubscriptionPlanChangeCancelled($billable, $planChange));
            });
    }

    /**
     * @return class-string<SubscriptionPlanChange>
     */
    protected function planChangeModel(): string
    {
        return config('plan-usage.models.subscription_plan_change', SubscriptionPlanChange::class);
    }

    /**
     * Reconcile a Stripe subscription.
     */
    protected function reconcileStripeSubscription($billable, $subscription, bool $dryRun): string
    {
        try {
            $subscriptionId = $subscription->stripe_id ?? null;

            if (! $subscriptionId) {
                $this->warn('  No Stripe subscription ID found');

                return 'skipped';
            }

            $this->info("  Subscription ID: {$subscriptionId}");
            $this->info('  Local ends_at: '.($subscription->ends_at ? $subscription->ends_at->toDateTimeString() : 'null'));

            // Authoritative fetch inside the lock (the dispatch wraps this
            // method in SubscriptionStateLock).
            $stripeSubscription = $subscription->asStripeSubscription();

            $this->info("  Stripe status: {$stripeSubscription->status}");
            $this->info('  Cancel at period end: '.($stripeSubscription->cancel_at_period_end ? 'Yes' : 'No'));

            if (in_array($stripeSubscription->status, ['active', 'trialing']) && ! $stripeSubscription->cancel_at_period_end) {
                $reactivated = false;

                if ($subscription->ends_at !== null) {
                    $this->warn('  Subscription was REACTIVATED in Stripe!');

                    if (! $dryRun) {
                        $subscription->ends_at = null;
                        $subscription->save();
                        $this->info('  Cleared ends_at - subscription is active');
                        Log::warning('ReconcileSubscriptions: Subscription was reactivated in Stripe', [
                            'billable_type' => get_class($billable),
                            'billable_id' => $billable->getKey(),
                            'stripe_subscription_id' => $stripeSubscription->id,
                        ]);
                    } else {
                        $this->line('  [DRY-RUN] Would clear ends_at date');
                    }

                    $reactivated = true;
                }

                // Correct plan drift left by missed/out-of-order webhooks and
                // recover missed initial checkouts (planless billable with a
                // live remote subscription).
                $synced = $this->syncPlanPriceFromRemote(
                    $billable,
                    $stripeSubscription->items->data[0]->price->id ?? null,
                    $dryRun,
                    'stripe_price_id',
                );

                if ($reactivated) {
                    return 'reactivated';
                }

                if ($synced) {
                    return 'updated';
                }

                $this->info('  Subscription status OK');

                return 'skipped';
            }

            if ($stripeSubscription->status === 'active' && $stripeSubscription->cancel_at_period_end) {
                $periodEnd = Carbon::createFromTimestamp($stripeSubscription->current_period_end);
                $oldEndsAt = $subscription->ends_at;

                // ends_at may be null when the scheduled-cancellation webhook
                // was missed — exactly the state reconciliation exists to fix.
                if ($oldEndsAt === null || ! $oldEndsAt->equalTo($periodEnd)) {
                    $this->warn('  Grace period end date mismatch');
                    $this->info('     Local: '.($oldEndsAt ? $oldEndsAt->toDateTimeString() : 'null'));
                    $this->info("     Stripe: {$periodEnd->toDateTimeString()}");

                    if (! $dryRun) {
                        $subscription->ends_at = $periodEnd;
                        $subscription->save();
                        $this->info('  Updated grace period end date');
                        Log::info('ReconcileSubscriptions: Updated grace period end date from Stripe', [
                            'billable_type' => get_class($billable),
                            'billable_id' => $billable->getKey(),
                            'old_ends_at' => $oldEndsAt,
                            'new_ends_at' => $periodEnd,
                        ]);
                    } else {
                        $this->line('  [DRY-RUN] Would update grace period end date');
                    }

                    return 'updated';
                }

                $this->info('  Grace period end date is correct');

                return 'skipped';
            }

            // Every other status follows the shared entitlement policy —
            // identical to the webhook listener, so reconciliation can never
            // disagree with live event handling.
            $decision = EntitlementStatusPolicy::decide('stripe', (string) $stripeSubscription->status);

            if ($decision !== EntitlementStatusPolicy::REVOKE) {
                $this->info("  {$stripeSubscription->status} keeps entitlements (policy)");

                return 'skipped';
            }

            $this->warn("  Stripe status {$stripeSubscription->status} does not hold entitlements");

            if (! $dryRun) {
                app(DeleteSubscriptionAction::class)->execute($billable);
                $this->info('  Removed plan from billable');
                Log::info('ReconcileSubscriptions: status does not hold entitlements, removed plan', [
                    'billable_type' => get_class($billable),
                    'billable_id' => $billable->getKey(),
                    'stripe_status' => $stripeSubscription->status,
                ]);
            } else {
                $this->line('  [DRY-RUN] Would remove plan from billable');
            }

            return 'removed';
        } catch (ApiErrorException $e) {
            $this->error("  Stripe API Error: {$e->getMessage()}");
            $this->warn('  Skipping - unable to verify with Stripe');

            Log::error('ReconcileSubscriptions: Failed to check Stripe subscription status', [
                'billable_type' => get_class($billable),
                'billable_id' => $billable->getKey(),
                'error' => $e->getMessage(),
            ]);

            return 'error';
        }
    }

    /**
     * Reconcile a Paddle subscription.
     */
    protected function reconcilePaddleSubscription($billable, $subscription, bool $dryRun): string
    {
        try {
            $subscriptionId = $subscription->paddle_id ?? $subscription->id ?? null;

            if (! $subscriptionId) {
                $this->warn('  No Paddle subscription ID found');

                return 'skipped';
            }

            $this->info("  Subscription ID: {$subscriptionId}");
            $this->info('  Local ends_at: '.($subscription->ends_at ? $subscription->ends_at->toDateTimeString() : 'null'));

            // Authoritative fetch inside the lock (the dispatch wraps this
            // method in SubscriptionStateLock).
            $paddleSubscription = $this->fetchPaddleSubscription($subscriptionId);

            if (! $paddleSubscription) {
                $this->warn('  Could not fetch subscription from Paddle API, falling back to local status');

                return $this->reconcilePaddleFromLocalStatus($billable, $subscription, $dryRun, $subscriptionId);
            }

            $paddleStatus = $paddleSubscription['status'] ?? 'unknown';
            $scheduledChange = $paddleSubscription['scheduled_change'] ?? null;
            $cancelAtPeriodEnd = $scheduledChange && ($scheduledChange['action'] ?? null) === 'cancel';

            $this->info("  Paddle status: {$paddleStatus}");
            $this->info('  Cancel at period end: '.($cancelAtPeriodEnd ? 'Yes' : 'No'));

            if (in_array($paddleStatus, ['active', 'trialing']) && ! $cancelAtPeriodEnd) {
                $reactivated = false;

                if ($subscription->ends_at) {
                    $this->warn('  Subscription was REACTIVATED in Paddle!');

                    if (! $dryRun) {
                        $subscription->ends_at = null;
                        $subscription->save();
                        $this->info('  Cleared ends_at - subscription is active');
                        Log::warning('ReconcileSubscriptions: Subscription was reactivated in Paddle', [
                            'billable_type' => get_class($billable),
                            'billable_id' => $billable->getKey(),
                            'paddle_subscription_id' => $subscriptionId,
                        ]);
                    } else {
                        $this->line('  [DRY-RUN] Would clear ends_at date');
                    }

                    $reactivated = true;
                }

                // Correct plan drift left by missed/out-of-order webhooks and
                // recover missed initial checkouts (planless billable with a
                // live remote subscription).
                $synced = $this->syncPlanPriceFromRemote(
                    $billable,
                    $paddleSubscription['items'][0]['price']['id'] ?? null,
                    $dryRun,
                    'paddle_price_id',
                );

                if ($reactivated) {
                    return 'reactivated';
                }

                if ($synced) {
                    return 'updated';
                }

                $this->info('  Subscription status OK');

                return 'skipped';
            }

            if ($paddleStatus === 'active' && $cancelAtPeriodEnd) {
                // Grace period — sync the end date from Paddle
                $periodEnd = isset($paddleSubscription['current_billing_period']['ends_at'])
                    ? Carbon::parse($paddleSubscription['current_billing_period']['ends_at'])
                    : null;

                if ($periodEnd && (! $subscription->ends_at || ! $subscription->ends_at->equalTo($periodEnd))) {
                    $this->warn('  Grace period end date mismatch');
                    $this->info('     Local: '.($subscription->ends_at ? $subscription->ends_at->toDateTimeString() : 'null'));
                    $this->info("     Paddle: {$periodEnd->toDateTimeString()}");

                    if (! $dryRun) {
                        $subscription->ends_at = $periodEnd;
                        $subscription->save();
                        $this->info('  Updated grace period end date');
                        Log::info('ReconcileSubscriptions: Updated grace period end date from Paddle', [
                            'billable_type' => get_class($billable),
                            'billable_id' => $billable->getKey(),
                            'old_ends_at' => $subscription->ends_at,
                            'new_ends_at' => $periodEnd,
                        ]);
                    } else {
                        $this->line('  [DRY-RUN] Would update grace period end date');
                    }

                    return 'updated';
                }

                $this->info('  Grace period end date is correct');

                return 'skipped';
            }

            // Every other status follows the shared entitlement policy —
            // identical to the webhook listener, so reconciliation can never
            // disagree with live event handling.
            $decision = EntitlementStatusPolicy::decide('paddle', $paddleStatus);

            if ($decision !== EntitlementStatusPolicy::REVOKE) {
                $this->info("  {$paddleStatus} keeps entitlements (policy)");

                return 'skipped';
            }

            $this->warn("  Paddle status {$paddleStatus} does not hold entitlements");

            if (! $dryRun) {
                app(DeleteSubscriptionAction::class)->execute($billable);
                $this->info('  Removed plan from billable');
                Log::info('ReconcileSubscriptions: status does not hold entitlements, removed plan', [
                    'billable_type' => get_class($billable),
                    'billable_id' => $billable->getKey(),
                    'paddle_status' => $paddleStatus,
                ]);
            } else {
                $this->line('  [DRY-RUN] Would remove plan from billable');
            }

            return 'removed';
        } catch (\Exception $e) {
            $this->error("  Paddle Error: {$e->getMessage()}");
            $this->warn('  Skipping - unable to verify with Paddle');

            Log::error('ReconcileSubscriptions: Failed to check Paddle subscription status', [
                'billable_type' => get_class($billable),
                'billable_id' => $billable->getKey(),
                'error' => $e->getMessage(),
            ]);

            return 'error';
        }
    }

    /**
     * Fetch subscription details from the Paddle API.
     */
    protected function fetchPaddleSubscription(string $subscriptionId): ?array
    {
        $apiKey = config('cashier.api_key') ?? config('plan-usage.paddle.api_key');

        if (! $apiKey) {
            return null;
        }

        $sandbox = config('cashier.sandbox', config('plan-usage.paddle.sandbox', true));
        $baseUrl = $sandbox
            ? 'https://sandbox-api.paddle.com'
            : 'https://api.paddle.com';

        try {
            $ch = curl_init();

            curl_setopt_array($ch, [
                CURLOPT_URL => "{$baseUrl}/subscriptions/{$subscriptionId}",
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    "Authorization: Bearer {$apiKey}",
                    'Content-Type: application/json',
                ],
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode >= 400 || ! $response) {
                return null;
            }

            $data = json_decode($response, true);

            return $data['data'] ?? null;
        } catch (\Exception $e) {
            Log::warning('Failed to fetch Paddle subscription', [
                'subscription_id' => $subscriptionId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Fallback reconciliation using local status when Paddle API is unavailable.
     */
    protected function reconcilePaddleFromLocalStatus($billable, $subscription, bool $dryRun, string $subscriptionId): string
    {
        $status = $subscription->status ?? 'unknown';

        $this->info("  Local status: {$status}");

        if ($status === 'canceled') {
            $this->warn("  Subscription is {$status}");

            if (! $dryRun) {
                app(DeleteSubscriptionAction::class)->execute($billable);
                $this->info('  Removed plan from billable');
            } else {
                $this->line('  [DRY-RUN] Would remove plan from billable');
            }

            return 'removed';
        }

        // Check if grace period has expired
        if ($subscription->ends_at && $subscription->ends_at->isPast()) {
            $this->warn('  Subscription grace period has ended');

            if (! $dryRun) {
                app(DeleteSubscriptionAction::class)->execute($billable);
                $this->info('  Removed plan from billable');
                Log::info('ReconcileSubscriptions: Paddle subscription grace period ended, removed plan', [
                    'billable_type' => get_class($billable),
                    'billable_id' => $billable->getKey(),
                    'paddle_subscription_id' => $subscriptionId,
                ]);
            } else {
                $this->line('  [DRY-RUN] Would remove plan from billable');
            }

            return 'removed';
        }

        $this->info('  Subscription status OK (local only - could not verify with Paddle)');

        return 'skipped';
    }

    /**
     * Get the billable model class from configuration.
     */
    protected function getBillableClass(): ?string
    {
        // Try multiple config locations for flexibility
        $class = config('plan-usage.models.billable')
            ?? config('cashier.model');

        if (! $class) {
            return null;
        }

        // Validate the class exists
        if (! class_exists($class)) {
            $this->error("Billable class {$class} does not exist.");

            return null;
        }

        return $class;
    }

    /**
     * Get a human-readable identifier for the billable.
     *
     * @param  mixed  $billable
     */
    protected function getBillableIdentifier($billable): string
    {
        $type = class_basename(get_class($billable));
        $id = $billable->getKey();

        // Try to get a name or email if available
        $name = $billable->name ?? $billable->email ?? null;

        if ($name) {
            return "{$type} #{$id} ({$name})";
        }

        return "{$type} #{$id}";
    }
}
