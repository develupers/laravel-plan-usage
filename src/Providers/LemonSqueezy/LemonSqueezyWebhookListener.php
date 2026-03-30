<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Providers\LemonSqueezy;

use Develupers\PlanUsage\Actions\Subscription\DeleteSubscriptionAction;
use Develupers\PlanUsage\Actions\Subscription\SyncPlanWithBillableAction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use LemonSqueezy\Laravel\Customer;
use LemonSqueezy\Laravel\Events\WebhookHandled;

/**
 * Handles LemonSqueezy webhook events for plan synchronization.
 *
 * This listener processes subscription-related webhook events from LemonSqueezy
 * and syncs the local plan/price associations.
 *
 * LemonSqueezy identifies billables via custom_data passed during checkout,
 * unlike Stripe/Paddle which use customer IDs.
 */
class LemonSqueezyWebhookListener
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
        $eventType = $payload['meta']['event_name'] ?? '';

        // Only handle subscription-related events
        if (! $this->shouldHandle($eventType)) {
            return;
        }

        // Deduplicate webhook events using a hash of the payload
        $eventId = $payload['meta']['webhook_id'] ?? md5(json_encode($payload));
        if (! Cache::add("plan-usage:webhook:lemon-squeezy:{$eventId}", true, 3600)) {
            Log::debug('Skipping duplicate LemonSqueezy webhook event', ['event_id' => $eventId]);

            return;
        }

        try {
            match ($eventType) {
                'subscription_created',
                'subscription_updated',
                'subscription_resumed',
                'subscription_unpaused' => $this->handleSubscriptionChange($payload),
                'subscription_cancelled',
                'subscription_expired',
                'subscription_paused' => $this->handleSubscriptionEnded($payload),
                default => null,
            };
        } catch (\Exception $e) {
            Log::error('Failed to sync plan from LemonSqueezy webhook', [
                'error' => $e->getMessage(),
                'event_type' => $eventType,
            ]);
        }
    }

    /**
     * Determine if this event should be handled.
     */
    private function shouldHandle(string $eventType): bool
    {
        return in_array($eventType, [
            'subscription_created',
            'subscription_updated',
            'subscription_cancelled',
            'subscription_expired',
            'subscription_resumed',
            'subscription_paused',
            'subscription_unpaused',
        ]);
    }

    /**
     * Handle subscription created, updated, or resumed events.
     */
    private function handleSubscriptionChange(array $payload): void
    {
        $attributes = $payload['data']['attributes'] ?? [];

        if (empty($attributes)) {
            Log::warning('Invalid or empty attributes in LemonSqueezy webhook');

            return;
        }

        // Extract variant ID (equivalent to price ID)
        $variantId = (string) ($attributes['variant_id'] ?? '');

        if ($variantId === '') {
            Log::warning('Missing variant_id in LemonSqueezy subscription webhook');

            return;
        }

        // Find the billable via custom_data
        $billable = $this->findBillable($payload);

        if (! $billable) {
            Log::warning('No billable found for LemonSqueezy subscription webhook', [
                'subscription_id' => $payload['data']['id'] ?? 'unknown',
            ]);

            return;
        }

        // Update the lemon_squeezy_id on the billable if not set
        $customerId = (string) ($attributes['customer_id'] ?? '');
        if ($customerId !== '' && empty($billable->lemon_squeezy_id)) {
            $billable->lemon_squeezy_id = $customerId;
            $billable->save();
        }

        // Sync the plan with the billable using variant ID
        $synced = $this->syncPlanWithBillable->execute($billable, $variantId);

        $eventType = $payload['meta']['event_name'] ?? 'unknown';

        if ($synced) {
            Log::info('Successfully synced plan from LemonSqueezy webhook', [
                'billable_type' => get_class($billable),
                'billable_id' => $billable->getKey(),
                'variant_id' => $variantId,
                'event_type' => $eventType,
            ]);
        } else {
            Log::warning('Failed to sync plan from LemonSqueezy webhook - plan not found', [
                'billable_type' => get_class($billable),
                'billable_id' => $billable->getKey(),
                'variant_id' => $variantId,
                'event_type' => $eventType,
            ]);
        }
    }

    /**
     * Handle subscription cancelled, expired, or paused events.
     */
    private function handleSubscriptionEnded(array $payload): void
    {
        $billable = $this->findBillable($payload);

        if (! $billable) {
            Log::warning('No billable found for LemonSqueezy subscription end webhook', [
                'subscription_id' => $payload['data']['id'] ?? 'unknown',
            ]);

            return;
        }

        // Use dedicated action for subscription deletion
        $this->deleteSubscription->execute($billable);

        Log::info('Processed LemonSqueezy subscription ended webhook', [
            'billable_type' => get_class($billable),
            'billable_id' => $billable->getKey(),
            'event_type' => $payload['meta']['event_name'] ?? 'unknown',
        ]);
    }

    /**
     * Find the billable entity from the webhook payload.
     *
     * LemonSqueezy passes billable identity through custom_data in the meta field,
     * which is set during checkout creation.
     */
    private function findBillable(array $payload)
    {
        // Try custom_data first (set during checkout)
        $customData = $payload['meta']['custom_data'] ?? [];
        $billableId = $customData['billable_id'] ?? null;
        $billableType = $customData['billable_type'] ?? null;

        if ($billableId && $billableType && class_exists($billableType)) {
            return $billableType::find($billableId);
        }

        // Fall back to customer_id lookup
        $customerId = (string) ($payload['data']['attributes']['customer_id'] ?? '');

        if ($customerId === '') {
            return null;
        }

        $billableClass = config('plan-usage.models.billable');

        if (! $billableClass || ! class_exists($billableClass)) {
            return null;
        }

        // Try convenience column on billable table
        $table = (new $billableClass)->getTable();
        if (\Schema::hasColumn($table, 'lemon_squeezy_id')) {
            $billable = $billableClass::where('lemon_squeezy_id', $customerId)->first();
            if ($billable) {
                return $billable;
            }
        }

        // Fall back to LemonSqueezy Customer model
        if (class_exists(Customer::class)) {
            $customer = Customer::where('lemon_squeezy_id', $customerId)->first();
            if ($customer && $customer->billable) {
                return $customer->billable;
            }
        }

        return null;
    }
}
