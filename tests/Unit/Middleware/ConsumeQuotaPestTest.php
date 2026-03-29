<?php

declare(strict_types=1);

use Develupers\PlanUsage\Http\Middleware\ConsumeQuota;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

describe('ConsumeQuota Middleware', function () {

    beforeEach(function () {
        $this->middleware = new ConsumeQuota;
        $this->request = Request::create('/test', 'POST');
        $this->request->server->set('REMOTE_ADDR', '127.0.0.1');
        $this->request->headers->set('User-Agent', 'Test Browser');
    });

    it('consumes quota on successful responses', function () {
        // Arrange
        $user = new class
        {
            public $consumed = false;

            public $consumedData = null;

            public function consume($slug, $amount, $metadata)
            {
                $this->consumed = true;
                $this->consumedData = [
                    'slug' => $slug,
                    'amount' => $amount,
                    'metadata' => $metadata,
                ];

                return true;
            }
        };

        $this->request->setUserResolver(fn () => $user);

        // Act
        $response = $this->middleware->handle(
            $this->request,
            fn ($request) => new Response('success', 200),
            'api-calls',
            5
        );

        // Assert
        expect($response->getContent())->toBe('success')
            ->and($user->consumed)->toBeTrue()
            ->and($user->consumedData['slug'])->toBe('api-calls')
            ->and($user->consumedData['amount'])->toBe(5.0)
            ->and($user->consumedData['metadata'])->toHaveKeys(['ip', 'user_agent', 'url', 'method']);
    });

    it('does not consume on failed responses', function () {
        // Arrange
        $user = new class
        {
            public $consumed = false;

            public function consume($slug, $amount, $metadata)
            {
                $this->consumed = true;

                return true;
            }
        };

        $this->request->setUserResolver(fn () => $user);

        // Act
        $response = $this->middleware->handle(
            $this->request,
            fn ($request) => new Response('error', 500),
            'api-calls',
            5
        );

        // Assert
        expect($response->getStatusCode())->toBe(500)
            ->and($user->consumed)->toBeFalse();
    });

    it('captures correct metadata', function () {
        // Arrange
        $user = new class
        {
            public $metadata = null;

            public function consume($slug, $amount, $metadata)
            {
                $this->metadata = $metadata;

                return true;
            }
        };

        $this->request->setUserResolver(fn () => $user);

        // Act
        $this->middleware->handle(
            $this->request,
            fn ($request) => new Response('success', 200),
            'api-calls'
        );

        // Assert
        expect($user->metadata)->not->toBeNull()
            ->and($user->metadata['ip'])->toBe('127.0.0.1')
            ->and($user->metadata['user_agent'])->toBe('Test Browser')
            ->and($user->metadata['url'])->toContain('/test')
            ->and($user->metadata['method'])->toBe('POST');
    });

    it('uses default amount of 1 when not specified', function () {
        // Arrange
        $user = new class
        {
            public $consumedAmount = null;

            public function consume($slug, $amount, $metadata)
            {
                $this->consumedAmount = $amount;

                return true;
            }
        };

        $this->request->setUserResolver(fn () => $user);

        // Act
        $this->middleware->handle(
            $this->request,
            fn ($request) => new Response('success', 200),
            'api-calls'
        );

        // Assert
        expect($user->consumedAmount)->toBe(1.0);
    });

    it('handles missing billable gracefully', function () {
        // Arrange
        $this->request->setUserResolver(fn () => null);

        // Act
        $response = $this->middleware->handle(
            $this->request,
            fn ($request) => new Response('success', 200),
            'api-calls'
        );

        // Assert - Should not throw error
        expect($response->getContent())->toBe('success');
    });
});

describe('ConsumeQuota Middleware with different response codes', function () {

    test('consumes quota for successful status codes only', function (int $statusCode) {
        // Arrange
        $middleware = new ConsumeQuota;
        $request = Request::create('/test', 'GET');

        $user = new class
        {
            public $consumed = false;

            public function consume($slug, $amount, $metadata)
            {
                $this->consumed = true;

                return true;
            }
        };

        $request->setUserResolver(fn () => $user);

        // Act
        $response = $middleware->handle(
            $request,
            fn ($request) => new Response('content', $statusCode),
            'feature'
        );

        // Assert
        $shouldConsume = $statusCode >= 200 && $statusCode < 300;
        expect($user->consumed)->toBe($shouldConsume);
    })->with([
        'OK' => [200],
        'Created' => [201],
        'Accepted' => [202],
        'No Content' => [204],
        'Redirect' => [302],
        'Bad Request' => [400],
        'Unauthorized' => [401],
        'Forbidden' => [403],
        'Not Found' => [404],
        'Server Error' => [500],
    ]);
});
