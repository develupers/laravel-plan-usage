<?php

declare(strict_types=1);

namespace Develupers\PlanUsage;

use Develupers\PlanUsage\Actions\Subscription\CancelSubscriptionAction;
use Develupers\PlanUsage\Actions\Subscription\CreateStripeCheckoutSessionAction;
use Develupers\PlanUsage\Actions\Subscription\DeleteSubscriptionAction;
use Develupers\PlanUsage\Actions\Subscription\SyncPlanWithBillableAction;
use Develupers\PlanUsage\Commands\PlanUsageCommand;
use Develupers\PlanUsage\Commands\PushPlansCommand;
use Develupers\PlanUsage\Commands\Stripe\PushPlansStripeCommand;
use Develupers\PlanUsage\Commands\Subscription\ReconcileSubscriptionsCommand;
use Develupers\PlanUsage\Commands\WarmCacheCommand;
use Develupers\PlanUsage\Contracts\BillingProvider;
use Develupers\PlanUsage\Providers\LemonSqueezy\LemonSqueezyProvider;
use Develupers\PlanUsage\Providers\LemonSqueezy\LemonSqueezyWebhookListener;
use Develupers\PlanUsage\Providers\Paddle\PaddleProvider;
use Develupers\PlanUsage\Providers\Paddle\PaddleWebhookListener;
use Develupers\PlanUsage\Providers\Stripe\StripeProvider;
use Develupers\PlanUsage\Providers\Stripe\StripeWebhookListener;
use Develupers\PlanUsage\Services\PlanManager;
use Develupers\PlanUsage\Services\QuotaEnforcer;
use Develupers\PlanUsage\Services\UsageTracker;
use Develupers\PlanUsage\Traits\DetectsBillingProvider;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Events\WebhookHandled;
use Laravel\Paddle\Events\WebhookReceived;
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
            return new PlanManager;
        });

        $this->app->singleton('plan-usage.tracker', function ($app) {
            return new UsageTracker;
        });

        $this->app->singleton('plan-usage.quota', function ($app) {
            return new QuotaEnforcer;
        });

        // Alias for main facade
        $this->app->singleton('plan-usage', function ($app) {
            return new PlanUsage;
        });

        // Register subscription actions as singletons
        $this->app->singleton(
            CancelSubscriptionAction::class
        );

        $this->app->singleton(
            CreateStripeCheckoutSessionAction::class
        );

        $this->app->singleton(
            DeleteSubscriptionAction::class
        );

        $this->app->singleton(
            SyncPlanWithBillableAction::class
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
            'lemon-squeezy' => $this->resolveLemonSqueezyProvider(),
            default => throw new \InvalidArgumentException(
                "Unknown billing provider: {$provider}. Supported providers are: stripe, paddle, lemon-squeezy"
            ),
        };
    }

    /**
     * Resolve the Stripe provider with validation.
     */
    protected function resolveStripeProvider(): StripeProvider
    {
        if (! class_exists(Cashier::class)) {
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
     * Resolve the LemonSqueezy provider with validation.
     */
    protected function resolveLemonSqueezyProvider(): LemonSqueezyProvider
    {
        if (! class_exists(\LemonSqueezy\Laravel\LemonSqueezy::class)) {
            throw new \RuntimeException(
                'LemonSqueezy provider configured but lemonsqueezy/laravel is not installed. '.
                'Install it with: composer require lemonsqueezy/laravel'
            );
        }

        return new LemonSqueezyProvider;
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
            'lemon-squeezy' => 'add_billable_columns_lemon_squeezy',
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
        if ($this->isStripeProvider() && class_exists(WebhookHandled::class)) {
            \Event::listen(
                WebhookHandled::class,
                StripeWebhookListener::class
            );
        }

        // Register Paddle webhook listener
        if ($this->isPaddleProvider() && class_exists(WebhookReceived::class)) {
            \Event::listen(
                WebhookReceived::class,
                PaddleWebhookListener::class
            );
        }

        // Register LemonSqueezy webhook listener
        if ($this->isLemonSqueezyProvider() && class_exists(\LemonSqueezy\Laravel\Events\WebhookHandled::class)) {
            \Event::listen(
                \LemonSqueezy\Laravel\Events\WebhookHandled::class,
                LemonSqueezyWebhookListener::class
            );
        }
    }
}
