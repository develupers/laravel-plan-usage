<?php

declare(strict_types=1);

use Develupers\PlanUsage\Contracts\BillingProvider;
use Develupers\PlanUsage\Providers\Paddle\PaddleProvider;

/**
 * Test suite for PaddleProvider implementation.
 */
describe('PaddleProvider', function () {
    beforeEach(function () {
        $this->provider = new PaddleProvider;
    });

    it('implements BillingProvider interface', function () {
        expect($this->provider)->toBeInstanceOf(BillingProvider::class);
    });

    it('returns correct provider name', function () {
        expect($this->provider->name())->toBe('paddle');
    });

    it('returns correct customer id column', function () {
        expect($this->provider->getCustomerIdColumn())->toBe('paddle_id');
    });

    it('returns correct price id column', function () {
        expect($this->provider->getPriceIdColumn())->toBe('paddle_price_id');
    });

    it('returns correct product id column', function () {
        expect($this->provider->getProductIdColumn())->toBe('paddle_product_id');
    });

    it('returns correct webhook event class', function () {
        expect($this->provider->getWebhookEventClass())
            ->toBe(\Laravel\Paddle\Events\WebhookReceived::class);
    });

    it('isInstalled returns true when cashier paddle available', function () {
        $isInstalled = $this->provider->isInstalled();
        $paddleExists = class_exists(\Laravel\Paddle\Cashier::class);

        expect($isInstalled)->toBe($paddleExists);
    });

    it('isSandbox returns boolean', function () {
        expect($this->provider->isSandbox())->toBeBool();
    });

    it('syncProducts returns expected structure', function () {
        $result = $this->provider->syncProducts([], ['dry_run' => true]);

        expect($result)->toBeArray()
            ->toHaveKey('created')
            ->toHaveKey('updated')
            ->toHaveKey('errors');
    });

    it('syncProducts dry run with empty plans returns clean result', function () {
        $result = $this->provider->syncProducts([], ['dry_run' => true]);

        // With no plans to sync, created and updated should be empty
        expect($result['created'])->toBeEmpty()
            ->and($result['updated'])->toBeEmpty();
    });
});
