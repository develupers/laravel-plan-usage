<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Facades;

use Develupers\PlanUsage\Services\QuotaEnforcer;
use Illuminate\Support\Facades\Facade;

/**
 * @see QuotaEnforcer
 */
class Quota extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'plan-usage.quota';
    }
}
