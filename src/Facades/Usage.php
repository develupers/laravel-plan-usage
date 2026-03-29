<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Facades;

use Develupers\PlanUsage\Services\UsageTracker;
use Illuminate\Support\Facades\Facade;

/**
 * @see UsageTracker
 */
class Usage extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'plan-usage.tracker';
    }
}
