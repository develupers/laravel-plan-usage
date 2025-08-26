<?php

declare(strict_types=1);

arch('models extend eloquent model')
    ->expect('Develupers\PlanUsage\Models')
    ->toExtend('Illuminate\Database\Eloquent\Model');

arch('services are suffixed correctly')
    ->expect('Develupers\PlanUsage\Services')
    ->toHaveSuffix(['Manager', 'Tracker', 'Enforcer']);

arch('events extend base event')
    ->expect('Develupers\PlanUsage\Events')
    ->toExtend('Develupers\PlanUsage\Events\BaseEvent')
    ->ignoring(['Develupers\PlanUsage\Events\BaseEvent']);

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

arch('events have proper structure')
    ->expect('Develupers\PlanUsage\Events\UsageRecorded')
    ->toHaveProperty('usage');

arch('services follow single responsibility')
    ->expect('Develupers\PlanUsage\Services\PlanManager')
    ->not->toUse([
        'Develupers\PlanUsage\Models\Usage',
        'Develupers\PlanUsage\Models\Quota'
    ]);

arch('test files use pest')
    ->expect('Develupers\PlanUsage\Tests')
    ->not->toUse(['PHPUnit\Framework\TestCase'])
    ->ignoring(['Develupers\PlanUsage\Tests\TestCase']);
