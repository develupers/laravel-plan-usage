<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Providers\Stripe;

use Develupers\PlanUsage\Actions\Subscription\DeleteSubscriptionAction;
use Develupers\PlanUsage\Actions\Subscription\SyncPlanWithBillableAction;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Events\WebhookHandled;

/**
 * Handles Stripe webhook events for plan synchronization.
 *
 * This listener processes subscription-related webhook events from Stripe
 * and syncs the local plan/price associations.
 */
class StripeWebhookListener
{
    /**
     * Create the event listener.
     */
    public function __construct(
        private SyncPlanWithBillableAction $syncPlanWithBillable,
        private DeleteSubscriptionAction $deleteSubscription
    ) {}

    /**
     * Handle the event.
     */
    public function handle(WebhookHandled $event): void
    {
        $payload = $event->payload;

        // Only handle subscription-related events
        if (! $this->shouldHandle($payload['type'] ?? '')) {
            return;
        }

        try {
            match ($payload['type']) {
                'customer.subscription.created',
                'customer.subscription.updated' => $this->handleSubscriptionChange($payload),
                'customer.subscription.deleted' => $this->handleSubscriptionDeleted($payload),
                default => null,
            };
        } catch (\Exception $e) {
            Log::error('Failed to sync plan from Stripe webhook', [
                'error' => $e->getMessage(),
                'event_type' => $payload['type'],
                'payload' => $payload,
            ]);
        }
    }

    /**
     * Determine if this event should be handled.
     */
    private function shouldHandle(string $eventType): bool
    {
        return in_array($eventType, [
            'customer.subscription.created',
            'customer.subscription.updated',
            'customer.subscription.deleted',
        ]);
    }

    /**
     * Handle subscription created or updated events.
     */
    private function handleSubscriptionChange(array $payload): void
    {
        $subscription = $payload['data']['object'] ?? [];

        // Extract customer ID and price ID
        $customerId = $subscription['customer'] ?? null;
        $priceId = $this->extractPriceId($subscription);

        if (! $customerId || ! $priceId) {
            Log::warning('Missing customer ID or price ID in subscription webhook', [
                'customer_id' => $customerId,
                'price_id' => $priceId,
                'subscription_id' => $subscription['id'] ?? 'unknown',
            ]);

            return;
        }

        // Find the billable by Stripe customer ID
        $billable = $this->findBillableByStripeId($customerId);

        if (! $billable) {
            Log::warning('No billable found for Stripe customer', [
                'customer_id' => $customerId,
            ]);

            return;
        }

        // Sync the plan with the billable
        $synced = $this->syncPlanWithBillable->execute($billable, $priceId);

        if ($synced) {
            Log::info('Successfully synced plan from Stripe webhook', [
                'billable_type' => get_class($billable),
                'billable_id' => $billable->getKey(),
                'price_id' => $priceId,
                'event_type' => $payload['type'],
            ]);
        } else {
            Log::warning('Failed to sync plan from Stripe webhook - plan not found', [
                'billable_type' => get_class($billable),
                'billable_id' => $billable->getKey(),
                'price_id' => $priceId,
                'event_type' => $payload['type'],
            ]);
        }
    }

    /**
     * Handle subscription deleted events.
     */
    private function handleSubscriptionDeleted(array $payload): void
    {
        $subscription = $payload['data']['object'] ?? [];
        $customerId = $subscription['customer'] ?? null;

        if (! $customerId) {
            Log::warning('Missing customer ID in subscription deleted webhook');

            return;
        }

        // Find the billable by Stripe customer ID
        $billable = $this->findBillableByStripeId($customerId);

        if (! $billable) {
            Log::warning('No billable found for Stripe customer on deletion', [
                'customer_id' => $customerId,
            ]);

            return;
        }

        // Use dedicated action for subscription deletion
        $this->deleteSubscription->execute($billable);

        Log::info('Processed subscription deletion webhook', [
            'billable_type' => get_class($billable),
            'billable_id' => $billable->getKey(),
            'event_type' => $payload['type'],
        ]);
    }

    /**
     * Extract the price ID from the subscription object.
     */
    private function extractPriceId(array $subscription): ?string
    {
        // Stripe subscriptions have items->data array with price information
        $items = $subscription['items']['data'] ?? [];

        if (empty($items)) {
            return null;
        }

        // Get the first item's price ID (assuming single product subscription)
        // If you support multiple products, you'd need to handle this differently
        return $items[0]['price']['id'] ?? null;
    }

    /**
     * Find a billable entity by Stripe customer ID.
     */
    private function findBillableByStripeId(string $stripeId)
    {
        // Get the billable model class from config
        $billableClass = config('plan-usage.models.billable')
            ?? config('cashier.model');

        if (! $billableClass || ! class_exists($billableClass)) {
            Log::error('Billable model class not configured or does not exist', [
                'configured_class' => $billableClass,
            ]);

            return null;
        }

        return $billableClass::where('stripe_id', $stripeId)->first();
    }
}
