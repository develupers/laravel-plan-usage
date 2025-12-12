<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Commands\Stripe;

use Develupers\PlanUsage\Models\Plan;
use Develupers\PlanUsage\Models\PlanPrice;
use Illuminate\Console\Command;
use Stripe\Exception\ApiErrorException;
use Stripe\Price;
use Stripe\Product;

/**
 * @deprecated Use `plans:push --provider=stripe` instead. This command will be removed in a future version.
 */
class PushPlansStripeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'stripe:push-plans
                            {--force : Force update existing products}
                            {--dry-run : Show what would be created without actually creating}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '[DEPRECATED] Use plans:push instead. Sync local plans to Stripe products and prices';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->warn('⚠️  This command is deprecated. Please use `php artisan plans:push --provider=stripe` instead.');
        $this->newLine();
        // Set Stripe API key
        $stripeSecret = config('cashier.secret');
        if (! $stripeSecret) {
            $this->error('Stripe secret key not configured. Please set STRIPE_SECRET in your .env file.');

            return 1;
        }

        \Stripe\Stripe::setApiKey($stripeSecret);
        $plans = Plan::all();
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');

        if ($plans->isEmpty()) {
            $this->error('No plans found in database. Please create some plans first.');

            return 1;
        }

        $this->info('Syncing '.$plans->count().' plans to Stripe...');

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made to Stripe');
        }

        foreach ($plans as $plan) {
            $this->info("\n".str_repeat('=', 50));
            $this->info("Processing: {$plan->name} ({$plan->slug})");
            $this->info(str_repeat('=', 50));

            try {
                // Check if product exists
                $product = null;
                if ($plan->stripe_product_id) {
                    try {
                        $product = Product::retrieve($plan->stripe_product_id);
                        $this->line("Found existing product: {$product->id}");

                        if ($force) {
                            // Update product details
                            if (! $dryRun) {
                                $product = Product::update($product->id, [
                                    'name' => $plan->display_name ?? $plan->name,
                                    'description' => $plan->description,
                                    'metadata' => [
                                        'plan_id' => $plan->id,
                                        'plan_slug' => $plan->slug,
                                    ],
                                ]);
                                $this->info("Updated product: {$product->id}");
                            } else {
                                $this->info("Would update product: {$product->id}");
                            }
                        }
                    } catch (ApiErrorException $e) {
                        $this->warn("Product {$plan->stripe_product_id} not found in Stripe, creating new...");
                        $product = null;
                    }
                }

                // Create product if it doesn't exist
                if (! $product) {
                    if (! $dryRun) {
                        $product = Product::create([
                            'name' => $plan->display_name ?? $plan->name,
                            'description' => $plan->description,
                            'metadata' => [
                                'plan_id' => $plan->id,
                                'plan_slug' => $plan->slug,
                            ],
                        ]);

                        // Save product ID to database
                        $plan->stripe_product_id = $product->id;
                        $plan->save();

                        $this->info("Created product: {$product->id}");
                    } else {
                        $this->info("Would create product for: {$plan->name}");
                    }
                }

                $plan->load('prices');

                if ($plan->prices->isEmpty()) {
                    $this->warn('No price variants configured for this plan. Skipping price sync.');

                    continue;
                }

                foreach ($plan->prices as $planPrice) {
                    $this->line("- Syncing price variant ({$planPrice->interval->value})");

                    $price = null;
                    if ($planPrice->stripe_price_id) {
                        try {
                            $price = Price::retrieve($planPrice->stripe_price_id);
                            $this->line("  • Found existing price: {$price->id}");

                            $expectedAmount = (int) round($planPrice->price * 100);
                            $expectedInterval = $planPrice->interval->value;
                            $expectedCurrency = strtolower($planPrice->currency);

                            if ($price->unit_amount !== $expectedAmount
                                || $price->currency !== $expectedCurrency
                                || ($price->recurring->interval ?? null) !== $expectedInterval) {
                                $this->warn('  • Price mismatch detected between local configuration and Stripe.');

                                if ($force) {
                                    if (! $dryRun) {
                                        $newPrice = Price::create([
                                            'product' => $product->id,
                                            'unit_amount' => $expectedAmount,
                                            'currency' => $expectedCurrency,
                                            'recurring' => [
                                                'interval' => $expectedInterval,
                                            ],
                                            'metadata' => [
                                                'plan_id' => $plan->id,
                                                'plan_price_id' => $planPrice->id,
                                                'plan_slug' => $plan->slug,
                                            ],
                                        ]);

                                        Price::update($price->id, ['active' => false]);

                                        $planPrice->stripe_price_id = $newPrice->id;
                                        $planPrice->save();

                                        $price = $newPrice;
                                        $this->info("  • Created replacement price: {$newPrice->id}");
                                    } else {
                                        $this->info('  • Would create replacement price and archive the mismatched one.');
                                    }
                                }
                            }
                        } catch (ApiErrorException $e) {
                            $this->warn("  • Stripe price {$planPrice->stripe_price_id} not found. It will be recreated.");
                            $price = null;
                        }
                    }

                    if (! $price && $product) {
                        if (! $dryRun) {
                            $price = Price::create([
                                'product' => $product->id,
                                'unit_amount' => (int) round($planPrice->price * 100),
                                'currency' => strtolower($planPrice->currency),
                                'recurring' => [
                                    'interval' => $planPrice->interval->value,
                                ],
                                'metadata' => [
                                    'plan_id' => $plan->id,
                                    'plan_price_id' => $planPrice->id,
                                    'plan_slug' => $plan->slug,
                                ],
                            ]);

                            $planPrice->stripe_price_id = $price->id;
                            $planPrice->save();

                            $this->info("  • Created price: {$price->id} (\${$planPrice->price}/{$planPrice->interval->value})");
                        } else {
                            $this->info("  • Would create price: \${$planPrice->price}/{$planPrice->interval->value}");
                        }
                    }

                    if (! $dryRun) {
                        $this->table(
                            ['Field', 'Value'],
                            [
                                ['Plan', $plan->name],
                                ['Product ID', $plan->stripe_product_id],
                                ['Price Interval', $planPrice->interval->value],
                                ['Price ID', $planPrice->stripe_price_id ?? 'pending'],
                                ['Amount', '$'.$planPrice->price.' '.$planPrice->currency],
                                ['Active', $planPrice->is_active ? 'Yes' : 'No'],
                            ]
                        );
                    }
                }

            } catch (ApiErrorException $e) {
                $this->error("Error processing {$plan->name}: ".$e->getMessage());
                if (! $force) {
                    return 1;
                }
            }
        }

        $this->newLine();
        if ($dryRun) {
            $this->warn('DRY RUN COMPLETE - No changes were made');
            $this->info('Run without --dry-run to actually create/update products in Stripe');
        } else {
            $this->info('✅ All plans synced successfully!');
            $this->newLine();

            // Display summary
            $this->info('Stripe IDs have been saved to the database:');
            $this->table(
                ['Plan', 'Product ID', 'Price Interval', 'Price ID'],
                $plans->flatMap(function ($plan) {
                    return $plan->prices->map(function (PlanPrice $planPrice) use ($plan) {
                        return [
                            $plan->name,
                            $plan->stripe_product_id,
                            $planPrice->interval->value,
                            $planPrice->stripe_price_id,
                        ];
                    });
                })
            );
        }

        return 0;
    }
}
