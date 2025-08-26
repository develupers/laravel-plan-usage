<?php

declare(strict_types=1);

namespace Develupers\PlanUsage;

use Develupers\PlanUsage\Commands\PlanUsageCommand;
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
                'create_usage_table',
                'create_quotas_table',
                'add_billable_columns',
            ])
            ->hasCommand(PlanUsageCommand::class);
    }

    /**
     * Bootstrap any package services.
     */
    public function boot(): void
    {
        parent::boot();

        // Publish configuration
        $this->publishes([
            __DIR__.'/../config/plan-usage.php' => config_path('plan-usage.php'),
        ], 'plan-usage-config');

        // Publish migrations
        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'plan-usage-migrations');
    }
}
