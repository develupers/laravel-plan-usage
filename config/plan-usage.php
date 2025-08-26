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
        'usage' => 'usage',
        'quotas' => 'quotas',
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Configuration
    |--------------------------------------------------------------------------
    |
    | Configure caching for quota checks and usage tracking to improve
    | performance. Set to false to disable caching.
    |
    */
    'cache' => [
        'enabled' => true,
        'store' => env('PLAN_FEATURE_CACHE_STORE', 'redis'),
        'ttl' => 3600, // Time to live in seconds
        'prefix' => 'plan_feature_usage',
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
        
        // Send notifications at these usage percentages
        'warning_thresholds' => [80, 90, 100],
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
        'enabled' => env('PLAN_FEATURE_STRIPE_ENABLED', true),
        
        // Report usage to Stripe for metered billing
        'report_usage' => env('PLAN_FEATURE_STRIPE_REPORT_USAGE', true),
        
        // Sync plans from Stripe
        'sync_plans' => env('PLAN_FEATURE_STRIPE_SYNC_PLANS', false),
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
        'enforce-quota' => \Develupers\PlanUsage\Http\Middleware\EnforceQuota::class,
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
            \Develupers\PlanUsage\Events\UsageRecorded::class => [
                \Develupers\PlanUsage\Listeners\ReportUsageToStripe::class,
            ],
            \Develupers\PlanUsage\Events\QuotaExceeded::class => [
                \Develupers\PlanUsage\Listeners\NotifyQuotaExceeded::class,
            ],
        ],
    ],
];