<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Plan Feature Usage Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains the configuration options for the Laravel Plan Feature
    | Usage package, which manages subscription plans, features, quotas, and
    | usage tracking with billing provider integration (Stripe or Paddle).
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Billing Provider
    |--------------------------------------------------------------------------
    |
    | Configure which billing provider to use. Supports 'stripe', 'paddle',
    | or 'auto' to auto-detect based on installed Cashier package.
    |
    | When set to 'auto', the package will check for installed packages:
    | - If laravel/cashier-paddle is installed, Paddle will be used
    | - If laravel/cashier is installed, Stripe will be used
    | - If neither is installed, an error will be thrown
    |
    */
    'billing' => [
        'provider' => env('BILLING_PROVIDER', 'auto'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | You can specify custom model classes if you need to extend the default
    | models provided by the package.
    |
    | The 'billable' model is your User, Account, Team, or Organization model
    | that will have subscriptions and be charged.
    |
    */
    'models' => [
        'billable' => null, // e.g., App\Models\User::class or App\Models\Account::class
        'plan' => \Develupers\PlanUsage\Models\Plan::class,
        'plan_price' => \Develupers\PlanUsage\Models\PlanPrice::class,
        'feature' => \Develupers\PlanUsage\Models\Feature::class,
        'plan_feature' => \Develupers\PlanUsage\Models\PlanFeature::class,
        'usage' => \Develupers\PlanUsage\Models\Usage::class,
        'quota' => \Develupers\PlanUsage\Models\Quota::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Table Names
    |--------------------------------------------------------------------------
    |
    | You can customize the table names used by the package if needed.
    |
    */
    'tables' => [
        'plans' => 'plans',
        'plan_prices' => 'plan_prices',
        'features' => 'features',
        'plan_features' => 'plan_features',
        'usages' => 'usages',
        'quotas' => 'quotas',
        'billable' => 'users', // Default billable table - e.g. 'accounts', 'users', 'workspace'
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Configuration
    |--------------------------------------------------------------------------
    |
    | Configure caching for quota checks and usage tracking to improve
    | performance.
    |
    */
    'cache' => [
        'enabled' => env('PLAN_USAGE_CACHE_ENABLED', false),
        'store' => env('PLAN_USAGE_CACHE_STORE', 'database'), // Can be any Laravel cache store (file, database, redis, memcached, etc.)
        'prefix' => 'plan_feature_usage',

        // Granular TTL settings for different cache types (in seconds)
        'ttl' => [
            'default' => 3600,      // Default TTL for all cache
            'plans' => 86400,       // Plans are relatively static (24 hours)
            'features' => 86400,    // Features are relatively static (24 hours)
            'quotas' => 300,        // Quotas are more dynamic (5 minutes)
            'usage' => 60,          // Usage data changes frequently (1 minute)
        ],

        // Selective caching - enable/disable caching for specific features
        'selective' => [
            'plans' => true,        // Cache plan data
            'features' => true,     // Cache feature data
            'quotas' => true,       // Cache quota data
            'usage' => false,       // Don't cache usage data by default (too dynamic)
        ],

        // Use cache tags if supported by the driver (redis, memcached)
        'use_tags' => true,

        // Automatically warm cache after deployments
        'warm_on_deploy' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Quota Enforcement
    |--------------------------------------------------------------------------
    |
    | Configure how quotas are enforced in your application.
    |
    */
    'quota' => [
        // Throw exception when quota exceeded
        'throw_exception' => true,

        // Grace period percentage (e.g., allow 10% over limit before hard stop)
        'grace_period' => 0,

        // Enable soft limits to allow grace period
        'soft_limit' => false,

        // Grace percentage - how much over the limit to allow (e.g., 10 = 10% over limit)
        'grace_percentage' => 10,

        // Send notifications at these usage percentages
        'warning_thresholds' => [80, 100],
    ],

    /*
    |--------------------------------------------------------------------------
    | Usage Tracking
    |--------------------------------------------------------------------------
    |
    | Configure how usage is tracked and aggregated.
    |
    */
    'usage' => [
        // Automatically aggregate usage data
        'auto_aggregate' => true,

        // Aggregation periods (daily, weekly, monthly, yearly)
        'aggregation_periods' => ['daily', 'monthly'],

        // Keep raw usage records for this many days (null = forever)
        'retention_days' => 90,

        // Track usage in real-time or batch
        'mode' => 'real-time', // 'real-time' or 'batch'

        // Merge metadata from multiple usage records
        'merge_metadata' => false,

        // Aggregate usage records from the same period when calculating statistics
        'aggregate_same_period' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Feature Types
    |--------------------------------------------------------------------------
    |
    | Define the types of features your application supports.
    |
    */
    'feature_types' => [
        'boolean' => [
            'label' => 'Boolean Feature',
            'description' => 'Feature that is either enabled or disabled',
        ],
        'limit' => [
            'label' => 'Numeric Limit',
            'description' => 'Feature with a numeric limit (e.g., max projects)',
        ],
        'quota' => [
            'label' => 'Usage Quota',
            'description' => 'Feature with usage tracking and limits (e.g., API calls)',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Reset Periods
    |--------------------------------------------------------------------------
    |
    | Define when different types of quotas should reset.
    |
    */
    'reset_periods' => [
        'daily' => [
            'label' => 'Daily',
            'cron' => '0 0 * * *', // Midnight every day
        ],
        'weekly' => [
            'label' => 'Weekly',
            'cron' => '0 0 * * 1', // Monday at midnight
        ],
        'monthly' => [
            'label' => 'Monthly',
            'cron' => '0 0 1 * *', // First day of month at midnight
        ],
        'yearly' => [
            'label' => 'Yearly',
            'cron' => '0 0 1 1 *', // January 1st at midnight
        ],
        'never' => [
            'label' => 'Never',
            'cron' => null, // Never resets
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Subscription Settings
    |--------------------------------------------------------------------------
    |
    | Configure subscription-related settings for your application.
    |
    */
    'subscription' => [
        // Default plan ID to assign when subscription is deleted
        'default_plan_id' => null,

        // Clear usage records when subscription is deleted
        'clear_usage_on_delete' => false,

        // Clear provider customer data when subscription is deleted
        'clear_provider_data_on_delete' => false,

        // Default subscription name
        'default_name' => 'default',
    ],

    /*
    |--------------------------------------------------------------------------
    | Checkout Configuration
    |--------------------------------------------------------------------------
    |
    | Configure checkout settings for the billing provider.
    |
    */
    'checkout' => [
        // Success URL after checkout (can include {CHECKOUT_SESSION_ID} placeholder)
        'success_url' => null,

        // Cancel URL for checkout
        'cancel_url' => null,

        // Allow promotion codes in checkout
        'allow_promotion_codes' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Stripe Integration
    |--------------------------------------------------------------------------
    |
    | Configure Stripe integration for metered billing and usage reporting.
    | These settings are only used when billing.provider is 'stripe' or 'auto'
    | and laravel/cashier is installed.
    |
    */
    'stripe' => [
        // Enable Stripe metered billing integration
        'enabled' => true,

        // Report usage to Stripe for metered billing
        'report_usage' => true,

        // Sync plans from Stripe
        'sync_plans' => false,

        // Webhook tolerance in seconds (for webhook signature verification)
        'webhook_tolerance' => 300,

        // Webhook secret for signature verification
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Paddle Integration
    |--------------------------------------------------------------------------
    |
    | Configure Paddle Billing integration for subscriptions and payments.
    | These settings are only used when billing.provider is 'paddle' or 'auto'
    | and laravel/cashier-paddle is installed.
    |
    | Note: Paddle acts as Merchant of Record, handling all tax/VAT compliance.
    |
    */
    'paddle' => [
        // Use Paddle sandbox environment
        'sandbox' => env('PADDLE_SANDBOX', true),

        // Paddle seller ID
        'seller_id' => env('PADDLE_SELLER_ID'),

        // Paddle API key
        'api_key' => env('PADDLE_API_KEY'),

        // Webhook secret for signature verification
        'webhook_secret' => env('PADDLE_WEBHOOK_SECRET'),

        // Client-side token for Paddle.js
        'client_side_token' => env('PADDLE_CLIENT_SIDE_TOKEN'),

        // Retain pricing from Paddle (vs using local prices)
        'retain_paddle_pricing' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Middleware
    |--------------------------------------------------------------------------
    |
    | Register middleware aliases for the package.
    |
    */
    'middleware' => [
        'check-feature' => \Develupers\PlanUsage\Http\Middleware\CheckFeature::class,
        'enforce-quota' => \Develupers\PlanUsage\Http\Middleware\CheckQuota::class,
        'track-usage' => \Develupers\PlanUsage\Http\Middleware\TrackUsage::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Events
    |--------------------------------------------------------------------------
    |
    | Enable or disable specific events.
    |
    */
    'events' => [
        'enabled' => true,
        'listeners' => [
            // Example listeners - uncomment and create as needed:
            // \Develupers\PlanUsage\Events\UsageRecorded::class => [
            //     \App\Listeners\YourUsageListener::class,
            // ],
            // \Develupers\PlanUsage\Events\QuotaExceeded::class => [
            //     \App\Listeners\YourQuotaListener::class,
            // ],
        ],
    ],
];
