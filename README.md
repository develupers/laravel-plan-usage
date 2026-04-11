# Laravel Plan Usage

[![Latest Version on Packagist](https://img.shields.io/packagist/v/develupers/laravel-plan-usage.svg?style=flat-square)](https://packagist.org/packages/develupers/laravel-plan-usage)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/develupers/laravel-plan-usage/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/develupers/laravel-plan-usage/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/develupers/laravel-plan-usage/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/develupers/laravel-plan-usage/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/develupers/laravel-plan-usage.svg?style=flat-square)](https://packagist.org/packages/develupers/laravel-plan-usage)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

A powerful Laravel package for managing subscription plans, features, quotas, and usage tracking. Perfect for SaaS applications that need flexible plan management with feature access control and usage monitoring. Seamlessly integrates with Laravel Cashier for billing with support for **Stripe, Paddle, and LemonSqueezy**.

## ✨ Features

- 📊 **Flexible Plan Management** - Create and manage subscription tiers with customizable pricing
- 🎯 **Feature Access Control** - Define boolean, limit, and quota-based features
- 📈 **Usage Tracking** - Monitor and track feature consumption in real-time
- 🚦 **Quota Enforcement** - Automatic quota limits with configurable warning thresholds
- 💳 **Multi-Provider Billing** - Support for Stripe, Paddle, and LemonSqueezy billing providers
- 🌍 **MoR Support** - Use Paddle or LemonSqueezy as Merchant of Record for simplified tax/VAT handling
- 💱 **Multi-Currency Support** - Support for different currencies per pricing option
- 📅 **Flexible Pricing Intervals** - Daily, weekly, monthly, yearly, or lifetime pricing
- 🔄 **Periodic Reset Options** - Daily, weekly, monthly, or yearly quota resets
- 🎪 **Event-Driven Architecture** - React to usage events and quota warnings
- 🛡️ **Middleware Protection** - Route-level feature and quota enforcement
- 🏷️ **Plan Types** - Support for public, legacy, and private plans
- ♾️ **Lifetime Plans** - Mark plans as lifetime to exempt them from subscription enforcement
- ⏰ **Quota Reset Scheduling** - Built-in command and job for resetting expired quotas
- 📊 **Usage Analytics** - Built-in statistics and reporting capabilities

## 📋 Requirements

- PHP 8.3+
- Laravel 11.x or 12.x
- **One of the following billing packages:**
  - Laravel Cashier 15.x (for Stripe)
  - Laravel Cashier Paddle 2.x (for Paddle Billing)
  - LemonSqueezy Laravel (for LemonSqueezy)

## 🚀 Installation

You can install the package via composer:

```bash
composer require develupers/laravel-plan-usage
```

Run the installation command to set up everything:

```bash
php artisan plan-usage:install
```

This command will:
- Publish the configuration file
- Publish database migrations
- Set up the necessary tables

Then run the migrations:

```bash
php artisan migrate
```

## ⚙️ Configuration

After installation, configure the package in `config/plan-usage.php`:

```php
return [
    'tables' => [
        'billable' => 'accounts', // Your billable model's table
        'plans' => 'plans',
        'plan_prices' => 'plan_prices',
        'features' => 'features',
        'plan_features' => 'plan_features',
        'usages' => 'usages',
        'quotas' => 'quotas',
    ],

    'cache' => [
        'enabled' => true,
        'store' => 'redis',
        'ttl' => 3600,
    ],

    'quota' => [
        'throw_exception' => true,
        'soft_limit' => false,
        'grace_percentage' => 0,
        'warning_thresholds' => [80, 100],
        'trigger_once' => false, // Only fire each threshold event once per billing period
    ],

    // Billing provider configuration
    'billing' => [
        // 'auto' = detect from installed package
        // 'stripe' = force Stripe provider
        // 'paddle' = force Paddle provider
        // 'lemon-squeezy' = force LemonSqueezy provider
        'provider' => env('BILLING_PROVIDER', 'auto'),
    ],

    // Subscription settings
    'subscription' => [
        // Default plan ID for new billables and cancelled subscriptions.
        // Set this to your free plan ID so users always have a plan.
        'default_plan_id' => env('DEFAULT_PLAN_ID', null),
    ],

    // Paddle-specific configuration (only needed if using Paddle)
    'paddle' => [
        'sandbox' => env('PADDLE_SANDBOX', true),
        'seller_id' => env('PADDLE_SELLER_ID'),
        'api_key' => env('PADDLE_API_KEY'),
        'webhook_secret' => env('PADDLE_WEBHOOK_SECRET'),
        'client_side_token' => env('PADDLE_CLIENT_SIDE_TOKEN'),
    ],

    // LemonSqueezy-specific configuration (only needed if using LemonSqueezy)
    'lemon-squeezy' => [
        'api_key' => env('LEMON_SQUEEZY_API_KEY'),
        'store' => env('LEMON_SQUEEZY_STORE'),
        'webhook_secret' => env('LEMON_SQUEEZY_SIGNING_SECRET'),
    ],
];
```

### Default Plan for New Billables

When `default_plan_id` is configured, the package automatically assigns this plan to new billable models when they're created. This is useful for:

- **Free tier assignment**: New users automatically get the free plan
- **Subscription cancellation fallback**: When a paid subscription is cancelled, users fall back to the default plan instead of having no plan

```php
// In config/plan-usage.php
'subscription' => [
    'default_plan_id' => 1, // Your Free plan ID
],

// Or via .env
DEFAULT_PLAN_ID=1
```

With this configured, you don't need to manually assign plans when creating billables:

```php
// The free plan is automatically assigned!
$account = Account::create([
    'name' => 'New Account',
    'owner_id' => $user->id,
]);

echo $account->plan->name; // "Free"
```

## 💳 Billing Provider Setup

This package supports **Stripe**, **Paddle**, and **LemonSqueezy** as billing providers. You can only use one provider at a time.

### Option 1: Stripe (Default)

Install Laravel Cashier for Stripe:

```bash
composer require laravel/cashier
```

Add to your `.env`:

```env
BILLING_PROVIDER=stripe
STRIPE_KEY=your-stripe-key
STRIPE_SECRET=your-stripe-secret
STRIPE_WEBHOOK_SECRET=your-webhook-secret
```

### Option 2: Paddle (Merchant of Record)

Paddle acts as Merchant of Record, handling all tax/VAT compliance for you. This is ideal if you want to avoid dealing with tax regulations across different countries.

Install Laravel Cashier for Paddle:

```bash
composer require laravel/cashier-paddle
```

Add to your `.env`:

```env
BILLING_PROVIDER=paddle
PADDLE_SANDBOX=true
PADDLE_SELLER_ID=your-seller-id
PADDLE_API_KEY=your-api-key
PADDLE_WEBHOOK_SECRET=your-webhook-secret
PADDLE_CLIENT_SIDE_TOKEN=your-client-token
```

### Option 3: LemonSqueezy (Merchant of Record)

LemonSqueezy acts as Merchant of Record, similar to Paddle. It uses "variants" instead of "prices" for different pricing options.

Install the LemonSqueezy Laravel package:

```bash
composer require lemonsqueezy/laravel
```

Add to your `.env`:

```env
BILLING_PROVIDER=lemon-squeezy
LEMON_SQUEEZY_API_KEY=your-api-key
LEMON_SQUEEZY_STORE=your-store-id
LEMON_SQUEEZY_SIGNING_SECRET=your-webhook-secret
```

### Auto-Detection

If `BILLING_PROVIDER` is set to `auto` (or not set), the package will automatically detect which billing package is installed and use the appropriate provider. Detection priority: Paddle > LemonSqueezy > Stripe.

### Automatic Migration Selection

The package automatically publishes the correct migration for your billable table based on the detected billing provider:

- **Stripe**: Adds `stripe_id`, `pm_type`, `pm_last_four`, `trial_ends_at` columns
- **Paddle**: Adds `paddle_id`, `trial_ends_at` columns
- **LemonSqueezy**: Adds `lemon_squeezy_id`, `trial_ends_at` columns

All migrations also add plan tracking columns: `plan_id`, `plan_price_id`, `plan_changed_at`.

> **Important**: You still need to publish and run the billing provider's own migrations separately. The package only adds the billable columns to your model's table.

```bash
# For Stripe
php artisan vendor:publish --tag=cashier-migrations

# For Paddle
php artisan vendor:publish --provider="Laravel\Paddle\CashierServiceProvider"

# For LemonSqueezy
php artisan vendor:publish --tag="lemon-squeezy-migrations"

# Then run migrations
php artisan migrate
```

## 🏁 Quick Start

### 1. Add Traits to Your Billable Model

**For Stripe:**
```php
use Develupers\PlanUsage\Traits\HasPlanFeatures;
use Laravel\Cashier\Billable;

class Account extends Model
{
    use Billable, HasPlanFeatures;
}
```

**For Paddle:**
```php
use Develupers\PlanUsage\Traits\HasPlanFeatures;
use Laravel\Paddle\Billable;

class Account extends Model
{
    use Billable, HasPlanFeatures;
}
```

**For LemonSqueezy:**
```php
use Develupers\PlanUsage\Traits\HasPlanFeatures;
use LemonSqueezy\Laravel\Billable;

class Account extends Model
{
    use Billable, HasPlanFeatures;
}
```

### 2. Create Plans and Features

```php
use Develupers\PlanUsage\Models\Plan;
use Develupers\PlanUsage\Models\PlanPrice;
use Develupers\PlanUsage\Models\Feature;
use Develupers\PlanUsage\Models\PlanFeature;

// Create a plan
$plan = Plan::create([
    'name' => 'Professional',
    'slug' => 'professional',
    'display_name' => 'Professional Plan',
    'description' => 'Perfect for growing businesses',
    'stripe_product_id' => 'prod_123456',          // Stripe Product ID (if using Stripe)
    'paddle_product_id' => 'pro_01abc123',         // Paddle Product ID (if using Paddle)
    'lemon_squeezy_product_id' => '12345',         // LemonSqueezy Product ID (if using LemonSqueezy)
    'trial_days' => 14,
    'type' => 'public',
]);

// Create pricing options for the plan
$monthlyPrice = PlanPrice::create([
    'plan_id' => $plan->id,
    'stripe_price_id' => 'price_monthly123',       // Stripe Price ID
    'paddle_price_id' => 'pri_01xyz789',           // Paddle Price ID
    'lemon_squeezy_variant_id' => '67890',         // LemonSqueezy Variant ID
    'price' => 49.00,
    'currency' => 'USD',
    'interval' => 'month',
    'is_default' => true, // Default pricing option
    'is_active' => true,
]);

$yearlyPrice = PlanPrice::create([
    'plan_id' => $plan->id,
    'stripe_price_id' => 'price_yearly456',
    'paddle_price_id' => 'pri_01abc456',
    'lemon_squeezy_variant_id' => '67891',
    'price' => 490.00, // Discounted yearly price
    'currency' => 'USD',
    'interval' => 'year',
    'is_active' => true,
]);

// Create features
$apiFeature = Feature::create([
    'name' => 'API Calls',
    'slug' => 'api-calls',
    'type' => 'quota',
    'reset_period' => 'monthly',
]);

// Link feature to plan with a value
PlanFeature::create([
    'plan_id' => $plan->id,
    'feature_id' => $apiFeature->id,
    'value' => '5000', // 5000 API calls per month
]);
```

### 3. Assign Plan to Billable

```php
$account = Account::find(1);
$account->plan_id = $plan->id;
$account->save();
```

### 4. Use Features in Your Application

There are two approaches -- pick whichever fits your use case:

**Middleware approach** (automatic, on routes):
```php
// CheckQuota gates the request, ConsumeQuota enforces + logs on success
Route::middleware(['check-quota:api-calls,1', 'consume-quota:api-calls,1'])
    ->post('/api/generate', 'ApiController@generate');
```

**Manual approach** (in your code):
```php
// consume() does everything: checks quota, increments, and logs usage
if ($account->consume('api-calls', 1, ['endpoint' => '/api/generate'])) {
    // Success -- quota was available
} else {
    // Quota exceeded
}
```

**Other useful methods:**
```php
// Check if feature is in the plan
$account->hasFeature('api-calls');

// Read-only quota check (no side effects)
$account->checkQuota('api-calls', 10);

// Log usage without quota enforcement
$account->logUsage('api-calls', 1, ['source' => 'import']);

// Check remaining quota
$remaining = $account->getRemainingQuota('api-calls');

// Get detailed usage information
$usage = $account->getFeatureUsage('api-calls');
// Returns: ['limit' => 5000, 'used' => 1250, 'remaining' => 3750]
```

## 🔍 Understanding Feature Checks

The package provides two main methods for checking features:

| Method | Purpose | Mutates quota? |
|--------|---------|----------------|
| `hasFeature('api-calls')` | Check if feature is included in the plan | No |
| `checkQuota('api-calls', 10)` | Check if quota is available for amount | No |
| `consume('api-calls', 10)` | Enforce + increment + log (the full operation) | Yes |
| `logUsage('api-calls', 10)` | Log usage only (no enforcement) | No |

**Key difference**: `hasFeature()` checks plan inclusion, `checkQuota()` checks current quota availability, and `consume()` actually uses the quota.

## 🏷️ Plan Types

Plans can be categorized by visibility and distribution type:

| Type | Description | Purchasable | Visible to |
|------|-------------|-------------|------------|
| **public** | Current plans on pricing page | Yes, self-service checkout | Everyone |
| **private** | Gated plans requiring access code, invite, or membership | Yes, with access | Invited/authorized users |
| **legacy** | Discontinued plans, grandfathered for existing subscribers | No, existing subscribers only | Current holders only |
| **hidden** | Internal/admin-only plans (lifetime deals, staff plans) | No, manually assigned | Admin only |

```php
// Only show public plans on pricing page
$availablePlans = Plan::availableForPurchase()->get(); // active + public

// Get legacy plans for existing customers
$legacyPlans = Plan::legacy()->get();

// Get hidden plans (admin use only)
$hiddenPlans = Plan::hidden()->get();

// Check plan type
if ($plan->isAvailableForPurchase()) {
    // Show "Subscribe" button
}

if ($plan->isHidden()) {
    // Only show in admin panel
}

// Plan type lifecycle: public → legacy (when retired)
// Private plans are for gated access (access codes, invitations)
// Hidden plans are never exposed to users (lifetime deals, internal use)
```

## ♾️ Lifetime Plans

Lifetime plans are plans that don't require an active billing subscription. They're ideal for one-time purchase deals, promotional offers, or manually assigned plans that should never be revoked by subscription enforcement.

### Creating a Lifetime Plan

```php
$plan = Plan::create([
    'name' => 'Growth Lifetime',
    'slug' => 'growth-lifetime',
    'display_name' => 'Growth Lifetime',
    'description' => 'Growth plan with lifetime access',
    'type' => 'hidden',        // Not shown on public pricing pages
    'is_lifetime' => true,     // Exempt from subscription enforcement
    'is_active' => true,
]);
```

### Querying Lifetime Plans

```php
// Get all lifetime plans
$lifetimePlans = Plan::lifetime()->get();

// Get plans that require an active subscription
$subscriptionPlans = Plan::requiresSubscription()->get();

// Check if a specific plan is lifetime
if ($plan->isLifetime()) {
    // Skip subscription enforcement
}
```

### How Lifetime Plans Work

| Aspect | Regular Plan | Lifetime Plan |
|--------|-------------|---------------|
| Requires subscription | Yes | No |
| Quotas reset periodically | Yes | Yes |
| Subject to plan enforcement | Yes | No |
| Shown on pricing page | If `type = public` | Typically `type = hidden` |
| Assigned via | Stripe/Paddle/LemonSqueezy checkout | Admin panel, seeder, or manual assignment |

Lifetime plans still benefit from quota resets — a lifetime plan with 8,000 monthly credits will reset to 0 used credits each month. The only difference is that the plan is never removed due to a missing subscription.

### Migration

If upgrading from a previous version, publish and run the migration:

```bash
php artisan vendor:publish --tag=plan-usage-migrations
php artisan migrate
```

This adds the `is_lifetime` boolean column (default: `false`) to the plans table.

## 💰 Pricing Structure

Plans support multiple pricing options with different intervals and currencies:

### Creating Multiple Prices

```php
// Plans can have multiple pricing options
$plan = Plan::find(1);

// Monthly pricing
$plan->prices()->create([
    'stripe_price_id' => 'price_monthly',
    'price' => 29.00,
    'currency' => 'USD',
    'interval' => 'month',
    'is_default' => true,
]);

// Annual pricing with discount
$plan->prices()->create([
    'stripe_price_id' => 'price_yearly',
    'price' => 290.00, // Save $58!
    'currency' => 'USD',
    'interval' => 'year',
]);

// Lifetime deal
$plan->prices()->create([
    'stripe_price_id' => 'price_lifetime',
    'price' => 999.00,
    'currency' => 'USD',
    'interval' => 'lifetime',
]);
```

### Working with Prices

```php
// Get default price
$defaultPrice = $plan->defaultPrice;

// Get price by interval
$monthlyPrice = $plan->getMonthlyPrice();
$yearlyPrice = $plan->getYearlyPrice();
$customPrice = $plan->getPriceByInterval('week');

// Get all active prices
$activePrices = $plan->activePrices;

// Find plan by any provider price ID (auto-detects current provider)
$plan = Plan::findByProviderPriceId('price_monthly123');

// Calculate savings
$yearlyPrice = $plan->getYearlyPrice();
$monthlyPrice = $plan->getMonthlyPrice();
$savings = $yearlyPrice->calculateSavings($monthlyPrice);
echo "Save {$savings}% with yearly billing!";
```

### Supported Intervals

| Interval | Description |
|----------|-------------|
| `day` | Daily billing |
| `week` | Weekly billing |
| `month` | Monthly billing |
| `year` | Annual billing |
| `lifetime` | One-time payment |

## 🎯 Feature Types

The package supports three types of features:

| Type | Description | Example |
|------|-------------|---------|
| **Boolean** | On/off features | Advanced Analytics, Priority Support |
| **Limit** | Maximum allowed quantity | Max Projects, Team Members |
| **Quota** | Usage-based with periodic reset | API Calls, Storage, Bandwidth |

## 🛡️ Middleware

Protect your routes with built-in middleware:

```php
// In bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    $middleware->alias([
        'check-feature' => \Develupers\PlanUsage\Http\Middleware\CheckFeature::class,
        'check-quota' => \Develupers\PlanUsage\Http\Middleware\CheckQuota::class,
        'consume-quota' => \Develupers\PlanUsage\Http\Middleware\ConsumeQuota::class,
    ]);
})

// In routes/web.php
Route::get('/analytics', function () {
    // Only accessible if user has 'advanced-analytics' feature
})->middleware('check-feature:advanced-analytics');

Route::post('/api/generate', function () {
    // Enforces quota, increments, and logs usage on success
})->middleware('consume-quota:api-calls,1');

// Gate + consume (check first, consume after success)
Route::middleware(['check-quota:api-calls,5', 'consume-quota:api-calls,5'])
    ->post('/api/bulk', 'ApiController@bulk');
```

## 📊 Usage Tracking & Analytics

### Feature Usage Details

```php
// Get comprehensive usage details for a feature
$usage = $account->getFeatureUsage('api-calls');
// Returns: ['limit' => 5000, 'used' => 1250, 'remaining' => 3750]

// Get usage percentage
$percentage = $account->getFeatureUsagePercentage('api-calls');
echo "You've used {$percentage}% of your API calls";

// Get all features status
$featuresStatus = $account->getFeaturesStatus();
foreach ($featuresStatus as $status) {
    echo "{$status['name']}: {$status['used']}/{$status['limit']}\n";
}
```

### Track Usage

```php
use Develupers\PlanUsage\Facades\PlanUsage;

// Consume: enforce quota + increment + log (returns false if exceeded)
PlanUsage::consume($account, 'api-calls', 10, [
    'endpoint' => '/api/generate',
    'ip' => $request->ip(),
]);

// Get usage history
$history = PlanUsage::usage()->getHistory($account, 'api-calls');

// Get usage statistics
$stats = PlanUsage::usage()->getStatistics(
    $account,
    'api-calls',
    now()->subMonth(),
    now(),
    'day'
);
```

### Quota Management

```php
// Get current usage
$used = PlanUsage::quotas()->getUsed($account, 'api-calls');

// Get remaining quota
$remaining = PlanUsage::quotas()->getRemaining($account, 'api-calls');

// Get usage percentage
$percentage = PlanUsage::quotas()->getUsagePercentage($account, 'api-calls');

// Enforce quota (returns false if would exceed)
$canProceed = PlanUsage::quotas()->enforce($account, 'api-calls', 10);
```

## 🎪 Events

The package dispatches events you can listen to:

- `UsageRecorded` - When usage is recorded
- `QuotaWarning` - When usage reaches warning threshold (80%, 100%)
- `QuotaExceeded` - When quota limit is exceeded

```php
// In your EventServiceProvider or via Laravel auto-discovery
protected $listen = [
    \Develupers\PlanUsage\Events\QuotaWarning::class => [
        \App\Listeners\SendQuotaWarningNotification::class,
    ],
    \Develupers\PlanUsage\Events\QuotaExceeded::class => [
        \App\Listeners\HandleQuotaExceeded::class,
    ],
];
```

### Event Deduplication (`trigger_once`)

By default, `QuotaWarning` and `QuotaExceeded` events fire on **every** `consume()` call where usage exceeds a threshold. This can result in duplicate notifications (e.g., an email on every API request after 80% usage).

Set `trigger_once` to `true` to fire each event only once per billing period:

```php
// config/plan-usage.php
'quota' => [
    'warning_thresholds' => [80, 100],
    'trigger_once' => true,
],
```

When enabled, the package uses a cache key scoped to the billable, feature, and threshold. The key expires at the quota's `reset_at` timestamp (or 24 hours if no reset period is configured). Each threshold fires independently — crossing 80% sends one event, and later crossing 100% sends another.

This is handled at the package level, so your listeners don't need any deduplication logic.

## 💳 Billing Provider Integration

The package seamlessly integrates with Laravel Cashier for both Stripe and Paddle:

### Provider-Agnostic Methods

```php
// Get the current provider's price ID for a plan price
$priceId = $planPrice->getProviderPriceId();

// Find a plan by its provider's price ID (works with Stripe, Paddle, and LemonSqueezy)
$plan = Plan::findByProviderPriceId($priceId);

// Get/set the provider's product ID
$productId = $plan->getProviderProductId();
$plan->setProviderProductId('prod_ABC123');

// Creating subscription (works with all providers)
$billable->newSubscription('default', $planPrice->getProviderPriceId())->create();
```

### Syncing Plans to Billing Provider

Use the unified `plans:push` command to sync your local plans to the billing provider:

```bash
# Sync to configured provider (auto-detected)
php artisan plans:push

# Sync to specific provider
php artisan plans:push --provider=stripe
php artisan plans:push --provider=paddle
php artisan plans:push --provider=lemon-squeezy

# Preview what would be synced (no changes made)
php artisan plans:push --dry-run

# Force update existing products
php artisan plans:push --force
```

### Resetting Expired Quotas

Quotas with a reset period (daily, weekly, monthly, yearly) need to be periodically reset. The package provides a command and a queued job for this.

**Command:**

```bash
# Run synchronously
php artisan plan-usage:reset-quotas

# Dispatch as a queued job
php artisan plan-usage:reset-quotas --dispatch
```

**Schedule it** in your `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

// Reset expired quotas every hour
Schedule::command('plan-usage:reset-quotas --dispatch')->hourly();
```

**Dispatch the job directly** from your code:

```php
use Develupers\PlanUsage\Jobs\ResetExpiredQuotasJob;

// Queued
ResetExpiredQuotasJob::dispatch();

// Synchronous
ResetExpiredQuotasJob::dispatchSync();
```

The job finds all quotas where `reset_at` has passed and `used > 0`, resets the usage to 0, and sets the next `reset_at` date based on the feature's reset period.

### Reconciling Subscriptions

If webhooks are missed, you can reconcile local subscription status with the billing provider:

```bash
# Reconcile with configured provider
php artisan subscriptions:reconcile

# Reconcile with specific provider
php artisan subscriptions:reconcile --provider=stripe
php artisan subscriptions:reconcile --provider=paddle
php artisan subscriptions:reconcile --provider=lemon-squeezy

# Preview changes
php artisan subscriptions:reconcile --dry-run
```

### Stripe-Specific

```php
// Plans connect to Stripe Products
$plan->stripe_product_id = 'prod_ABC123';

// Each price connects to Stripe Prices
$price->stripe_price_id = 'price_XYZ789';
```

### Paddle-Specific

```php
// Plans connect to Paddle Products
$plan->paddle_product_id = 'pro_01ABC123';

// Each price connects to Paddle Prices
$price->paddle_price_id = 'pri_01XYZ789';
```

### LemonSqueezy-Specific

```php
// Plans connect to LemonSqueezy Products
$plan->lemon_squeezy_product_id = '12345';

// Each price connects to LemonSqueezy Variants
$price->lemon_squeezy_variant_id = '67890';
```

## 🔄 Plan Comparison

Compare plans to show upgrade benefits:

```php
$comparison = PlanUsage::plans()->comparePlans($currentPlanId, $newPlanId);

foreach ($comparison as $featureSlug => $data) {
    echo "{$data['feature']}: ";
    echo "{$data['plan1']} → {$data['plan2']}";
    if ($data['difference'] > 0) {
        echo " (+{$data['difference']})";
    }
}
```

## 🧪 Testing

Run the test suite:

```bash
composer test
```

Run tests with coverage:

```bash
composer test-coverage
```

Format code:

```bash
composer format
```

Static analysis:

```bash
composer analyse
```

## 📚 Documentation

For detailed documentation, see:

- [Installation Guide](docs/INSTALLATION.md) - Complete setup instructions
- [User Guide](docs/USER_GUIDE.md) - Comprehensive usage documentation
- [Quick Reference](docs/QUICK_REFERENCE.md) - Common operations cheatsheet

## 🤝 Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details on how to contribute to this project.

### Development Setup

1. Clone the repository
2. Install dependencies: `composer install`
3. Run tests: `composer test`
4. Check code style: `composer format`

## 🔒 Security

If you discover any security-related issues, please email security@develupers.com instead of using the issue tracker.

## 📝 Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## 👥 Credits

- [Omar Robinson](https://github.com/orobinson)
- [All Contributors](../../contributors)

## 📄 License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

## 💪 Support

Need help or have questions? Feel free to:

- 📖 Check the [documentation](docs/)
- 🐛 [Report bugs](https://github.com/develupers/laravel-plan-usage/issues)
- 💡 [Request features](https://github.com/develupers/laravel-plan-usage/issues)
- ⭐ Star the repository if you find it useful!

---

Built with ❤️ by [Develupers](https://develupers.com)
