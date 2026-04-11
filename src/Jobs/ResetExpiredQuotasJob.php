<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Jobs;

use Develupers\PlanUsage\Models\Quota;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ResetExpiredQuotasJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $expiredQuotas = Quota::query()
            ->whereNotNull('reset_at')
            ->where('reset_at', '<=', now())
            ->where('used', '>', 0)
            ->with('feature')
            ->get();

        if ($expiredQuotas->isEmpty()) {
            return;
        }

        $resetCount = 0;

        foreach ($expiredQuotas as $quota) {
            $quota->reset();
            $resetCount++;
        }

        Log::info("ResetExpiredQuotasJob: Reset {$resetCount} expired quota(s).");
    }
}
