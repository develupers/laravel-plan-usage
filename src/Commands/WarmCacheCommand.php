<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Commands;

use Develupers\PlanUsage\Models\Feature;
use Develupers\PlanUsage\Models\Plan;
use Illuminate\Console\Command;

class WarmCacheCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'plan-usage:warm-cache 
                            {--clear : Clear cache before warming}
                            {--plans : Warm only plan cache}
                            {--features : Warm only feature cache}
                            {--quotas : Warm only quota cache}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Warm the plan usage cache by pre-loading frequently accessed data';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (!config('plan-usage.cache.enabled')) {
            $this->warn('Cache is disabled in configuration. Enable it to use cache warming.');
            return Command::FAILURE;
        }

        $this->info('Starting cache warming process...');

        // Clear cache if requested
        if ($this->option('clear')) {
            $this->clearCache();
        }

        // Determine what to warm
        $warmAll = !$this->option('plans') && !$this->option('features') && !$this->option('quotas');
        
        if ($warmAll || $this->option('plans')) {
            $this->warmPlanCache();
        }

        if ($warmAll || $this->option('features')) {
            $this->warmFeatureCache();
        }

        if ($warmAll || $this->option('quotas')) {
            $this->warmQuotaCache();
        }

        $this->info('Cache warming completed successfully!');

        return Command::SUCCESS;
    }

    /**
     * Clear all plan usage cache
     */
    protected function clearCache(): void
    {
        $this->info('Clearing existing cache...');
        
        $planManager = app('plan-usage.manager');
        $planManager->clearCache();
        
        $this->info('Cache cleared.');
    }

    /**
     * Warm plan-related cache
     */
    protected function warmPlanCache(): void
    {
        if (!config('plan-usage.cache.selective.plans', true)) {
            $this->info('Plan caching is disabled in configuration.');
            return;
        }

        $this->info('Warming plan cache...');
        
        $planManager = app('plan-usage.manager');
        
        // Load all plans
        $plans = $planManager->getAllPlans();
        $this->info("Cached {$plans->count()} plans");

        // Load each plan individually (including by stripe_price_id)
        foreach ($plans as $plan) {
            $planManager->findPlan($plan->id);
            
            if ($plan->stripe_price_id) {
                $planManager->findPlan($plan->stripe_price_id);
            }
            
            // Load plan features
            $planManager->getPlanFeatures($plan->id);
        }

        $this->info('Plan cache warmed.');
    }

    /**
     * Warm feature-related cache
     */
    protected function warmFeatureCache(): void
    {
        if (!config('plan-usage.cache.selective.features', true)) {
            $this->info('Feature caching is disabled in configuration.');
            return;
        }

        $this->info('Warming feature cache...');
        
        $planManager = app('plan-usage.manager');
        $plans = Plan::all();
        $features = Feature::all();
        
        $combinations = 0;
        
        // Cache all plan-feature combinations
        foreach ($plans as $plan) {
            foreach ($features as $feature) {
                // Check if plan has feature
                $planManager->planHasFeature($plan->id, $feature->slug);
                
                // Get feature value
                $planManager->getFeatureValue($plan->id, $feature->slug);
                
                $combinations++;
            }
        }

        $this->info("Cached {$combinations} plan-feature combinations");
        $this->info('Feature cache warmed.');
    }

    /**
     * Warm quota-related cache
     */
    protected function warmQuotaCache(): void
    {
        if (!config('plan-usage.cache.selective.quotas', true)) {
            $this->info('Quota caching is disabled in configuration.');
            return;
        }

        $this->info('Warming quota cache...');
        
        $quotaEnforcer = app('plan-usage.quota');
        
        // Get billable model class from config
        $billableTable = config('plan-usage.tables.billable', 'users');
        
        // Try to determine the model class from the table name
        $modelClass = match($billableTable) {
            'users' => '\\App\\Models\\User',
            'teams' => '\\App\\Models\\Team',
            'accounts' => '\\App\\Models\\Account',
            default => null
        };

        if (!$modelClass || !class_exists($modelClass)) {
            $this->warn("Could not determine billable model class for table '{$billableTable}'.");
            $this->warn('Skipping quota cache warming. You may need to warm quota cache manually.');
            return;
        }

        $billables = $modelClass::query()
            ->whereNotNull('plan_id')
            ->limit(100) // Limit to prevent memory issues
            ->get();

        $quotaCount = 0;
        
        foreach ($billables as $billable) {
            $quotas = $quotaEnforcer->getAllQuotas($billable);
            $quotaCount += $quotas->count();
        }

        $this->info("Cached quotas for {$billables->count()} billables ({$quotaCount} total quotas)");
        $this->info('Quota cache warmed.');
    }
}