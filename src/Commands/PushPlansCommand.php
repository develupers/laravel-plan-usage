<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Commands;

use Develupers\PlanUsage\Contracts\BillingProvider;
use Develupers\PlanUsage\Models\Plan;
use Develupers\PlanUsage\Models\PlanPrice;
use Illuminate\Console\Command;

/**
 * Unified command to sync local plans to the configured billing provider.
 *
 * This command delegates to the appropriate provider (Stripe or Paddle)
 * based on the configuration.
 */
class PushPlansCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'plans:push
                            {--provider= : Override the billing provider (stripe or paddle)}
                            {--force : Force update existing products}
                            {--dry-run : Show what would be created without actually creating}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync local plans to the billing provider (Stripe or Paddle)';

    /**
     * Execute the console command.
     */
    public function handle(BillingProvider $defaultProvider): int
    {
        $providerName = $this->option('provider');
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');

        // Get the provider to use
        $provider = $providerName
            ? $this->resolveProvider($providerName)
            : $defaultProvider;

        if (! $provider) {
            return 1;
        }

        // Check if the provider is installed
        if (! $provider->isInstalled()) {
            $this->error("The {$provider->name()} provider is not installed.");
            $this->info($provider->name() === 'stripe'
                ? 'Install it with: composer require laravel/cashier'
                : 'Install it with: composer require laravel/cashier-paddle');

            return 1;
        }

        // Load all plans
        $plans = Plan::with('prices')->get();

        if ($plans->isEmpty()) {
            $this->error('No plans found in database. Please create some plans first.');

            return 1;
        }

        $this->info("Syncing {$plans->count()} plans to {$provider->name()}...");

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        // Delegate to the provider
        $results = $provider->syncProducts($plans, [
            'dry_run' => $dryRun,
            'force' => $force,
        ]);

        // Display results
        $this->displayResults($results, $provider, $dryRun, $plans);

        return empty($results['errors']) ? 0 : 1;
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
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return null;
        }
    }

    /**
     * Display sync results.
     */
    protected function displayResults(array $results, BillingProvider $provider, bool $dryRun, $plans): void
    {
        $this->newLine();

        // Show created
        if (! empty($results['created'])) {
            $this->info('Created:');
            foreach ($results['created'] as $item) {
                if (is_array($item)) {
                    $this->line("  - {$item['plan']}" . ($dryRun ? ' (dry run)' : ''));
                } else {
                    $this->line("  - {$item}");
                }
            }
        }

        // Show updated
        if (! empty($results['updated'])) {
            $this->info('Updated:');
            foreach ($results['updated'] as $item) {
                if (is_array($item)) {
                    $message = $item['plan'] ?? 'unknown';
                    if (isset($item['skipped'])) {
                        $message .= " ({$item['skipped']})";
                    }
                    $this->line("  - {$message}");
                } else {
                    $this->line("  - {$item}");
                }
            }
        }

        // Show errors
        if (! empty($results['errors'])) {
            $this->error('Errors:');
            foreach ($results['errors'] as $error) {
                if (is_array($error)) {
                    $this->line("  - {$error['plan']}: {$error['error']}");
                } else {
                    $this->line("  - {$error}");
                }
            }
        }

        // Summary
        $this->newLine();
        if ($dryRun) {
            $this->warn('DRY RUN COMPLETE - No changes were made');
            $this->info('Run without --dry-run to actually sync to ' . $provider->name());
        } else {
            if (empty($results['errors'])) {
                $this->info('All plans synced successfully to ' . $provider->name() . '!');
            } else {
                $this->warn('Sync completed with some errors.');
            }

            // Display summary table
            $priceIdColumn = $provider->getPriceIdColumn();
            $productIdColumn = $provider->getProductIdColumn();

            $this->newLine();
            $this->table(
                ['Plan', 'Product ID', 'Price Interval', 'Price ID'],
                $plans->flatMap(function ($plan) use ($priceIdColumn, $productIdColumn) {
                    return $plan->prices->map(function (PlanPrice $planPrice) use ($plan, $priceIdColumn, $productIdColumn) {
                        return [
                            $plan->name,
                            $plan->{$productIdColumn} ?? 'N/A',
                            $planPrice->interval->value,
                            $planPrice->{$priceIdColumn} ?? 'N/A',
                        ];
                    });
                })
            );
        }
    }
}
