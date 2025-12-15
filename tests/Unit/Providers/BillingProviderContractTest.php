<?php

declare(strict_types=1);

use Develupers\PlanUsage\Contracts\BillingProvider;
use Develupers\PlanUsage\Providers\Paddle\PaddleProvider;
use Develupers\PlanUsage\Providers\Stripe\StripeProvider;

/**
 * Test that both providers implement the BillingProvider contract correctly.
 */
describe('BillingProvider Contract', function () {

    dataset('providers', [
        'Stripe Provider' => [fn () => new StripeProvider],
        'Paddle Provider' => [fn () => new PaddleProvider],
    ]);

    it('implements BillingProvider interface', function (BillingProvider $provider) {
        expect($provider)->toBeInstanceOf(BillingProvider::class);
    })->with('providers');

    it('name returns string', function (BillingProvider $provider) {
        $name = $provider->name();

        expect($name)->toBeString()->not->toBeEmpty();
    })->with('providers');

    it('column methods return strings', function (BillingProvider $provider) {
        expect($provider->getCustomerIdColumn())->toBeString()->not->toBeEmpty()
            ->and($provider->getPriceIdColumn())->toBeString()->not->toBeEmpty()
            ->and($provider->getProductIdColumn())->toBeString()->not->toBeEmpty();
    })->with('providers');

    it('webhook event class is valid class string', function (BillingProvider $provider) {
        $eventClass = $provider->getWebhookEventClass();

        expect($eventClass)->toBeString()
            ->and($eventClass)->toMatch('/^[A-Za-z_\\\\]+$/');
    })->with('providers');

    it('isInstalled returns boolean', function (BillingProvider $provider) {
        expect($provider->isInstalled())->toBeBool();
    })->with('providers');

    it('syncProducts with empty collection returns proper structure', function (BillingProvider $provider) {
        $result = $provider->syncProducts([], ['dry_run' => true]);

        expect($result)->toBeArray()
            ->toHaveKey('created')
            ->toHaveKey('updated')
            ->toHaveKey('errors');
    })->with('providers');

    it('findBillableByCustomerId with invalid id returns null', function (BillingProvider $provider) {
        // Without proper app setup, this should return null gracefully
        $result = $provider->findBillableByCustomerId('invalid_id_12345');

        expect($result)->toBeNull();
    })->with('providers');

    it('stripe and paddle have different column names', function () {
        $stripe = new StripeProvider;
        $paddle = new PaddleProvider;

        expect($stripe->getCustomerIdColumn())->not->toBe($paddle->getCustomerIdColumn())
            ->and($stripe->getPriceIdColumn())->not->toBe($paddle->getPriceIdColumn())
            ->and($stripe->getProductIdColumn())->not->toBe($paddle->getProductIdColumn());
    });

    it('stripe and paddle have different names', function () {
        $stripe = new StripeProvider;
        $paddle = new PaddleProvider;

        expect($stripe->name())->not->toBe($paddle->name())
            ->and($stripe->name())->toBe('stripe')
            ->and($paddle->name())->toBe('paddle');
    });
});
