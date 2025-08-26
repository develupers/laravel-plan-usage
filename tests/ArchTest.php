<?php

declare(strict_types=1);

arch('models extend eloquent model')
    ->expect('Develupers\PlanUsage\Models')
    ->toExtend('Illuminate\Database\Eloquent\Model');

arch('services have appropriate names')
    ->expect('Develupers\PlanUsage\Services\PlanManager')
    ->toHaveSuffix('Manager')
    ->and('Develupers\PlanUsage\Services\UsageTracker')
    ->toHaveSuffix('Tracker')
    ->and('Develupers\PlanUsage\Services\QuotaEnforcer')
    ->toHaveSuffix('Enforcer');

arch('middleware implements handle method')
    ->expect('Develupers\PlanUsage\Middleware')
    ->toHaveMethod('handle');

arch('facades extend laravel facade')
    ->expect('Develupers\PlanUsage\Facades')
    ->toExtend('Illuminate\Support\Facades\Facade');

arch('no debug statements')
    ->expect(['dd', 'dump', 'var_dump', 'print_r', 'die', 'ray'])
    ->each->not->toBeUsed();

arch('strict types declaration')
    ->expect('Develupers\PlanUsage')
    ->toUseStrictTypes();

arch('models have proper relationships')
    ->expect('Develupers\PlanUsage\Models\Plan')
    ->toHaveMethod('features')
    ->toHaveMethod('planFeatures');

arch('services follow single responsibility')
    ->expect('Develupers\PlanUsage\Services\PlanManager')
    ->not->toUse([
        'Develupers\PlanUsage\Models\Usage',
        'Develupers\PlanUsage\Models\Quota',
    ]);

arch('test files use pest')
    ->expect('Develupers\PlanUsage\Tests')
    ->not->toUse(['PHPUnit\Framework\TestCase'])
    ->ignoring(['Develupers\PlanUsage\Tests\TestCase']);
