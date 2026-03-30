<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Commands\Subscription;

use Carbon\Carbon;
use Develupers\PlanUsage\Actions\Subscription\DeleteSubscriptionAction;
use Develupers\PlanUsage\Contracts\BillingProvider;
use Develupers\PlanUsage\Providers\LemonSqueezy\LemonSqueezyProvider;
use Develupers\PlanUsage\Providers\Paddle\PaddleProvider;
use Develupers\PlanUsage\Providers\Stripe\StripeProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Cashier;
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
                            {--provider= : Override the billing provider (stripe, paddle, or lemon-squeezy)}';

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

        // Pre-fetch all subscriptions in batch to avoid per-billable API calls
        $batchSubscriptions = null;
        if ($provider->name() === 'paddle') {
            $this->info('Pre-fetching subscriptions from Paddle API...');
            $batchSubscriptions = $this->fetchAllPaddleSubscriptions();
        } elseif ($provider->name() === 'stripe') {
            $this->info('Pre-fetching subscriptions from Stripe API...');
            $batchSubscriptions = $this->fetchAllStripeSubscriptions();
        } elseif ($provider->name() === 'lemon-squeezy') {
            $this->info('Pre-fetching subscriptions from LemonSqueezy API...');
            $batchSubscriptions = $this->fetchAllLemonSqueezySubscriptions();
        }

        if ($batchSubscriptions !== null) {
            $this->info("Fetched {$batchSubscriptions->count()} subscription(s) from {$provider->name()}");
        } else {
            $this->warn("Could not pre-fetch from {$provider->name()} API, will fall back to per-subscription calls");
        }

        $query->each(function ($billable) use ($provider, $dryRun, $batchSubscriptions, &$processed, &$removed, &$reactivated, &$updated, &$errors, &$skipped) {
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
                    $dryRun,
                    $batchSubscriptions
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
                'stripe' => new StripeProvider,
                'paddle' => new PaddleProvider,
                'lemon-squeezy' => new LemonSqueezyProvider,
                default => throw new \InvalidArgumentException("Unknown provider: {$name}"),
            };
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());
            $this->info('Supported providers: stripe, paddle, lemon-squeezy');

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
        bool $dryRun,
        ?Collection $batchSubscriptions = null
    ): string {
        if ($provider->name() === 'stripe') {
            return $this->reconcileStripeSubscription($billable, $subscription, $dryRun, $batchSubscriptions);
        }

        if ($provider->name() === 'paddle') {
            return $this->reconcilePaddleSubscription($billable, $subscription, $dryRun, $batchSubscriptions);
        }

        if ($provider->name() === 'lemon-squeezy') {
            return $this->reconcileLemonSqueezySubscription($billable, $subscription, $dryRun, $batchSubscriptions);
        }

        $this->warn("  Provider {$provider->name()} reconciliation not implemented");

        return 'skipped';
    }

    /**
     * Reconcile a Stripe subscription.
     */
    protected function reconcileStripeSubscription($billable, $subscription, bool $dryRun, ?Collection $batchSubscriptions = null): string
    {
        try {
            $subscriptionId = $subscription->stripe_id ?? null;

            if (! $subscriptionId) {
                $this->warn('  No Stripe subscription ID found');

                return 'skipped';
            }

            $this->info("  Subscription ID: {$subscriptionId}");
            $this->info("  Local ends_at: {$subscription->ends_at->toDateTimeString()}");

            // Look up from pre-fetched batch first, fall back to individual API call
            $stripeSubscription = $batchSubscriptions?->get($subscriptionId)
                ?? $subscription->asStripeSubscription();

            $this->info("  Stripe status: {$stripeSubscription->status}");
            $this->info('  Cancel at period end: '.($stripeSubscription->cancel_at_period_end ? 'Yes' : 'No'));

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
    protected function reconcilePaddleSubscription($billable, $subscription, bool $dryRun, ?Collection $batchSubscriptions = null): string
    {
        try {
            $subscriptionId = $subscription->paddle_id ?? $subscription->id ?? null;

            if (! $subscriptionId) {
                $this->warn('  No Paddle subscription ID found');

                return 'skipped';
            }

            $this->info("  Subscription ID: {$subscriptionId}");
            $this->info('  Local ends_at: '.($subscription->ends_at ? $subscription->ends_at->toDateTimeString() : 'null'));

            // Look up from pre-fetched batch first, fall back to individual API call
            $paddleSubscription = $batchSubscriptions?->get($subscriptionId)
                ?? $this->fetchPaddleSubscription($subscriptionId);

            if (! $paddleSubscription) {
                $this->warn('  Could not fetch subscription from Paddle API, falling back to local status');

                return $this->reconcilePaddleFromLocalStatus($billable, $subscription, $dryRun, $subscriptionId);
            }

            $paddleStatus = $paddleSubscription['status'] ?? 'unknown';
            $scheduledChange = $paddleSubscription['scheduled_change'] ?? null;
            $cancelAtPeriodEnd = $scheduledChange && ($scheduledChange['action'] ?? null) === 'cancel';

            $this->info("  Paddle status: {$paddleStatus}");
            $this->info('  Cancel at period end: '.($cancelAtPeriodEnd ? 'Yes' : 'No'));

            // Handle based on Paddle status
            // Paddle statuses: active, canceled, past_due, paused, trialing
            if ($paddleStatus === 'canceled') {
                $this->warn('  Paddle confirms subscription is canceled');

                if (! $dryRun) {
                    app(DeleteSubscriptionAction::class)->execute($billable);
                    $this->info('  Removed plan from billable');
                    Log::info('ReconcileSubscriptions: Paddle confirmed cancellation, removed plan', [
                        'billable_type' => get_class($billable),
                        'billable_id' => $billable->getKey(),
                        'paddle_status' => $paddleStatus,
                        'paddle_subscription_id' => $subscriptionId,
                    ]);
                } else {
                    $this->line('  [DRY-RUN] Would remove plan from billable');
                }

                return 'removed';
            }

            if ($paddleStatus === 'active' && ! $cancelAtPeriodEnd) {
                // Paddle says active with no pending cancellation
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

                    return 'reactivated';
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

            if ($paddleStatus === 'paused') {
                $this->warn('  Subscription is paused');
                $this->info('  Skipping - needs manual review');

                return 'skipped';
            }

            // Other statuses (trialing, past_due)
            $this->warn("  Subscription has special status: {$paddleStatus}");
            $this->info('  Skipping - needs manual review');

            Log::info('ReconcileSubscriptions: Subscription has special status, skipping', [
                'billable_type' => get_class($billable),
                'billable_id' => $billable->getKey(),
                'paddle_status' => $paddleStatus,
            ]);

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
     * Fetch all subscriptions from the Stripe API in batch using auto-pagination.
     *
     * Returns a collection keyed by subscription ID, or null if the API is unavailable.
     */
    protected function fetchAllStripeSubscriptions(): ?Collection
    {
        try {
            $stripe = Cashier::stripe();
            $subscriptions = collect();

            // Fetch all non-draft subscriptions in a single paginated pass.
            // Stripe's 'all' status excludes only 'incomplete' — this covers
            // active, canceled, incomplete_expired, past_due, trialing, paused, unpaid.
            $params = ['limit' => 100, 'status' => 'all'];

            do {
                $response = $stripe->subscriptions->all($params);

                foreach ($response->data as $sub) {
                    $subscriptions->put($sub->id, $sub);
                }

                // Cursor-based pagination: use the last ID as starting_after
                if ($response->has_more && count($response->data) > 0) {
                    $params['starting_after'] = end($response->data)->id;
                }
            } while ($response->has_more);

            return $subscriptions;
        } catch (\Exception $e) {
            Log::warning('Failed to batch-fetch Stripe subscriptions', [
                'error' => $e->getMessage(),
            ]);

            return null;
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
     * Fetch all subscriptions from the Paddle API in batch using pagination.
     *
     * Returns a collection keyed by subscription ID, or null if the API is unavailable.
     */
    protected function fetchAllPaddleSubscriptions(): ?Collection
    {
        $apiKey = config('cashier.api_key') ?? config('plan-usage.paddle.api_key');

        if (! $apiKey) {
            return null;
        }

        $sandbox = config('cashier.sandbox', config('plan-usage.paddle.sandbox', true));
        $baseUrl = $sandbox
            ? 'https://sandbox-api.paddle.com'
            : 'https://api.paddle.com';

        $subscriptions = collect();

        try {
            // Fetch both active and canceled subscriptions
            foreach (['active', 'canceled'] as $status) {
                $url = "{$baseUrl}/subscriptions?status={$status}&per_page=200";

                while ($url) {
                    $ch = curl_init();

                    curl_setopt_array($ch, [
                        CURLOPT_URL => $url,
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
                        Log::warning('Failed to fetch Paddle subscriptions batch', [
                            'status' => $status,
                            'http_code' => $httpCode,
                        ]);

                        return null;
                    }

                    $data = json_decode($response, true);

                    foreach ($data['data'] ?? [] as $sub) {
                        if (isset($sub['id'])) {
                            $subscriptions->put($sub['id'], $sub);
                        }
                    }

                    // Follow pagination
                    $url = $data['meta']['pagination']['next'] ?? null;
                }
            }
        } catch (\Exception $e) {
            Log::warning('Failed to batch-fetch Paddle subscriptions', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return $subscriptions;
    }

    /**
     * Reconcile a LemonSqueezy subscription.
     */
    protected function reconcileLemonSqueezySubscription($billable, $subscription, bool $dryRun, ?Collection $batchSubscriptions = null): string
    {
        try {
            $subscriptionId = $subscription->lemon_squeezy_id ?? $subscription->id ?? null;

            if (! $subscriptionId) {
                $this->warn('  No LemonSqueezy subscription ID found');

                return 'skipped';
            }

            $this->info("  Subscription ID: {$subscriptionId}");
            $this->info('  Local ends_at: '.($subscription->ends_at ? $subscription->ends_at->toDateTimeString() : 'null'));

            // Look up from pre-fetched batch first, fall back to individual API call
            $lsSubscription = $batchSubscriptions?->get($subscriptionId)
                ?? $this->fetchLemonSqueezySubscription($subscriptionId);

            if (! $lsSubscription) {
                $this->warn('  Could not fetch subscription from LemonSqueezy API, falling back to local status');

                return $this->reconcileLemonSqueezyFromLocalStatus($billable, $subscription, $dryRun, $subscriptionId);
            }

            $lsStatus = $lsSubscription['attributes']['status'] ?? $lsSubscription['status'] ?? 'unknown';

            $this->info("  LemonSqueezy status: {$lsStatus}");

            // Handle based on LemonSqueezy status
            // Statuses: on_trial, active, paused, past_due, unpaid, cancelled, expired
            if (in_array($lsStatus, ['cancelled', 'expired'])) {
                $this->warn("  LemonSqueezy confirms subscription is {$lsStatus}");

                if (! $dryRun) {
                    app(DeleteSubscriptionAction::class)->execute($billable);
                    $this->info('  Removed plan from billable');
                    Log::info('ReconcileSubscriptions: LemonSqueezy confirmed cancellation, removed plan', [
                        'billable_type' => get_class($billable),
                        'billable_id' => $billable->getKey(),
                        'ls_status' => $lsStatus,
                        'ls_subscription_id' => $subscriptionId,
                    ]);
                } else {
                    $this->line('  [DRY-RUN] Would remove plan from billable');
                }

                return 'removed';
            }

            if ($lsStatus === 'active') {
                if ($subscription->ends_at) {
                    $this->warn('  Subscription was REACTIVATED in LemonSqueezy!');

                    if (! $dryRun) {
                        $subscription->ends_at = null;
                        $subscription->save();
                        $this->info('  Cleared ends_at - subscription is active');
                        Log::warning('ReconcileSubscriptions: Subscription was reactivated in LemonSqueezy', [
                            'billable_type' => get_class($billable),
                            'billable_id' => $billable->getKey(),
                            'ls_subscription_id' => $subscriptionId,
                        ]);
                    } else {
                        $this->line('  [DRY-RUN] Would clear ends_at date');
                    }

                    return 'reactivated';
                }

                $this->info('  Subscription status OK');

                return 'skipped';
            }

            if ($lsStatus === 'paused') {
                $this->warn('  Subscription is paused');
                $this->info('  Skipping - needs manual review');

                return 'skipped';
            }

            // Other statuses (on_trial, past_due, unpaid)
            $this->warn("  Subscription has special status: {$lsStatus}");
            $this->info('  Skipping - needs manual review');

            Log::info('ReconcileSubscriptions: Subscription has special status, skipping', [
                'billable_type' => get_class($billable),
                'billable_id' => $billable->getKey(),
                'ls_status' => $lsStatus,
            ]);

            return 'skipped';
        } catch (\Exception $e) {
            $this->error("  LemonSqueezy Error: {$e->getMessage()}");
            $this->warn('  Skipping - unable to verify with LemonSqueezy');

            Log::error('ReconcileSubscriptions: Failed to check LemonSqueezy subscription status', [
                'billable_type' => get_class($billable),
                'billable_id' => $billable->getKey(),
                'error' => $e->getMessage(),
            ]);

            return 'error';
        }
    }

    /**
     * Fetch a single subscription from the LemonSqueezy API.
     */
    protected function fetchLemonSqueezySubscription(string $subscriptionId): ?array
    {
        $apiKey = config('lemon-squeezy.api_key') ?? config('plan-usage.lemon-squeezy.api_key');

        if (! $apiKey) {
            return null;
        }

        try {
            $ch = curl_init();

            curl_setopt_array($ch, [
                CURLOPT_URL => "https://api.lemonsqueezy.com/v1/subscriptions/{$subscriptionId}",
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    "Authorization: Bearer {$apiKey}",
                    'Accept: application/vnd.api+json',
                    'Content-Type: application/vnd.api+json',
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
            Log::warning('Failed to fetch LemonSqueezy subscription', [
                'subscription_id' => $subscriptionId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Fetch all subscriptions from the LemonSqueezy API in batch using pagination.
     *
     * Returns a collection keyed by subscription ID, or null if the API is unavailable.
     */
    protected function fetchAllLemonSqueezySubscriptions(): ?Collection
    {
        $apiKey = config('lemon-squeezy.api_key') ?? config('plan-usage.lemon-squeezy.api_key');

        if (! $apiKey) {
            return null;
        }

        $subscriptions = collect();

        try {
            $url = 'https://api.lemonsqueezy.com/v1/subscriptions?page[size]=100';

            while ($url) {
                $ch = curl_init();

                curl_setopt_array($ch, [
                    CURLOPT_URL => $url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER => [
                        "Authorization: Bearer {$apiKey}",
                        'Accept: application/vnd.api+json',
                        'Content-Type: application/vnd.api+json',
                    ],
                ]);

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode >= 400 || ! $response) {
                    Log::warning('Failed to fetch LemonSqueezy subscriptions batch', [
                        'http_code' => $httpCode,
                    ]);

                    return null;
                }

                $data = json_decode($response, true);

                foreach ($data['data'] ?? [] as $sub) {
                    if (isset($sub['id'])) {
                        $subscriptions->put($sub['id'], $sub);
                    }
                }

                // JSON:API pagination uses links.next
                $url = $data['links']['next'] ?? null;
            }
        } catch (\Exception $e) {
            Log::warning('Failed to batch-fetch LemonSqueezy subscriptions', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return $subscriptions;
    }

    /**
     * Fallback reconciliation using local status when LemonSqueezy API is unavailable.
     */
    protected function reconcileLemonSqueezyFromLocalStatus($billable, $subscription, bool $dryRun, string $subscriptionId): string
    {
        $status = $subscription->status ?? 'unknown';

        $this->info("  Local status: {$status}");

        if (in_array($status, ['cancelled', 'expired'])) {
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
                Log::info('ReconcileSubscriptions: LemonSqueezy subscription grace period ended, removed plan', [
                    'billable_type' => get_class($billable),
                    'billable_id' => $billable->getKey(),
                    'ls_subscription_id' => $subscriptionId,
                ]);
            } else {
                $this->line('  [DRY-RUN] Would remove plan from billable');
            }

            return 'removed';
        }

        $this->info('  Subscription status OK (local only - could not verify with LemonSqueezy)');

        return 'skipped';
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
