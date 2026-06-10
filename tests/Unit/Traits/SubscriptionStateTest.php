<?php

declare(strict_types=1);

use Develupers\PlanUsage\Traits\HasPlanFeatures;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Paddle\Billable;

/**
 * Concrete billable backed by the shared test_billables table, using the
 * Cashier Paddle Billable trait (morph-keyed subscriptions, matching the
 * billable_type/billable_id columns). The helpers only rely on
 * canceled()/onGracePeriod(), which exist on both Cashier Stripe and Cashier
 * Paddle subscription models.
 */
class SubscriptionStateTestBillable extends Model
{
    use Billable;
    use HasPlanFeatures;

    public $timestamps = false;

    protected $table = 'test_billables';

    protected $guarded = [];
}

beforeEach(function () {
    // The base TestCase ships a Stripe-shaped subscriptions table; swap it for
    // the Cashier Paddle shape these helpers are exercised against.
    Schema::dropIfExists('subscriptions');
    Schema::create('subscriptions', function (Blueprint $table) {
        $table->id();
        $table->morphs('billable');
        $table->string('type');
        $table->string('paddle_id')->unique();
        $table->string('status');
        $table->timestamp('trial_ends_at')->nullable();
        $table->timestamp('paused_at')->nullable();
        $table->timestamp('ends_at')->nullable();
        $table->timestamps();
    });
});

function paddleSubscriptionRow(SubscriptionStateTestBillable $billable, string $status, ?DateTimeInterface $endsAt = null): void
{
    $billable->subscriptions()->create([
        'type' => 'default',
        'paddle_id' => 'sub_state_'.uniqid(),
        'status' => $status,
        'ends_at' => $endsAt,
    ]);
}

it('reports no live or ended subscription when none exists', function () {
    $billable = SubscriptionStateTestBillable::create([]);

    expect($billable->hasLiveSubscription())->toBeFalse()
        ->and($billable->subscriptionHasEnded())->toBeFalse();
});

it('treats an active subscription as live and not ended', function () {
    $billable = SubscriptionStateTestBillable::create([]);
    paddleSubscriptionRow($billable, 'active');

    expect($billable->fresh()->hasLiveSubscription())->toBeTrue()
        ->and($billable->fresh()->subscriptionHasEnded())->toBeFalse();
});

it('treats a grace-period cancellation as live and not ended', function () {
    $billable = SubscriptionStateTestBillable::create([]);
    paddleSubscriptionRow($billable, 'canceled', now()->addWeek());

    expect($billable->fresh()->hasLiveSubscription())->toBeTrue()
        ->and($billable->fresh()->subscriptionHasEnded())->toBeFalse();
});

it('treats a paused subscription as live — fresh checkout would duplicate it', function () {
    $billable = SubscriptionStateTestBillable::create([]);
    paddleSubscriptionRow($billable, 'paused');

    expect($billable->fresh()->hasLiveSubscription())->toBeTrue()
        ->and($billable->fresh()->subscriptionHasEnded())->toBeFalse();
});

it('treats a canceled subscription past its grace period as ended, not live', function () {
    $billable = SubscriptionStateTestBillable::create([]);
    paddleSubscriptionRow($billable, 'canceled', now()->subDay());

    expect($billable->fresh()->hasLiveSubscription())->toBeFalse()
        ->and($billable->fresh()->subscriptionHasEnded())->toBeTrue();
});
