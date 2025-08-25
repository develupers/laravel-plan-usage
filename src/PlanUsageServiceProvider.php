<?php

namespace Develupers\PlanUsage;

use Develupers\PlanUsage\Commands\PlanUsageCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class PlanUsageServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('plan-feature-usage')
            ->hasConfigFile('plan-feature-usage')
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
}
