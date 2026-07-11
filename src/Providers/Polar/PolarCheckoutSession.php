<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Providers\Polar;

use Develupers\PlanUsage\Contracts\CheckoutSession;
use Illuminate\Http\RedirectResponse;

class PolarCheckoutSession implements CheckoutSession
{
    private ?string $url = null;

    public function __construct(
        protected mixed $checkout
    ) {}

    public function getUrl(): string
    {
        if ($this->url !== null) {
            return $this->url;
        }

        if (! method_exists($this->checkout, 'url')) {
            return '';
        }

        return $this->url = (string) $this->checkout->url();
    }

    public function getId(): string
    {
        $path = parse_url($this->getUrl(), PHP_URL_PATH);
        $id = is_string($path) ? basename($path) : '';

        return $id !== '' && $id !== '/' ? $id : 'polar_'.hash('sha256', $this->getUrl());
    }

    public function redirect(): RedirectResponse
    {
        return redirect()->away($this->getUrl(), 303);
    }

    public function getProviderCheckout(): mixed
    {
        return $this->checkout;
    }

    /**
     * @return array{id: string, url: string, provider: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->getId(),
            'url' => $this->getUrl(),
            'provider' => 'polar',
        ];
    }
}
