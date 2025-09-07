<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Plan Feature Usage Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains the configuration options for the Laravel Plan Feature
    | Usage package, which manages subscription plans, features, quotas, and
    | usage tracking with Laravel Cashier integration.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | You can specify custom model classes if you need to extend the default
    | models provided by the package.
    |
    */
    'models' => [
        'plan' => \Develupers\PlanUsage\Models\Plan::class,
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
    | Stripe Integration
    |--------------------------------------------------------------------------
    |
    | Configure Stripe integration for metered billing and usage reporting.
    |
    */
    'stripe' => [
        // Enable Stripe metered billing integration
        'enabled' => true,

        // Report usage to Stripe for metered billing
        'report_usage' => true,

        // Sync plans from Stripe
        'sync_plans' => false,
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
