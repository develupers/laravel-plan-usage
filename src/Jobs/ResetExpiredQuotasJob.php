<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Jobs;

use Develupers\PlanUsage\Models\Quota;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ResetExpiredQuotasJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // No used > 0 filter: an untouched quota can still carry a prorated or
        // grandfathered limit that must be trued up to the plan at renewal.
        $expiredQuotas = Quota::query()
            ->whereNotNull('reset_at')
            ->where('reset_at', '<=', now())
            ->with('feature')
            ->get();

        if ($expiredQuotas->isEmpty()) {
            return;
        }

        $resetCount = 0;

        foreach ($expiredQuotas as $quota) {
            // Re-read under a row lock and re-check expiry: a concurrent
            // consumer may have lazily reset AND incremented this quota since
            // the fetch above — saving the stale instance would zero the new
            // period's usage.
            $reset = DB::transaction(function () use ($quota): bool {
                $locked = Quota::query()->whereKey($quota->getKey())->lockForUpdate()->first();

                if ($locked === null || ! $locked->needsReset()) {
                    return false;
                }

                $locked->setRelation('feature', $quota->feature);
                $locked->reset();

                return true;
            });

            if ($reset) {
                $resetCount++;
            }
        }

        Log::info("ResetExpiredQuotasJob: Reset {$resetCount} expired quota(s).");
    }
}
