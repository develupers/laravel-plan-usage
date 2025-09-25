<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;

beforeEach(function () {
    Config::set('plan-usage.models.billable', 'Test\\Billable\\Model');
});

it('shows error when billable model not configured', function () {
    Config::set('plan-usage.models.billable', null);
    Config::set('cashier.model', null);

    $this->artisan('plan-usage:reconcile-subscriptions')
        ->expectsOutput('Billable model class not configured. Please set plan-usage.models.billable in config.')
        ->assertExitCode(1);
});

it('reports no expired subscriptions found', function () {
    // Mock billable class with no expired subscriptions
    $billableClass = Mockery::mock('alias:Test\\Billable\\Model');
    $query = Mockery::mock();
    $query->shouldReceive('count')->once()->andReturn(0);
    $query->shouldReceive('whereHas')->andReturn($query);
    $billableClass->shouldReceive('whereNotNull')->with('plan_id')->once()->andReturn($query);

    $this->artisan('plan-usage:reconcile-subscriptions')
        ->expectsOutput('✅ No expired subscriptions found that need reconciliation')
        ->assertSuccessful();
});

it('asks for confirmation without force flag', function () {
    $query = Mockery::mock();
    $query->shouldReceive('count')->once()->andReturn(2);
    $query->shouldReceive('whereHas')->andReturn($query);
    $query->shouldReceive('each')->never(); // Should not process if cancelled

    $billableClass = Mockery::mock('alias:Test\\Billable\\Model');
    $billableClass->shouldReceive('whereNotNull')->with('plan_id')->once()->andReturn($query);

    $this->artisan('plan-usage:reconcile-subscriptions')
        ->expectsQuestion('Do you want to reconcile 2 subscription(s)?', false)
        ->expectsOutput('Reconciliation cancelled')
        ->assertSuccessful();
});
