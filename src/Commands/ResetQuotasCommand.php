<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Commands;

use Develupers\PlanUsage\Jobs\ResetExpiredQuotasJob;
use Develupers\PlanUsage\Models\Quota;
use Illuminate\Console\Command;

class ResetQuotasCommand extends Command
{
    protected $signature = 'plan-usage:reset-quotas
                            {--dispatch : Dispatch as a queued job instead of running synchronously}';

    protected $description = 'Reset all expired quotas that have passed their reset date';

    public function handle(): int
    {
        if ($this->option('dispatch')) {
            ResetExpiredQuotasJob::dispatch();
            $this->info('Job dispatched to queue.');

            return Command::SUCCESS;
        }

        $count = Quota::query()
            ->whereNotNull('reset_at')
            ->where('reset_at', '<=', now())
            ->where('used', '>', 0)
            ->count();

        if ($count === 0) {
            $this->info('No expired quotas found.');

            return Command::SUCCESS;
        }

        $this->info("Found {$count} expired quota(s). Resetting...");

        ResetExpiredQuotasJob::dispatchSync();

        $this->info("Done.");

        return Command::SUCCESS;
    }
}
