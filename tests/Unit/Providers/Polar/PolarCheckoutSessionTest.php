<?php

declare(strict_types=1);

use Develupers\PlanUsage\Providers\Polar\PolarCheckoutSession;

it('memoizes the generated checkout URL', function () {
    $checkout = new class
    {
        public int $calls = 0;

        public function url(): string
        {
            $this->calls++;

            return 'https://polar.sh/checkout/session_123';
        }
    };

    $session = new PolarCheckoutSession($checkout);

    expect($session->getUrl())->toBe('https://polar.sh/checkout/session_123')
        ->and($session->getId())->toBe('session_123')
        ->and($session->toArray())->toBe([
            'id' => 'session_123',
            'url' => 'https://polar.sh/checkout/session_123',
            'provider' => 'polar',
        ])
        ->and($checkout->calls)->toBe(1);
});
