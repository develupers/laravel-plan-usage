<?php

declare(strict_types=1);

use Develupers\PlanUsage\Contracts\BillingProvider;
use Develupers\PlanUsage\Providers\Paddle\PaddleProvider;
use Illuminate\Database\Eloquent\Model;
use Laravel\Paddle\Cashier;
use Laravel\Paddle\Checkout;
use Laravel\Paddle\Customer;
use Laravel\Paddle\Events\WebhookReceived;

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
            ->toBe(WebhookReceived::class);
    });

    it('isInstalled returns true when cashier paddle available', function () {
        $isInstalled = $this->provider->isInstalled();
        $paddleExists = class_exists(Cashier::class);

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

    it('createCheckoutSession builds a paddle checkout with subscription type and return url', function () {
        $customer = (new Customer)->forceFill(['paddle_id' => 'ctm_test_123']);
        $checkout = Checkout::customer($customer, ['pri_test_123' => 1]);

        $billable = Mockery::mock(Model::class);
        $billable->shouldReceive('checkout')
            ->once()
            ->with('pri_test_123')
            ->andReturn($checkout);

        $session = $this->provider->createCheckoutSession($billable, 'pri_test_123', [
            'subscription_name' => 'default',
            'success_url' => 'https://example.com/success',
            'custom_data' => ['plan_id' => 7],
        ]);

        $paddleCheckout = $session->getProviderCheckout();

        expect($paddleCheckout)->toBeInstanceOf(Checkout::class)
            ->and($paddleCheckout->getCustomData())->toBe(['plan_id' => 7, 'subscription_type' => 'default'])
            ->and($paddleCheckout->getReturnUrl())->toBe('https://example.com/success')
            ->and($paddleCheckout->getItems())->toBe([['priceId' => 'pri_test_123', 'quantity' => 1]]);
    });
});
