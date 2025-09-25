<?php

declare(strict_types=1);

namespace Develupers\PlanUsage;

use Develupers\PlanUsage\Commands\PlanUsageCommand;
use Develupers\PlanUsage\Commands\Stripe\PushPlansStripeCommand;
use Develupers\PlanUsage\Commands\Subscription\ReconcileSubscriptionsCommand;
use Develupers\PlanUsage\Commands\WarmCacheCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class PlanUsageServiceProvider extends PackageServiceProvider
{
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
            ->hasMigrations([
                'create_plans_table',
                'create_features_table',
                'create_plan_features_table',
                'create_plan_prices_table',
                'create_usage_table',
                'create_quotas_table',
                'add_billable_columns',
            ])
            ->hasCommands([
                PlanUsageCommand::class,
                WarmCacheCommand::class,
                ReconcileSubscriptionsCommand::class,
                PushPlansStripeCommand::class,
            ]);
    }

    /**
     * Bootstrap any package services.
     */
    public function boot(): void
    {
        parent::boot();

        // Register event listeners if Laravel Cashier is installed
        if (class_exists('Laravel\Cashier\Events\WebhookHandled')) {
            \Event::listen(
                \Laravel\Cashier\Events\WebhookHandled::class,
                \Develupers\PlanUsage\Listeners\SyncBillablePlanFromStripe::class
            );
        }
    }
}
