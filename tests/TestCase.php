<?php

namespace Develupers\PlanUsage\Tests;

use Develupers\PlanUsage\PlanUsageServiceProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Develupers\\PlanUsage\\Database\\Factories\\'.class_basename($modelName).'Factory'
        );

        // Clear cache before each test to ensure isolation
        \Illuminate\Support\Facades\Cache::store('sqlite')->flush();
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

        // Set up SQLite database for cache
        config()->set('database.connections.sqlite_cache', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Configure cache to use database driver with SQLite
        config()->set('cache.stores.sqlite', [
            'driver' => 'database',
            'table' => 'cache',
            'connection' => 'sqlite_cache',
        ]);

        // Set cache configuration for tests - use SQLite database driver
        config()->set('cache.default', 'sqlite');
        config()->set('plan-usage.cache.store', 'sqlite');
        config()->set('plan-usage.cache.enabled', true); // Enable caching to test it properly
        config()->set('plan-usage.cache.use_tags', false); // Database driver doesn't support tags
        config()->set('plan-usage.tables.billable', 'test_billables');
        
        // Disable Stripe integration for tests
        config()->set('plan-usage.stripe.enabled', false);

        // Create cache table in SQLite
        Schema::connection('sqlite_cache')->create('cache', function ($table) {
            $table->string('key')->primary();
            $table->text('value');
            $table->integer('expiration');
            $table->index('expiration');
        });

        // Run migrations
        $migrations = [
            'create_plans_table',
            'create_plan_prices_table',
            'create_features_table',
            'create_plan_features_table',
            'create_usage_table',
            'create_quotas_table',
        ];

        foreach ($migrations as $migration) {
            $migrationFile = include __DIR__."/../database/migrations/{$migration}.php.stub";
            $migrationFile->up();
        }

        Schema::create('test_billables', function (Blueprint $table) {
            $table->id();
            $table->string('stripe_id')->nullable();
            $table->foreignId('plan_id')->nullable();
            $table->foreignId('plan_price_id')->nullable();
            $table->timestamp('plan_changed_at')->nullable();
        });

        // Create subscriptions table for Cashier compatibility
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('billable_type');
            $table->unsignedBigInteger('billable_id');
            $table->string('type');
            $table->string('stripe_id')->unique();
            $table->string('stripe_status');
            $table->string('stripe_price')->nullable();
            $table->integer('quantity')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
            $table->index(['billable_type', 'billable_id']);
        });

        Schema::create('subscription_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id');
            $table->string('stripe_id')->unique();
            $table->string('stripe_product');
            $table->string('stripe_price');
            $table->integer('quantity')->nullable();
            $table->timestamps();
        });
    }
}
