<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Tests\Unit\Providers\Paddle;

use Develupers\PlanUsage\Contracts\BillingProvider;
use Develupers\PlanUsage\Providers\Paddle\PaddleProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test suite for PaddleProvider implementation.
 *
 * Run with: BILLING_PROVIDER=paddle php artisan test --filter=PaddleProviderTest
 */
class PaddleProviderTest extends TestCase
{
    private PaddleProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new PaddleProvider();
    }

    public function test_implements_billing_provider_interface(): void
    {
        $this->assertInstanceOf(BillingProvider::class, $this->provider);
    }

    public function test_returns_correct_provider_name(): void
    {
        $this->assertEquals('paddle', $this->provider->name());
    }

    public function test_returns_correct_customer_id_column(): void
    {
        $this->assertEquals('paddle_id', $this->provider->getCustomerIdColumn());
    }

    public function test_returns_correct_price_id_column(): void
    {
        $this->assertEquals('paddle_price_id', $this->provider->getPriceIdColumn());
    }

    public function test_returns_correct_product_id_column(): void
    {
        $this->assertEquals('paddle_product_id', $this->provider->getProductIdColumn());
    }

    public function test_returns_correct_webhook_event_class(): void
    {
        $this->assertEquals(
            \Laravel\Paddle\Events\WebhookReceived::class,
            $this->provider->getWebhookEventClass()
        );
    }

    public function test_is_installed_returns_true_when_cashier_paddle_available(): void
    {
        // This test will pass if laravel/cashier-paddle is installed
        $isInstalled = $this->provider->isInstalled();

        // Check if the Paddle Cashier class exists
        $paddleExists = class_exists(\Laravel\Paddle\Cashier::class);

        $this->assertEquals($paddleExists, $isInstalled);
    }

    public function test_is_sandbox_returns_boolean(): void
    {
        $isSandbox = $this->provider->isSandbox();

        $this->assertIsBool($isSandbox);
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

    public function test_sync_products_dry_run_does_not_make_api_calls(): void
    {
        $result = $this->provider->syncProducts([], ['dry_run' => true]);

        // In dry run mode, no actual API calls should be made
        // and no errors should be present for empty input
        $this->assertEmpty($result['errors']);
    }
}
