<?php

declare(strict_types=1);

use Develupers\PlanUsage\Actions\Subscription\DeleteSubscriptionAction;
use Develupers\PlanUsage\Actions\Subscription\SyncPlanWithBillableAction;
use Develupers\PlanUsage\Listeners\SyncBillablePlanFromStripe;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Events\WebhookHandled;

beforeEach(function () {
    $this->syncAction = Mockery::mock(SyncPlanWithBillableAction::class);
    $this->deleteAction = Mockery::mock(DeleteSubscriptionAction::class);
    $this->listener = new SyncBillablePlanFromStripe($this->syncAction, $this->deleteAction);

    Config::set('plan-usage.models.billable', 'Test\\Billable\\Model');
});

it('handles subscription created event', function () {
    $payload = [
        'type' => 'customer.subscription.created',
        'data' => [
            'object' => [
                'id' => 'sub_test123',
                'customer' => 'cus_test456',
                'items' => [
                    'data' => [
                        [
                            'price' => [
                                'id' => 'price_test789',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    $billable = createBillable(['stripe_id' => 'cus_test456']);

    $billableClass = Mockery::mock('alias:Test\\Billable\\Model');
    $billableClass->shouldReceive('where')
        ->with('stripe_id', 'cus_test456')
        ->once()
        ->andReturn($billableClass);
    $billableClass->shouldReceive('first')
        ->once()
        ->andReturn($billable);

    $this->syncAction->shouldReceive('execute')
        ->once()
        ->with($billable, 'price_test789')
        ->andReturn(true);

    Log::shouldReceive('info')->once()->withArgs(function ($message, $context) {
        return $message === 'Successfully synced plan from Stripe webhook' &&
               $context['price_id'] === 'price_test789' &&
               $context['event_type'] === 'customer.subscription.created';
    });

    $event = new WebhookHandled($payload);
    $this->listener->handle($event);
});

it('handles subscription updated event', function () {
    $payload = [
        'type' => 'customer.subscription.updated',
        'data' => [
            'object' => [
                'id' => 'sub_test123',
                'customer' => 'cus_test456',
                'items' => [
                    'data' => [
                        [
                            'price' => [
                                'id' => 'price_updated999',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    $billable = createBillable(['stripe_id' => 'cus_test456']);

    $billableClass = Mockery::mock('alias:Test\\Billable\\Model');
    $billableClass->shouldReceive('where')
        ->with('stripe_id', 'cus_test456')
        ->once()
        ->andReturn($billableClass);
    $billableClass->shouldReceive('first')
        ->once()
        ->andReturn($billable);

    $this->syncAction->shouldReceive('execute')
        ->once()
        ->with($billable, 'price_updated999')
        ->andReturn(true);

    Log::shouldReceive('info')->once();

    $event = new WebhookHandled($payload);
    $this->listener->handle($event);
});

it('handles subscription deleted event', function () {
    $payload = [
        'type' => 'customer.subscription.deleted',
        'data' => [
            'object' => [
                'id' => 'sub_test123',
                'customer' => 'cus_test456',
            ],
        ],
    ];

    $billable = createBillable(['stripe_id' => 'cus_test456']);

    $billableClass = Mockery::mock('alias:Test\\Billable\\Model');
    $billableClass->shouldReceive('where')
        ->with('stripe_id', 'cus_test456')
        ->once()
        ->andReturn($billableClass);
    $billableClass->shouldReceive('first')
        ->once()
        ->andReturn($billable);

    $this->deleteAction->shouldReceive('execute')
        ->once()
        ->with($billable);

    Log::shouldReceive('info')->once()->withArgs(function ($message, $context) {
        return $message === 'Processed subscription deletion webhook' &&
               $context['event_type'] === 'customer.subscription.deleted';
    });

    $event = new WebhookHandled($payload);
    $this->listener->handle($event);
});

it('ignores non-subscription events', function () {
    $payload = [
        'type' => 'payment_intent.succeeded',
        'data' => ['object' => []],
    ];

    // No actions should be called
    $this->syncAction->shouldNotReceive('execute');
    $this->deleteAction->shouldNotReceive('execute');

    $event = new WebhookHandled($payload);
    $this->listener->handle($event);
});

it('logs warning when customer ID is missing', function () {
    $payload = [
        'type' => 'customer.subscription.created',
        'data' => [
            'object' => [
                'id' => 'sub_test123',
                // Missing customer field
                'items' => [
                    'data' => [
                        [
                            'price' => [
                                'id' => 'price_test789',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    Log::shouldReceive('warning')->once()->withArgs(function ($message, $context) {
        return $message === 'Missing customer ID or price ID in subscription webhook' &&
               $context['customer_id'] === null &&
               $context['price_id'] === 'price_test789';
    });

    $event = new WebhookHandled($payload);
    $this->listener->handle($event);
});

it('logs warning when price ID is missing', function () {
    $payload = [
        'type' => 'customer.subscription.created',
        'data' => [
            'object' => [
                'id' => 'sub_test123',
                'customer' => 'cus_test456',
                'items' => [
                    'data' => [], // Empty items
                ],
            ],
        ],
    ];

    Log::shouldReceive('warning')->once()->withArgs(function ($message, $context) {
        return $message === 'Missing customer ID or price ID in subscription webhook' &&
               $context['customer_id'] === 'cus_test456' &&
               $context['price_id'] === null;
    });

    $event = new WebhookHandled($payload);
    $this->listener->handle($event);
});

it('logs warning when billable not found', function () {
    $payload = [
        'type' => 'customer.subscription.created',
        'data' => [
            'object' => [
                'id' => 'sub_test123',
                'customer' => 'cus_nonexistent',
                'items' => [
                    'data' => [
                        [
                            'price' => [
                                'id' => 'price_test789',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    $billableClass = Mockery::mock('alias:Test\\Billable\\Model');
    $billableClass->shouldReceive('where')
        ->with('stripe_id', 'cus_nonexistent')
        ->once()
        ->andReturn($billableClass);
    $billableClass->shouldReceive('first')
        ->once()
        ->andReturn(null);

    Log::shouldReceive('warning')->once()->withArgs(function ($message, $context) {
        return $message === 'No billable found for Stripe customer' &&
               $context['customer_id'] === 'cus_nonexistent';
    });

    $event = new WebhookHandled($payload);
    $this->listener->handle($event);
});

it('logs warning when sync fails', function () {
    $payload = [
        'type' => 'customer.subscription.created',
        'data' => [
            'object' => [
                'id' => 'sub_test123',
                'customer' => 'cus_test456',
                'items' => [
                    'data' => [
                        [
                            'price' => [
                                'id' => 'price_invalid',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    $billable = createBillable(['stripe_id' => 'cus_test456']);

    $billableClass = Mockery::mock('alias:Test\\Billable\\Model');
    $billableClass->shouldReceive('where')
        ->with('stripe_id', 'cus_test456')
        ->once()
        ->andReturn($billableClass);
    $billableClass->shouldReceive('first')
        ->once()
        ->andReturn($billable);

    $this->syncAction->shouldReceive('execute')
        ->once()
        ->with($billable, 'price_invalid')
        ->andReturn(false); // Sync fails

    Log::shouldReceive('warning')->once()->withArgs(function ($message, $context) {
        return $message === 'Failed to sync plan from Stripe webhook - plan not found' &&
               $context['price_id'] === 'price_invalid';
    });

    $event = new WebhookHandled($payload);
    $this->listener->handle($event);
});

it('handles exceptions gracefully', function () {
    $payload = [
        'type' => 'customer.subscription.created',
        'data' => [
            'object' => [
                'id' => 'sub_test123',
                'customer' => 'cus_test456',
                'items' => [
                    'data' => [
                        [
                            'price' => [
                                'id' => 'price_test789',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    $billable = createBillable(['stripe_id' => 'cus_test456']);

    $billableClass = Mockery::mock('alias:Test\\Billable\\Model');
    $billableClass->shouldReceive('where')
        ->with('stripe_id', 'cus_test456')
        ->once()
        ->andReturn($billableClass);
    $billableClass->shouldReceive('first')
        ->once()
        ->andReturn($billable);

    $this->syncAction->shouldReceive('execute')
        ->once()
        ->andThrow(new Exception('Database error'));

    Log::shouldReceive('error')->once()->withArgs(function ($message, $context) {
        return $message === 'Failed to sync plan from Stripe webhook' &&
               $context['error'] === 'Database error' &&
               $context['event_type'] === 'customer.subscription.created';
    });

    $event = new WebhookHandled($payload);
    $this->listener->handle($event);
});

it('logs error when billable model not configured', function () {
    Config::set('plan-usage.models.billable', null);
    Config::set('cashier.model', null);

    $payload = [
        'type' => 'customer.subscription.created',
        'data' => [
            'object' => [
                'id' => 'sub_test123',
                'customer' => 'cus_test456',
                'items' => [
                    'data' => [
                        [
                            'price' => [
                                'id' => 'price_test789',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    Log::shouldReceive('error')->once()->withArgs(function ($message, $context) {
        return $message === 'Billable model class not configured or does not exist';
    });

    Log::shouldReceive('warning')->once(); // For no billable found

    $event = new WebhookHandled($payload);
    $this->listener->handle($event);
});

it('handles multiple price items correctly', function () {
    $payload = [
        'type' => 'customer.subscription.created',
        'data' => [
            'object' => [
                'id' => 'sub_test123',
                'customer' => 'cus_test456',
                'items' => [
                    'data' => [
                        [
                            'price' => [
                                'id' => 'price_first',
                            ],
                        ],
                        [
                            'price' => [
                                'id' => 'price_second',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    $billable = createBillable(['stripe_id' => 'cus_test456']);

    $billableClass = Mockery::mock('alias:Test\\Billable\\Model');
    $billableClass->shouldReceive('where')
        ->with('stripe_id', 'cus_test456')
        ->once()
        ->andReturn($billableClass);
    $billableClass->shouldReceive('first')
        ->once()
        ->andReturn($billable);

    // Should use the first price item
    $this->syncAction->shouldReceive('execute')
        ->once()
        ->with($billable, 'price_first')
        ->andReturn(true);

    Log::shouldReceive('info')->once();

    $event = new WebhookHandled($payload);
    $this->listener->handle($event);
});
