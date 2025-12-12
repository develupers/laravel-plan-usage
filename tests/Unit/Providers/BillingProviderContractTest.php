<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Tests\Unit\Providers;

use Develupers\PlanUsage\Contracts\BillingProvider;
use Develupers\PlanUsage\Providers\Paddle\PaddleProvider;
use Develupers\PlanUsage\Providers\Stripe\StripeProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test that both providers implement the BillingProvider contract correctly.
 */
class BillingProviderContractTest extends TestCase
{
    /**
     * @dataProvider providerClassProvider
     */
    public function test_provider_implements_billing_provider_interface(string $providerClass): void
    {
        $provider = new $providerClass();
        $this->assertInstanceOf(BillingProvider::class, $provider);
    }

    /**
     * @dataProvider providerClassProvider
     */
    public function test_provider_name_returns_string(string $providerClass): void
    {
        $provider = new $providerClass();
        $name = $provider->name();

        $this->assertIsString($name);
        $this->assertNotEmpty($name);
    }

    /**
     * @dataProvider providerClassProvider
     */
    public function test_provider_column_methods_return_strings(string $providerClass): void
    {
        $provider = new $providerClass();

        $this->assertIsString($provider->getCustomerIdColumn());
        $this->assertIsString($provider->getPriceIdColumn());
        $this->assertIsString($provider->getProductIdColumn());

        $this->assertNotEmpty($provider->getCustomerIdColumn());
        $this->assertNotEmpty($provider->getPriceIdColumn());
        $this->assertNotEmpty($provider->getProductIdColumn());
    }

    /**
     * @dataProvider providerClassProvider
     */
    public function test_provider_webhook_event_class_is_valid_class_string(string $providerClass): void
    {
        $provider = new $providerClass();
        $eventClass = $provider->getWebhookEventClass();

        $this->assertIsString($eventClass);
        // The class may not exist if the package isn't installed, but should be a valid class name format
        $this->assertMatchesRegularExpression('/^[A-Za-z_\\\\]+$/', $eventClass);
    }

    /**
     * @dataProvider providerClassProvider
     */
    public function test_provider_is_installed_returns_boolean(string $providerClass): void
    {
        $provider = new $providerClass();
        $this->assertIsBool($provider->isInstalled());
    }

    /**
     * @dataProvider providerClassProvider
     */
    public function test_provider_sync_products_with_empty_collection(string $providerClass): void
    {
        $provider = new $providerClass();
        $result = $provider->syncProducts([], ['dry_run' => true]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('created', $result);
        $this->assertArrayHasKey('updated', $result);
        $this->assertArrayHasKey('errors', $result);
    }

    /**
     * @dataProvider providerClassProvider
     */
    public function test_provider_find_billable_with_invalid_id_returns_null(string $providerClass): void
    {
        $provider = new $providerClass();

        // Without proper app setup, this should return null gracefully
        $result = $provider->findBillableByCustomerId('invalid_id_12345');

        $this->assertNull($result);
    }

    public static function providerClassProvider(): array
    {
        return [
            'Stripe Provider' => [StripeProvider::class],
            'Paddle Provider' => [PaddleProvider::class],
        ];
    }

    public function test_stripe_and_paddle_have_different_column_names(): void
    {
        $stripe = new StripeProvider();
        $paddle = new PaddleProvider();

        // Customer ID columns should be different
        $this->assertNotEquals(
            $stripe->getCustomerIdColumn(),
            $paddle->getCustomerIdColumn()
        );

        // Price ID columns should be different
        $this->assertNotEquals(
            $stripe->getPriceIdColumn(),
            $paddle->getPriceIdColumn()
        );

        // Product ID columns should be different
        $this->assertNotEquals(
            $stripe->getProductIdColumn(),
            $paddle->getProductIdColumn()
        );
    }

    public function test_stripe_and_paddle_have_different_names(): void
    {
        $stripe = new StripeProvider();
        $paddle = new PaddleProvider();

        $this->assertNotEquals($stripe->name(), $paddle->name());
        $this->assertEquals('stripe', $stripe->name());
        $this->assertEquals('paddle', $paddle->name());
    }
}
