# Laravel Plan Usage

[![Latest Version on Packagist](https://img.shields.io/packagist/v/develupers/laravel-plan-usage.svg?style=flat-square)](https://packagist.org/packages/develupers/laravel-plan-usage)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/develupers/laravel-plan-usage/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/develupers/laravel-plan-usage/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/develupers/laravel-plan-usage/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/develupers/laravel-plan-usage/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/develupers/laravel-plan-usage.svg?style=flat-square)](https://packagist.org/packages/develupers/laravel-plan-usage)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

A powerful Laravel package for managing subscription plans, features, quotas, and usage tracking. Perfect for SaaS applications that need flexible plan management with feature access control and usage monitoring. Seamlessly integrates with Laravel Cashier for billing with support for **both Stripe and Paddle**.

## ✨ Features

- 📊 **Flexible Plan Management** - Create and manage subscription tiers with customizable pricing
- 🎯 **Feature Access Control** - Define boolean, limit, and quota-based features
- 📈 **Usage Tracking** - Monitor and track feature consumption in real-time
- 🚦 **Quota Enforcement** - Automatic quota limits with configurable warning thresholds
- 💳 **Multi-Provider Billing** - Support for both Stripe and Paddle billing providers
- 🌍 **Paddle MoR Support** - Use Paddle as Merchant of Record for simplified tax/VAT handling
- 💱 **Multi-Currency Support** - Support for different currencies per pricing option
- 📅 **Flexible Pricing Intervals** - Daily, weekly, monthly, yearly, or lifetime pricing
- 🔄 **Periodic Reset Options** - Daily, weekly, monthly, or yearly quota resets
- 🎪 **Event-Driven Architecture** - React to usage events and quota warnings
- 🛡️ **Middleware Protection** - Route-level feature and quota enforcement
- 🏷️ **Plan Types** - Support for public, legacy, and private plans
- 📊 **Usage Analytics** - Built-in statistics and reporting capabilities

## 📋 Requirements

- PHP 8.3+
- Laravel 11.x or 12.x
- **One of the following billing packages:**
  - Laravel Cashier 15.x (for Stripe)
  - Laravel Cashier Paddle 2.x (for Paddle Billing)

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
        'warning_thresholds' => [80, 100],
    ],

    // Billing provider configuration
    'billing' => [
        // 'auto' = detect from installed package
        // 'stripe' = force Stripe provider
        // 'paddle' = force Paddle provider
        'provider' => env('BILLING_PROVIDER', 'auto'),
    ],

    // Paddle-specific configuration (only needed if using Paddle)
    'paddle' => [
        'sandbox' => env('PADDLE_SANDBOX', true),
        'seller_id' => env('PADDLE_SELLER_ID'),
        'api_key' => env('PADDLE_API_KEY'),
        'webhook_secret' => env('PADDLE_WEBHOOK_SECRET'),
        'client_side_token' => env('PADDLE_CLIENT_SIDE_TOKEN'),
    ],
];
```

## 💳 Billing Provider Setup

This package supports both **Stripe** and **Paddle** as billing providers. You can only use one provider at a time.

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

### Auto-Detection

If `BILLING_PROVIDER` is set to `auto` (or not set), the package will automatically detect which Cashier package is installed and use the appropriate provider.

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
    'stripe_product_id' => 'prod_123456',   // Stripe Product ID (if using Stripe)
    'paddle_product_id' => 'pro_01abc123',  // Paddle Product ID (if using Paddle)
    'trial_days' => 14,
    'type' => 'public',
]);

// Create pricing options for the plan
$monthlyPrice = PlanPrice::create([
    'plan_id' => $plan->id,
    'stripe_price_id' => 'price_monthly123', // Stripe Price ID
    'paddle_price_id' => 'pri_01xyz789',     // Paddle Price ID
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

```php
// Check if feature is in the plan
if ($account->hasFeature('api-calls')) {
    // Feature is included in the plan
}

// Check if account can use more of a feature
if ($account->canUseFeature('api-calls', 10)) {
    // Has enough quota for 10 more calls
}

// Record usage
$account->recordUsage('api-calls', 1);

// Check remaining quota
$remaining = $account->getRemainingQuota('api-calls');
echo "API calls remaining: {$remaining}";

// Get detailed usage information
$usage = $account->getFeatureUsage('api-calls');
echo "Limit: {$usage['limit']}, Used: {$usage['used']}, Remaining: {$usage['remaining']}";
```

## 🔍 Understanding Feature Checks

The package provides two main methods for checking features:

| Method | Purpose | Returns |
|--------|---------|---------|
| `hasFeature('api-calls')` | Check if feature is included in the plan | `true` if feature exists in plan |
| `canUseFeature('api-calls', 10)` | Check if you can use/consume more | `true` if within limits |

**Key difference**: `hasFeature()` checks plan inclusion, while `canUseFeature()` checks current availability/quota.

## 🏷️ Plan Types

Plans can be categorized by visibility/distribution type:

| Type | Description | Use Case |
|------|-------------|----------|
| **public** | Available for new subscriptions | Displayed on pricing pages |
| **legacy** | Grandfathered plans | Only for existing customers |
| **private** | Custom/enterprise plans | Negotiated individually |

```php
// Only show public plans on pricing page
$availablePlans = Plan::availableForPurchase()->get(); // active + public

// Get legacy plans for existing customers
$legacyPlans = Plan::legacy()->get();

// Check plan availability
if ($plan->isAvailableForPurchase()) {
    // Show "Subscribe" button
}
```

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

// Find plan by any of its Stripe price IDs
$plan = Plan::findByStripePriceId('price_monthly123');

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
        'check.feature' => \Develupers\PlanUsage\Http\Middleware\CheckFeature::class,
        'enforce.quota' => \Develupers\PlanUsage\Http\Middleware\CheckQuota::class,
        'track.usage' => \Develupers\PlanUsage\Http\Middleware\TrackUsage::class,
    ]);
})

// In routes/web.php
Route::get('/analytics', function () {
    // Only accessible if user has 'advanced-analytics' feature
})->middleware('check.feature:advanced-analytics');

Route::post('/api/generate', function () {
    // Automatically tracks API usage
})->middleware('track.usage:api-calls,1');
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

// Record usage with metadata
PlanUsage::record($account, 'api-calls', 10, [
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
// In your EventServiceProvider
protected $listen = [
    \Develupers\PlanUsage\Events\QuotaWarning::class => [
        \App\Listeners\SendQuotaWarningNotification::class,
    ],
    \Develupers\PlanUsage\Events\QuotaExceeded::class => [
        \App\Listeners\HandleQuotaExceeded::class,
    ],
];
```

## 💳 Billing Provider Integration

The package seamlessly integrates with Laravel Cashier for both Stripe and Paddle:

### Provider-Agnostic Methods

```php
// Get the current provider's price ID for a plan price
$priceId = $planPrice->getProviderPriceId();

// Find a plan by its provider's price ID (works with both Stripe and Paddle)
$plan = Plan::findByProviderPriceId($priceId);

// Get/set the provider's product ID
$productId = $plan->getProviderProductId();
$plan->setProviderProductId('prod_ABC123');

// Creating subscription (works with both providers)
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

# Preview what would be synced (no changes made)
php artisan plans:push --dry-run

# Force update existing products
php artisan plans:push --force
```

### Reconciling Subscriptions

If webhooks are missed, you can reconcile local subscription status with the billing provider:

```bash
# Reconcile with configured provider
php artisan subscriptions:reconcile

# Reconcile with specific provider
php artisan subscriptions:reconcile --provider=stripe
php artisan subscriptions:reconcile --provider=paddle

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
