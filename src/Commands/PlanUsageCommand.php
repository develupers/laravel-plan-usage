<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Commands;

use Illuminate\Console\Command;

class PlanUsageCommand extends Command
{
    public $signature = 'plan-usage:install';

    public $description = 'Install the Plan Usage package';

    public function handle(): int
    {
        $this->info('Installing Plan Usage package...');

        $this->info('Publishing configuration...');
        $this->call('vendor:publish', [
            '--tag' => 'plan-usage-config',
            '--force' => true,
        ]);

        $this->info('Publishing migrations...');
        $this->call('vendor:publish', [
            '--tag' => 'plan-usage-migrations',
        ]);

        $this->info('Plan Usage package installed successfully.');

        return self::SUCCESS;
    }
}
