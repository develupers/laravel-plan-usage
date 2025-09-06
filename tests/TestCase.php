<?php

namespace Develupers\PlanUsage\Tests;

use Develupers\PlanUsage\PlanUsageServiceProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Develupers\\PlanUsage\\Database\\Factories\\'.class_basename($modelName).'Factory'
        );
    }

    protected function getPackageProviders($app)
    {
        return [
            PlanUsageServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');
        
        // Set cache configuration for tests
        config()->set('cache.default', 'array');
        config()->set('plan-usage.cache.store', 'array');
        config()->set('plan-usage.cache.use_tags', false); // Array driver doesn't support tags

        // Run migrations
        $migrations = [
            'create_plans_table',
            'create_features_table',
            'create_plan_features_table',
            'create_usage_table',
            'create_quotas_table',
        ];

        foreach ($migrations as $migration) {
            $migrationFile = include __DIR__."/../database/migrations/{$migration}.php.stub";
            $migrationFile->up();
        }
    }
}
