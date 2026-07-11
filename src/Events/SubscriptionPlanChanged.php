<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Events;

use Develupers\PlanUsage\Models\SubscriptionPlanChange;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SubscriptionPlanChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  array<int, array<string, mixed>>  $quotaAdjustments
     */
    public function __construct(
        public Model $billable,
        public SubscriptionPlanChange $planChange,
        public array $quotaAdjustments = [],
    ) {}
}
