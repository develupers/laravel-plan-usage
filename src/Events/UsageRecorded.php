<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Events;

use Develupers\PlanUsage\Models\Feature;
use Develupers\PlanUsage\Models\Usage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UsageRecorded
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Model $billable;
    public Feature $feature;
    public float $amount;
    public Usage $usage;

    /**
     * Create a new event instance.
     */
    public function __construct(Model $billable, Feature $feature, float $amount, Usage $usage)
    {
        $this->billable = $billable;
        $this->feature = $feature;
        $this->amount = $amount;
        $this->usage = $usage;
    }
}