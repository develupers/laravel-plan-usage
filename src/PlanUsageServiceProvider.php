<?php

declare(strict_types=1);

namespace Develupers\PlanUsage;

use Develupers\PlanUsage\Commands\PlanUsageCommand;
use Develupers\PlanUsage\Commands\PushPlansCommand;
use Develupers\PlanUsage\Commands\Stripe\PushPlansStripeCommand;
use Develupers\PlanUsage\Commands\Subscription\ReconcileSubscriptionsCommand;
use Develupers\PlanUsage\Commands\WarmCacheCommand;
use Develupers\PlanUsage\Contracts\BillingProvider;
use Develupers\PlanUsage\Providers\Paddle\PaddleProvider;
use Develupers\PlanUsage\Providers\Stripe\StripeProvider;
use Develupers\PlanUsage\Traits\DetectsBillingProvider;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class PlanUsageServiceProvider extends PackageServiceProvider
{
    use DetectsBillingProvider;

    /**
     * Register any application services.
     */
    public function register(): void
    {
        parent::register();

        // Merge default configuration
        $this->mergeConfigFrom(
            __DIR__.'/../config/plan-usage.php', 'plan-usage'
        );

        // Register the billing provider
        $this->registerBillingProvider();

        // Register service bindings
        $this->app->singleton('plan-usage.manager', function ($app) {
            return new \Develupers\PlanUsage\Services\PlanManager;
        });

        $this->app->singleton('plan-usage.tracker', function ($app) {
            return new \Develupers\PlanUsage\Services\UsageTracker;
        });

        $this->app->singleton('plan-usage.quota', function ($app) {
            return new \Develupers\PlanUsage\Services\QuotaEnforcer;
        });

        // Alias for main facade
        $this->app->singleton('plan-usage', function ($app) {
            return new \Develupers\PlanUsage\PlanUsage;
        });

        // Register subscription actions as singletons
        $this->app->singleton(
            \Develupers\PlanUsage\Actions\Subscription\CancelSubscriptionAction::class
        );

        $this->app->singleton(
            \Develupers\PlanUsage\Actions\Subscription\CreateStripeCheckoutSessionAction::class
        );

        $this->app->singleton(
            \Develupers\PlanUsage\Actions\Subscription\DeleteSubscriptionAction::class
        );

        $this->app->singleton(
            \Develupers\PlanUsage\Actions\Subscription\SyncPlanWithBillableAction::class
        );
    }

    /**
     * Register the billing provider based on configuration or auto-detection.
     */
    protected function registerBillingProvider(): void
    {
        $this->app->singleton(BillingProvider::class, function ($app) {
            $provider = $this->detectBillingProvider();

            return $this->resolveProvider($provider);
        });
    }

    /**
     * Resolve a specific billing provider by name.
     */
    protected function resolveProvider(string $provider): BillingProvider
    {
        return match ($provider) {
            'stripe' => $this->resolveStripeProvider(),
            'paddle' => $this->resolvePaddleProvider(),
            default => throw new \InvalidArgumentException(
                "Unknown billing provider: {$provider}. Supported providers are: stripe, paddle"
            ),
        };
    }

    /**
     * Resolve the Stripe provider with validation.
     */
    protected function resolveStripeProvider(): StripeProvider
    {
        if (! class_exists(\Laravel\Cashier\Cashier::class)) {
            throw new \RuntimeException(
                'Stripe provider configured but laravel/cashier is not installed. '.
                'Install it with: composer require laravel/cashier'
            );
        }

        return new StripeProvider;
    }

    /**
     * Resolve the Paddle provider with validation.
     */
    protected function resolvePaddleProvider(): PaddleProvider
    {
        if (! class_exists(\Laravel\Paddle\Cashier::class)) {
            throw new \RuntimeException(
                'Paddle provider configured but laravel/cashier-paddle is not installed. '.
                'Install it with: composer require laravel/cashier-paddle'
            );
        }

        return new PaddleProvider;
    }

    /**
     * Configure the package.
     */
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('plan-usage')
            ->hasConfigFile('plan-usage')
            ->hasMigrations($this->getMigrations())
            ->hasCommands([
                PlanUsageCommand::class,
                WarmCacheCommand::class,
                ReconcileSubscriptionsCommand::class,
                PushPlansCommand::class,
                PushPlansStripeCommand::class, // Deprecated, kept for backward compatibility
            ]);
    }

    /**
     * Get the migrations to publish based on detected billing provider.
     *
     * @return array<string>
     */
    protected function getMigrations(): array
    {
        $provider = $this->detectBillingProvider();

        $billableMigration = match ($provider) {
            'paddle' => 'add_billable_columns_paddle',
            'stripe' => 'add_billable_columns_stripe',
            default => 'add_billable_columns_stripe', // Fallback to Stripe
        };

        return [
            'create_plans_table',
            'create_features_table',
            'create_plan_features_table',
            'create_plan_prices_table',
            'create_usage_table',
            'create_quotas_table',
            $billableMigration,
        ];
    }

    /**
     * Bootstrap any package services.
     */
    public function boot(): void
    {
        parent::boot();

        // Register webhook listeners based on the configured provider
        $this->registerWebhookListeners();
    }

    /**
     * Register webhook listeners based on installed/configured provider.
     */
    protected function registerWebhookListeners(): void
    {
        // Register Stripe webhook listener
        if ($this->isStripeProvider() && class_exists(\Laravel\Cashier\Events\WebhookHandled::class)) {
            \Event::listen(
                \Laravel\Cashier\Events\WebhookHandled::class,
                \Develupers\PlanUsage\Providers\Stripe\StripeWebhookListener::class
            );
        }

        // Register Paddle webhook listener
        if ($this->isPaddleProvider() && class_exists(\Laravel\Paddle\Events\WebhookReceived::class)) {
            \Event::listen(
                \Laravel\Paddle\Events\WebhookReceived::class,
                \Develupers\PlanUsage\Providers\Paddle\PaddleWebhookListener::class
            );
        }
    }
}
