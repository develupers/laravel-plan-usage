<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Providers\Paddle;

use Develupers\PlanUsage\Actions\Subscription\DeleteSubscriptionAction;
use Develupers\PlanUsage\Actions\Subscription\SyncPlanWithBillableAction;
use Illuminate\Support\Facades\Log;
use Laravel\Paddle\Events\WebhookReceived;

/**
 * Handles Paddle webhook events for plan synchronization.
 *
 * This listener processes subscription-related webhook events from Paddle
 * and syncs the local plan/price associations.
 */
class PaddleWebhookListener
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
    public function handle(WebhookReceived $event): void
    {
        $payload = $event->payload;
        $eventType = $payload['event_type'] ?? '';

        // Only handle subscription-related events
        if (! $this->shouldHandle($eventType)) {
            return;
        }

        try {
            match ($eventType) {
                'subscription.created',
                'subscription.updated',
                'subscription.resumed' => $this->handleSubscriptionChange($payload),
                'subscription.canceled',
                'subscription.paused' => $this->handleSubscriptionEnded($payload),
                default => null,
            };
        } catch (\Exception $e) {
            Log::error('Failed to sync plan from Paddle webhook', [
                'error' => $e->getMessage(),
                'event_type' => $eventType,
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
            'subscription.created',
            'subscription.updated',
            'subscription.canceled',
            'subscription.paused',
            'subscription.resumed',
        ]);
    }

    /**
     * Handle subscription created, updated, or resumed events.
     */
    private function handleSubscriptionChange(array $payload): void
    {
        $data = $payload['data'] ?? [];

        // Extract customer ID and price ID
        $customerId = $data['customer_id'] ?? null;
        $priceId = $this->extractPriceId($data);

        if (! $customerId || ! $priceId) {
            Log::warning('Missing customer ID or price ID in Paddle subscription webhook', [
                'customer_id' => $customerId,
                'price_id' => $priceId,
                'subscription_id' => $data['id'] ?? 'unknown',
            ]);

            return;
        }

        // Find the billable by Paddle customer ID
        $billable = $this->findBillableByPaddleId($customerId);

        if (! $billable) {
            Log::warning('No billable found for Paddle customer', [
                'customer_id' => $customerId,
            ]);

            return;
        }

        // Update the paddle_id on the billable if not set
        if (empty($billable->paddle_id)) {
            $billable->paddle_id = $customerId;
            $billable->save();
        }

        // Sync the plan with the billable
        $synced = $this->syncPlanWithBillable->execute($billable, $priceId);

        if ($synced) {
            Log::info('Successfully synced plan from Paddle webhook', [
                'billable_type' => get_class($billable),
                'billable_id' => $billable->getKey(),
                'price_id' => $priceId,
                'event_type' => $payload['event_type'],
            ]);
        } else {
            Log::warning('Failed to sync plan from Paddle webhook - plan not found', [
                'billable_type' => get_class($billable),
                'billable_id' => $billable->getKey(),
                'price_id' => $priceId,
                'event_type' => $payload['event_type'],
            ]);
        }
    }

    /**
     * Handle subscription canceled or paused events.
     */
    private function handleSubscriptionEnded(array $payload): void
    {
        $data = $payload['data'] ?? [];
        $customerId = $data['customer_id'] ?? null;

        if (! $customerId) {
            Log::warning('Missing customer ID in Paddle subscription ended webhook');

            return;
        }

        // Find the billable by Paddle customer ID
        $billable = $this->findBillableByPaddleId($customerId);

        if (! $billable) {
            Log::warning('No billable found for Paddle customer on subscription end', [
                'customer_id' => $customerId,
            ]);

            return;
        }

        // Use dedicated action for subscription deletion
        $this->deleteSubscription->execute($billable);

        Log::info('Processed Paddle subscription ended webhook', [
            'billable_type' => get_class($billable),
            'billable_id' => $billable->getKey(),
            'event_type' => $payload['event_type'],
        ]);
    }

    /**
     * Extract the price ID from the subscription data.
     */
    private function extractPriceId(array $data): ?string
    {
        // Paddle subscriptions have items array with price information
        $items = $data['items'] ?? [];

        if (empty($items)) {
            return null;
        }

        // Get the first item's price ID
        return $items[0]['price']['id'] ?? null;
    }

    /**
     * Find a billable entity by Paddle customer ID.
     */
    private function findBillableByPaddleId(string $paddleId)
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

        // Check if the table has paddle_id column
        $table = (new $billableClass)->getTable();
        if (! \Schema::hasColumn($table, 'paddle_id')) {
            Log::error('Billable table does not have paddle_id column', [
                'table' => $table,
            ]);

            return null;
        }

        return $billableClass::where('paddle_id', $paddleId)->first();
    }
}
