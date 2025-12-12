<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Tests\Unit\Providers\Stripe;

use Develupers\PlanUsage\Contracts\BillingProvider;
use Develupers\PlanUsage\Providers\Stripe\StripeProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test suite for StripeProvider implementation.
 *
 * Run with: BILLING_PROVIDER=stripe php artisan test --filter=StripeProviderTest
 */
class StripeProviderTest extends TestCase
{
    private StripeProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new StripeProvider();
    }

    public function test_implements_billing_provider_interface(): void
    {
        $this->assertInstanceOf(BillingProvider::class, $this->provider);
    }

    public function test_returns_correct_provider_name(): void
    {
        $this->assertEquals('stripe', $this->provider->name());
    }

    public function test_returns_correct_customer_id_column(): void
    {
        $this->assertEquals('stripe_id', $this->provider->getCustomerIdColumn());
    }

    public function test_returns_correct_price_id_column(): void
    {
        $this->assertEquals('stripe_price_id', $this->provider->getPriceIdColumn());
    }

    public function test_returns_correct_product_id_column(): void
    {
        $this->assertEquals('stripe_product_id', $this->provider->getProductIdColumn());
    }

    public function test_returns_correct_webhook_event_class(): void
    {
        $this->assertEquals(
            \Laravel\Cashier\Events\WebhookHandled::class,
            $this->provider->getWebhookEventClass()
        );
    }

    public function test_is_installed_returns_true_when_cashier_available(): void
    {
        // This test will pass if laravel/cashier is installed
        $isInstalled = $this->provider->isInstalled();

        // Check if the Cashier class exists
        $cashierExists = class_exists(\Laravel\Cashier\Cashier::class);

        $this->assertEquals($cashierExists, $isInstalled);
    }

    public function test_sync_products_returns_expected_structure(): void
    {
        // Test with empty plans collection
        $result = $this->provider->syncProducts([], ['dry_run' => true]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('created', $result);
        $this->assertArrayHasKey('updated', $result);
        $this->assertArrayHasKey('errors', $result);
    }
}
