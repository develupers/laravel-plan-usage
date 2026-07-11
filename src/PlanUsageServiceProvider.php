<?php

declare(strict_types=1);

namespace Develupers\PlanUsage;

use Danestves\LaravelPolar\Events\WebhookHandled as PolarWebhookHandled;
use Danestves\LaravelPolar\LaravelPolar;
use Develupers\PlanUsage\Actions\Subscription\ApplyPlanChangeAction;
use Develupers\PlanUsage\Actions\Subscription\CancelPendingPlanChangeAction;
use Develupers\PlanUsage\Actions\Subscription\CancelSubscriptionAction;
use Develupers\PlanUsage\Actions\Subscription\ChangeSubscriptionPlanAction;
use Develupers\PlanUsage\Actions\Subscription\CreateStripeCheckoutSessionAction;
use Develupers\PlanUsage\Actions\Subscription\DeleteSubscriptionAction;
use Develupers\PlanUsage\Actions\Subscription\SyncPlanWithBillableAction;
use Develupers\PlanUsage\Commands\EnforcePlanSubscriptionsCommand;
use Develupers\PlanUsage\Commands\PlanUsageCommand;
use Develupers\PlanUsage\Commands\PushPlansCommand;
use Develupers\PlanUsage\Commands\ResetQuotasCommand;
use Develupers\PlanUsage\Commands\Stripe\PushPlansStripeCommand;
use Develupers\PlanUsage\Commands\Subscription\ReconcileSubscriptionsCommand;
use Develupers\PlanUsage\Commands\WarmCacheCommand;
use Develupers\PlanUsage\Contracts\BillingProvider;
use Develupers\PlanUsage\Providers\Paddle\PaddleProvider;
use Develupers\PlanUsage\Providers\Paddle\PaddleWebhookListener;
use Develupers\PlanUsage\Providers\Polar\PolarProvider;
use Develupers\PlanUsage\Providers\Polar\PolarWebhookListener;
use Develupers\PlanUsage\Providers\Stripe\StripeProvider;
use Develupers\PlanUsage\Providers\Stripe\StripeWebhookListener;
use Develupers\PlanUsage\Services\PlanManager;
use Develupers\PlanUsage\Services\QuotaEnforcer;
use Develupers\PlanUsage\Services\UsageTracker;
use Develupers\PlanUsage\Traits\DetectsBillingProvider;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Events\WebhookHandled;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class PlanUsageServiceProvider extends PackageServiceProvider
{
    use DetectsBillingProvider;

    /**
     * Register any application services.
     */
    /**
     * Runs before configurePackage(): getMigrations() selects provider-specific
     * migrations from billing.provider, and package-tools only merges package
     * config afterwards — without this early merge, a fresh install (config
     * not yet published) would ignore BILLING_PROVIDER and publish the wrong
     * provider's migrations.
     */
    public function registeringPackage(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/plan-usage.php', 'plan-usage');
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        parent::register();

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
            ApplyPlanChangeAction::class
        );

        $this->app->singleton(
            ChangeSubscriptionPlanAction::class
        );

        $this->app->singleton(
            CancelPendingPlanChangeAction::class
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
            'polar' => $this->resolvePolarProvider(),
            default => throw new \InvalidArgumentException(
                "Unknown billing provider: {$provider}. Supported providers are: stripe, paddle, polar"
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
     * Resolve the Polar provider with validation.
     */
    protected function resolvePolarProvider(): PolarProvider
    {
        if (! class_exists(LaravelPolar::class)) {
            throw new \RuntimeException(
                'Polar provider configured but danestves/laravel-polar is not installed. '.
                'Install it with: composer require danestves/laravel-polar'
            );
        }

        return new PolarProvider;
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
                ResetQuotasCommand::class,
                EnforcePlanSubscriptionsCommand::class,
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
            'polar' => 'add_billable_columns_polar',
            'stripe' => 'add_billable_columns_stripe',
            default => 'add_billable_columns_stripe', // Fallback to Stripe
        };

        // Only the selected provider's price/product identifier column is created.
        $priceColumnMigration = match ($provider) {
            'paddle' => 'add_paddle_price_id_to_plan_prices',
            'polar' => 'add_polar_product_id_to_plan_prices',
            'stripe' => 'add_stripe_price_id_to_plan_prices',
            default => 'add_stripe_price_id_to_plan_prices', // Fallback to Stripe
        };

        $migrations = [
            'create_plans_table',
            'create_features_table',
            'create_plan_features_table',
            'create_plan_prices_table',
            $priceColumnMigration,
            'create_usage_table',
            'create_quotas_table',
        ];

        // Managed plan changes (ChangeSubscriptionPlanAction) record a row per
        // change, so the table is needed by every provider that implements
        // SubscriptionLifecycleProvider.
        if (in_array($provider, ['stripe', 'paddle', 'polar'], true)) {
            $migrations[] = 'create_subscription_plan_changes_table';
        }

        // Durable webhook idempotency is Polar-specific; Stripe/Paddle
        // dedupe via their Cashier webhook handlers and a cache lock instead.
        if ($provider === 'polar') {
            $migrations[] = 'create_billing_webhook_events_table';
        }

        $migrations[] = $billableMigration;
        $migrations[] = 'add_lifetime_to_plans_table';

        return $migrations;
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

        // Register Paddle webhook listener. WebhookHandled fires AFTER Cashier
        // Paddle has processed the webhook, so the local subscription row
        // exists for identity validation. Cashier fires it for created/
        // updated/paused/canceled; subscription.resumed has no Cashier handler
        // and is covered by the subscription.updated events Paddle sends
        // alongside a resume.
        if ($this->isPaddleProvider() && class_exists(\Laravel\Paddle\Events\WebhookHandled::class)) {
            \Event::listen(
                \Laravel\Paddle\Events\WebhookHandled::class,
                PaddleWebhookListener::class
            );
        }

        // Register Polar webhook listener
        if ($this->isPolarProvider() && class_exists(PolarWebhookHandled::class)) {
            \Event::listen(
                PolarWebhookHandled::class,
                PolarWebhookListener::class
            );
        }

    }
}
