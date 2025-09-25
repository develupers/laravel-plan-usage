<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;

beforeEach(function () {
    Config::set('cashier.secret', 'sk_test_fake123');
});

it('shows error when stripe secret not configured', function () {
    Config::set('cashier.secret', null);

    $this->artisan('plan-usage:stripe-push')
        ->expectsOutput('Stripe secret key not configured. Please set STRIPE_SECRET in your .env file.')
        ->assertExitCode(1);
});

it('shows error when no plans exist', function () {
    $this->artisan('plan-usage:stripe-push')
        ->expectsOutput('No plans found in database. Please create some plans first.')
        ->assertExitCode(1);
});
