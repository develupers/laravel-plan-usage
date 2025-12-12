<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Commands\Subscription;

use Carbon\Carbon;
use Develupers\PlanUsage\Actions\Subscription\DeleteSubscriptionAction;
use Develupers\PlanUsage\Contracts\BillingProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

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
                            {--provider= : Override the billing provider (stripe or paddle)}';

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

        // Find billables with expired subscriptions that still have plans
        $query = $billableClass::whereNotNull('plan_id')
            ->whereHas('subscriptions', function ($query) {
                $query->whereNotNull('ends_at')
                    ->where('ends_at', '<', now());
            });

        $count = $query->count();

        if ($count === 0) {
            $this->info('No expired subscriptions found that need reconciliation');

            return Command::SUCCESS;
        }

        $this->info("Found {$count} billable(s) with expired subscriptions to check");

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

        $query->each(function ($billable) use ($provider, $dryRun, &$processed, &$removed, &$reactivated, &$updated, &$errors, &$skipped) {
            $processed++;
            $billableIdentifier = $this->getBillableIdentifier($billable);
            $this->info("\nChecking {$billableIdentifier}");

            try {
                // Get the local subscription
                $subscription = $billable->subscription('default');

                if (! $subscription) {
                    // No subscription but has plan - clean up
                    $this->warn('  No subscription record found but billable has plan');

                    if (! $dryRun) {
                        app(DeleteSubscriptionAction::class)->execute($billable);
                        $this->info('  Removed plan from billable');
                        Log::info('ReconcileSubscriptions: No subscription found, removed plan', [
                            'billable_type' => get_class($billable),
                            'billable_id' => $billable->getKey(),
                            'provider' => $provider->name(),
                        ]);
                    } else {
                        $this->line('  [DRY-RUN] Would remove plan from billable');
                    }

                    $removed++;

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
            } catch (\Exception $e) {
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
                'stripe' => new \Develupers\PlanUsage\Providers\Stripe\StripeProvider(),
                'paddle' => new \Develupers\PlanUsage\Providers\Paddle\PaddleProvider(),
                default => throw new \InvalidArgumentException("Unknown provider: {$name}"),
            };
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());
            $this->info('Supported providers: stripe, paddle');

            return null;
        }
    }

    /**
     * Reconcile a subscription with the billing provider.
     *
     * @return string Result status: 'removed', 'reactivated', 'updated', 'skipped', or 'error'
     */
    protected function reconcileWithProvider(
        BillingProvider $provider,
        $billable,
        $subscription,
        bool $dryRun
    ): string {
        if ($provider->name() === 'stripe') {
            return $this->reconcileStripeSubscription($billable, $subscription, $dryRun);
        }

        if ($provider->name() === 'paddle') {
            return $this->reconcilePaddleSubscription($billable, $subscription, $dryRun);
        }

        $this->warn("  Provider {$provider->name()} reconciliation not implemented");

        return 'skipped';
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
            $this->info("  Local ends_at: {$subscription->ends_at->toDateTimeString()}");

            // Check with Stripe for the real status
            $stripeSubscription = $subscription->asStripeSubscription();

            $this->info("  Stripe status: {$stripeSubscription->status}");
            $this->info('  Cancel at period end: ' . ($stripeSubscription->cancel_at_period_end ? 'Yes' : 'No'));

            // Handle based on Stripe status
            if (in_array($stripeSubscription->status, ['canceled', 'incomplete_expired'])) {
                $this->warn("  Stripe confirms subscription is {$stripeSubscription->status}");

                if (! $dryRun) {
                    app(DeleteSubscriptionAction::class)->execute($billable);
                    $this->info('  Removed plan from billable');
                    Log::info('ReconcileSubscriptions: Stripe confirmed cancellation, removed plan', [
                        'billable_type' => get_class($billable),
                        'billable_id' => $billable->getKey(),
                        'stripe_status' => $stripeSubscription->status,
                        'stripe_subscription_id' => $stripeSubscription->id,
                    ]);
                } else {
                    $this->line('  [DRY-RUN] Would remove plan from billable');
                }

                return 'removed';
            }

            if ($stripeSubscription->status === 'active' && ! $stripeSubscription->cancel_at_period_end) {
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

                return 'reactivated';
            }

            if ($stripeSubscription->status === 'active' && $stripeSubscription->cancel_at_period_end) {
                $periodEnd = Carbon::createFromTimestamp($stripeSubscription->current_period_end);

                if (! $subscription->ends_at->equalTo($periodEnd)) {
                    $this->warn('  Grace period end date mismatch');
                    $this->info("     Local: {$subscription->ends_at->toDateTimeString()}");
                    $this->info("     Stripe: {$periodEnd->toDateTimeString()}");

                    if (! $dryRun) {
                        $subscription->ends_at = $periodEnd;
                        $subscription->save();
                        $this->info('  Updated grace period end date');
                        Log::info('ReconcileSubscriptions: Updated grace period end date from Stripe', [
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

            // Other statuses (trialing, past_due, unpaid, paused)
            $this->warn("  Subscription has special status: {$stripeSubscription->status}");
            $this->info('  Skipping - needs manual review');

            Log::info('ReconcileSubscriptions: Subscription has special status, skipping', [
                'billable_type' => get_class($billable),
                'billable_id' => $billable->getKey(),
                'stripe_status' => $stripeSubscription->status,
            ]);

            return 'skipped';
        } catch (\Stripe\Exception\ApiErrorException $e) {
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
            $this->info("  Local ends_at: {$subscription->ends_at->toDateTimeString()}");

            // Check with Paddle for the real status
            // Note: Paddle Cashier stores status differently
            $status = $subscription->status ?? 'unknown';

            $this->info("  Local status: {$status}");

            // Handle based on Paddle status
            // Paddle statuses: active, canceled, past_due, paused, trialing
            if (in_array($status, ['canceled'])) {
                $this->warn("  Subscription is {$status}");

                if (! $dryRun) {
                    app(DeleteSubscriptionAction::class)->execute($billable);
                    $this->info('  Removed plan from billable');
                    Log::info('ReconcileSubscriptions: Paddle subscription canceled, removed plan', [
                        'billable_type' => get_class($billable),
                        'billable_id' => $billable->getKey(),
                        'paddle_status' => $status,
                        'paddle_subscription_id' => $subscriptionId,
                    ]);
                } else {
                    $this->line('  [DRY-RUN] Would remove plan from billable');
                }

                return 'removed';
            }

            if ($status === 'active' && ! $subscription->ends_at) {
                $this->warn('  Subscription is active with no end date');

                if (! $dryRun && $subscription->ends_at) {
                    $subscription->ends_at = null;
                    $subscription->save();
                    $this->info('  Cleared ends_at - subscription is active');
                }

                return 'reactivated';
            }

            if ($status === 'paused') {
                $this->warn('  Subscription is paused');
                $this->info('  Skipping - needs manual review');

                return 'skipped';
            }

            // Check if subscription should have ended
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

            $this->info('  Subscription status OK');

            return 'skipped';
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
