<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Commands;

use Develupers\PlanUsage\Jobs\EnforcePlanSubscriptionsJob;
use Develupers\PlanUsage\Models\Plan;
use Illuminate\Console\Command;

class EnforcePlanSubscriptionsCommand extends Command
{
    protected $signature = 'plan-usage:enforce-subscriptions
                            {--dispatch : Dispatch as a queued job instead of running synchronously}';

    protected $description = 'Revoke plans from billables without an active subscription (lifetime plans are exempt)';

    public function handle(): int
    {
        if ($this->option('dispatch')) {
            EnforcePlanSubscriptionsJob::dispatch();
            $this->info('Job dispatched to queue.');

            return Command::SUCCESS;
        }

        $modelClass = config('plan-usage.models.billable') ?? config('cashier.model');

        if (! $modelClass || ! class_exists($modelClass)) {
            $this->error('Billable model not configured. Set "models.billable" in config/plan-usage.php.');

            return Command::FAILURE;
        }

        $lifetimePlanIds = Plan::where('is_lifetime', true)->pluck('id');

        $count = $modelClass::query()
            ->whereNotNull('plan_id')
            ->when($lifetimePlanIds->isNotEmpty(), fn ($q) => $q->whereNotIn('plan_id', $lifetimePlanIds))
            ->count();

        if ($count === 0) {
            $this->info('No billables with non-lifetime plans found.');

            return Command::SUCCESS;
        }

        $this->info("Found {$count} billable(s) with non-lifetime plans. Enforcing...");

        EnforcePlanSubscriptionsJob::dispatchSync();

        $this->info('Done.');

        return Command::SUCCESS;
    }
}
