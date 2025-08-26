<?php

declare(strict_types=1);

use Develupers\PlanUsage\Middleware\TrackUsage;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

describe('TrackUsage Middleware', function () {

    beforeEach(function () {
        $this->middleware = new TrackUsage;
        $this->request = Request::create('/test', 'POST');
        $this->request->server->set('REMOTE_ADDR', '127.0.0.1');
        $this->request->headers->set('User-Agent', 'Test Browser');
    });

    it('tracks usage on successful responses', function () {
        // Arrange
        $tracked = false;
        $trackedData = null;

        $user = new class
        {
            public $tracked = false;

            public $trackedData = null;

            public function recordUsage($slug, $amount, $metadata)
            {
                $this->tracked = true;
                $this->trackedData = [
                    'slug' => $slug,
                    'amount' => $amount,
                    'metadata' => $metadata,
                ];
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
            ->and($user->tracked)->toBeTrue()
            ->and($user->trackedData['slug'])->toBe('api-calls')
            ->and($user->trackedData['amount'])->toBe(5.0)
            ->and($user->trackedData['metadata'])->toHaveKeys(['ip', 'user_agent', 'url', 'method']);
    });

    it('does not track usage on failed responses', function () {
        // Arrange
        $user = new class
        {
            public $tracked = false;

            public function recordUsage($slug, $amount, $metadata)
            {
                $this->tracked = true;
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
            ->and($user->tracked)->toBeFalse();
    });

    it('captures correct metadata', function () {
        // Arrange
        $user = new class
        {
            public $metadata = null;

            public function recordUsage($slug, $amount, $metadata)
            {
                $this->metadata = $metadata;
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
            public $recordedAmount = null;

            public function recordUsage($slug, $amount, $metadata)
            {
                $this->recordedAmount = $amount;
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
        expect($user->recordedAmount)->toBe(1.0);
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

describe('TrackUsage Middleware with different response codes', function () {

    test('tracks usage for successful status codes', function (int $statusCode) {
        // Arrange
        $middleware = new TrackUsage;
        $request = Request::create('/test', 'GET');

        $user = new class
        {
            public $tracked = false;

            public function recordUsage($slug, $amount, $metadata)
            {
                $this->tracked = true;
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
        $shouldTrack = $statusCode >= 200 && $statusCode < 300;
        expect($user->tracked)->toBe($shouldTrack);
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
