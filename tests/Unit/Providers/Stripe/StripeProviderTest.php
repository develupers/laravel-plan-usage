<?php

declare(strict_types=1);

use Develupers\PlanUsage\Contracts\BillingProvider;
use Develupers\PlanUsage\Providers\Stripe\StripeProvider;

/**
 * Test suite for StripeProvider implementation.
 */
describe('StripeProvider', function () {
    beforeEach(function () {
        $this->provider = new StripeProvider;
    });

    it('implements BillingProvider interface', function () {
        expect($this->provider)->toBeInstanceOf(BillingProvider::class);
    });

    it('returns correct provider name', function () {
        expect($this->provider->name())->toBe('stripe');
    });

    it('returns correct customer id column', function () {
        expect($this->provider->getCustomerIdColumn())->toBe('stripe_id');
    });

    it('returns correct price id column', function () {
        expect($this->provider->getPriceIdColumn())->toBe('stripe_price_id');
    });

    it('returns correct product id column', function () {
        expect($this->provider->getProductIdColumn())->toBe('stripe_product_id');
    });

    it('returns correct webhook event class', function () {
        expect($this->provider->getWebhookEventClass())
            ->toBe(\Laravel\Cashier\Events\WebhookHandled::class);
    });

    it('isInstalled returns true when cashier available', function () {
        $isInstalled = $this->provider->isInstalled();
        $cashierExists = class_exists(\Laravel\Cashier\Cashier::class);

        expect($isInstalled)->toBe($cashierExists);
    });

    it('syncProducts returns expected structure', function () {
        $result = $this->provider->syncProducts([], ['dry_run' => true]);

        expect($result)->toBeArray()
            ->toHaveKey('created')
            ->toHaveKey('updated')
            ->toHaveKey('errors');
    });
});
