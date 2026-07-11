<?php

declare(strict_types=1);

use Develupers\PlanUsage\PlanUsageServiceProvider;

afterEach(function () {
    putenv('BILLING_PROVIDER');
    unset($_ENV['BILLING_PROVIDER'], $_SERVER['BILLING_PROVIDER']);
});

function migrationsSelectedWithEnvProvider(string $provider): array
{
    // Fresh-install shape: config not yet published and not yet merged —
    // package-tools merges package config AFTER configurePackage() runs, so
    // migration selection historically could not see BILLING_PROVIDER.
    app('config')->set('plan-usage', []);
    putenv("BILLING_PROVIDER={$provider}");
    $_ENV['BILLING_PROVIDER'] = $provider;
    $_SERVER['BILLING_PROVIDER'] = $provider;

    $serviceProvider = new PlanUsageServiceProvider(app());
    $serviceProvider->register();

    // Read what configurePackage() actually handed to hasMigrations() — this
    // is the publish list. Re-invoking getMigrations() after register() would
    // see the config package-tools merges later and mask the bug.
    $property = new ReflectionProperty($serviceProvider, 'package');
    $property->setAccessible(true);

    return $property->getValue($serviceProvider)->migrationFileNames;
}

it('selects polar migrations from BILLING_PROVIDER before the config is published', function () {
    $migrations = migrationsSelectedWithEnvProvider('polar');

    expect($migrations)->toContain('add_polar_product_id_to_plan_prices')
        ->toContain('add_billable_columns_polar')
        ->toContain('create_billing_webhook_events_table')
        ->not->toContain('add_stripe_price_id_to_plan_prices')
        ->not->toContain('add_billable_columns_stripe');
});

it('selects paddle migrations from BILLING_PROVIDER before the config is published', function () {
    $migrations = migrationsSelectedWithEnvProvider('paddle');

    expect($migrations)->toContain('add_paddle_price_id_to_plan_prices')
        ->toContain('add_billable_columns_paddle')
        ->not->toContain('create_billing_webhook_events_table')
        ->not->toContain('add_polar_product_id_to_plan_prices');
});
