<?php

namespace Develupers\PlanUsage\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Develupers\PlanUsage\PlanUsage
 */
class PlanUsage extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Develupers\PlanUsage\PlanUsage::class;
    }
}
