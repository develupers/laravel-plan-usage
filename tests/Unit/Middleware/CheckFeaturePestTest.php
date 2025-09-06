<?php

declare(strict_types=1);

use Develupers\PlanUsage\Http\Middleware\CheckFeature;
use Develupers\PlanUsage\Models\Feature;
use Develupers\PlanUsage\Models\Plan;
use Develupers\PlanUsage\Models\PlanFeature;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

describe('CheckFeature Middleware', function () {

    beforeEach(function () {
        $this->middleware = new CheckFeature;
        $this->request = Request::create('/test', 'GET');
    });

    it('allows access when billable has feature', function () {
        // Arrange
        $plan = Plan::factory()->create();
        $feature = Feature::factory()->create(['slug' => 'premium-feature']);

        PlanFeature::create([
            'plan_id' => $plan->id,
            'feature_id' => $feature->id,
            'value' => '1',
        ]);

        $user = new class
        {
            public $plan_id = 1;

            public function hasFeature($slug)
            {
                return $slug === 'premium-feature';
            }
        };

        $this->request->setUserResolver(fn () => $user);

        // Act
        $response = $this->middleware->handle(
            $this->request,
            fn ($request) => response('success'),
            'premium-feature'
        );

        // Assert
        expect($response->getContent())->toBe('success');
    });

    it('denies access when billable lacks feature', function () {
        // Arrange
        $user = new class
        {
            public function hasFeature($slug)
            {
                return false;
            }
        };

        $this->request->setUserResolver(fn () => $user);

        // Act & Assert
        expect(fn () => $this->middleware->handle(
            $this->request,
            fn ($request) => response('success'),
            'premium-feature'
        ))->toThrow(HttpException::class);
    });

    it('throws 403 when no billable entity found', function () {
        // Arrange
        $this->request->setUserResolver(fn () => null);

        // Act & Assert
        try {
            $this->middleware->handle(
                $this->request,
                fn ($request) => response('success'),
                'any-feature'
            );
        } catch (HttpException $e) {
            expect($e->getStatusCode())->toBe(403)
                ->and($e->getMessage())->toContain('No billable entity found');
        }
    });

    it('checks user account relationship for billable', function () {
        // Arrange
        $account = new class
        {
            public function hasFeature($slug)
            {
                return $slug === 'account-feature';
            }
        };

        $user = new class
        {
            public $account;

            public function __construct()
            {
                $this->account = new class
                {
                    public function hasFeature($slug)
                    {
                        return $slug === 'account-feature';
                    }
                };
            }
        };

        $this->request->setUserResolver(fn () => $user);

        // Act
        $response = $this->middleware->handle(
            $this->request,
            fn ($request) => response('success'),
            'account-feature'
        );

        // Assert
        expect($response->getContent())->toBe('success');
    });

    it('checks current team for billable', function () {
        // Arrange
        $user = new class
        {
            public function currentTeam()
            {
                return new class
                {
                    public function hasFeature($slug)
                    {
                        return $slug === 'team-feature';
                    }
                };
            }
        };

        $this->request->setUserResolver(fn () => $user);

        // Act
        $response = $this->middleware->handle(
            $this->request,
            fn ($request) => response('success'),
            'team-feature'
        );

        // Assert
        expect($response->getContent())->toBe('success');
    });
});
