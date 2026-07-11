<?php

namespace Develupers\PlanUsage\Tests;

use Develupers\PlanUsage\PlanUsageServiceProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
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
        Cache::store('sqlite')->flush();
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

        // Pin the provider: with laravel/cashier-paddle in require-dev, 'auto'
        // detection would resolve to paddle and silently flip provider-aware
        // helpers (findByProviderPriceId, etc.) for every legacy test. Paddle
        // tests opt in via config()->set('plan-usage.billing.provider', 'paddle').
        config()->set('plan-usage.billing.provider', 'stripe');

        // Disable Stripe integration for tests
        config()->set('plan-usage.stripe.enabled', false);

        // Create cache table in SQLite
        Schema::connection('sqlite_cache')->create('cache', function ($table) {
            $table->string('key')->primary();
            $table->text('value');
            $table->integer('expiration');
            $table->index('expiration');
        });

        Schema::connection('sqlite_cache')->create('cache_locks', function ($table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration');
        });

        // Run migrations
        // The test database exercises every provider, so run all of the
        // per-provider price-column migrations (in production only the selected
        // provider's column is installed — see PlanUsageServiceProvider::getMigrations()).
        $migrations = [
            'create_plans_table',
            'create_plan_prices_table',
            'add_stripe_price_id_to_plan_prices',
            'add_paddle_price_id_to_plan_prices',
            'add_lemon_squeezy_variant_id_to_plan_prices',
            'add_polar_product_id_to_plan_prices',
            'create_features_table',
            'create_plan_features_table',
            'create_usage_table',
            'create_quotas_table',
            'create_subscription_plan_changes_table',
            'create_billing_webhook_events_table',
            // Runs after create_plans_table (which already has is_lifetime) to
            // exercise the fresh-install publish combination — must be a no-op.
            'add_lifetime_to_plans_table',
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

        Schema::create('polar_customers', function (Blueprint $table) {
            $table->id();
            $table->morphs('billable');
            $table->string('polar_id')->nullable()->unique();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamps();
        });

        Schema::create('polar_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->morphs('billable');
            $table->string('type');
            $table->string('polar_id')->unique();
            $table->string('status');
            $table->string('product_id');
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
        });
    }
}
