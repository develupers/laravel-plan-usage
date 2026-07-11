<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Enums;

enum SubscriptionChangeStatus: string
{
    case Pending = 'pending';
    case Applied = 'applied';
    case Cancelled = 'cancelled';
    case Failed = 'failed';
}
