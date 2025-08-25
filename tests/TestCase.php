<?php

namespace Develupers\PlanUsage\Tests;

use Illuminate\Database\Eloquent\Factories\Factory;
use Orchestra\Testbench\TestCase as Orchestra;
use Develupers\PlanUsage\PlanUsageServiceProvider;

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