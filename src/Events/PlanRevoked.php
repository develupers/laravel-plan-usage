<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Events;

use Develupers\PlanUsage\Models\Plan;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PlanRevoked
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Model $billable;

    public Plan $previousPlan;

    public string $reason;

    /**
     * Create a new event instance.
     */
    public function __construct(Model $billable, Plan $previousPlan, string $reason = 'no_active_subscription')
    {
        $this->billable = $billable;
        $this->previousPlan = $previousPlan;
        $this->reason = $reason;
    }
}
