<?php

namespace Develupers\PlanUsage\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Develupers\PlanUsage\Services\UsageTracker
 */
class Usage extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'plan-usage.tracker';
    }
}