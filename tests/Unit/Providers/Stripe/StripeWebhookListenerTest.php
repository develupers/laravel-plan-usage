<?php

declare(strict_types=1);

use Develupers\PlanUsage\Actions\Subscription\DeleteSubscriptionAction;
use Develupers\PlanUsage\Actions\Subscription\SyncPlanWithBillableAction;
use Develupers\PlanUsage\Contracts\Billable as BillableContract;
use Develupers\PlanUsage\Providers\Stripe\StripeWebhookListener;
use Develupers\PlanUsage\Traits\HasPlanFeatures;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Laravel\Cashier\Billable;
use Laravel\Cashier\Events\WebhookHandled;

/**
 * Concrete billable backed by the shared test_billables table — the listener
 * resolves the class from config and queries it by stripe_id directly.
 */
class StripeListenerTestBillable extends Model implements BillableContract
{
    use Billable;
    use HasPlanFeatures;

    public $timestamps = false;

    protected $table = 'test_billables';

    protected $guarded = [];
}

beforeEach(function () {
    $this->syncAction = Mockery::mock(SyncPlanWithBillableAction::class);
    $this->deleteAction = Mockery::mock(DeleteSubscriptionAction::class);
    $this->listener = new StripeWebhookListener($this->syncAction, $this->deleteAction);

    Config::set('plan-usage.models.billable', StripeListenerTestBillable::class);

    if (! Schema::hasColumn('test_billables', 'stripe_id')) {
        Schema::table('test_billables', function (Blueprint $table) {
            $table->string('stripe_id')->nullable();
        });
    }
});

function stripeWebhookPayload(string $eventId, string $customerId = 'cus_wh_test'): array
{
    return [
        'id' => $eventId,
        'type' => 'customer.subscription.updated',
        'data' => [
            'object' => [
                'id' => 'sub_wh_test',
                'customer' => $customerId,
                'items' => [
                    'data' => [
                        ['price' => ['id' => 'price_wh_test']],
                    ],
                ],
            ],
        ],
    ];
}

it('processes a duplicate delivery only once', function () {
    StripeListenerTestBillable::create(['stripe_id' => 'cus_wh_test']);

    $this->syncAction->shouldReceive('execute')->once()->andReturn(true);

    $event = new WebhookHandled(stripeWebhookPayload('evt_dup_1'));

    $this->listener->handle($event);
    $this->listener->handle($event);
});

it('releases the dedupe key on failure so the provider retry is processed', function () {
    StripeListenerTestBillable::create(['stripe_id' => 'cus_wh_test']);

    // First delivery fails transiently; the redelivery must succeed. Swallowing
    // would return HTTP 200 (no provider retry), and a dedupe key left behind
    // would block the redelivery for an hour.
    $attempts = 0;
    $this->syncAction->shouldReceive('execute')
        ->twice()
        ->andReturnUsing(function () use (&$attempts) {
            if (++$attempts === 1) {
                throw new Exception('Transient database error');
            }

            return true;
        });

    $event = new WebhookHandled(stripeWebhookPayload('evt_retry_1'));

    expect(fn () => $this->listener->handle($event))
        ->toThrow(Exception::class, 'Transient database error');

    $this->listener->handle($event);

    expect($attempts)->toBe(2);
});
