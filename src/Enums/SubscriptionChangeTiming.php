<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Enums;

enum SubscriptionChangeTiming: string
{
    case Immediate = 'immediate';
    case NextPeriod = 'next_period';
}
