<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Events;

use Develupers\PlanUsage\Models\Feature;
use Develupers\PlanUsage\Models\Quota;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class QuotaExceeded
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Model $billable;
    public Feature $feature;
    public Quota $quota;

    /**
     * Create a new event instance.
     */
    public function __construct(Model $billable, Feature $feature, Quota $quota)
    {
        $this->billable = $billable;
        $this->feature = $feature;
        $this->quota = $quota;
    }
}