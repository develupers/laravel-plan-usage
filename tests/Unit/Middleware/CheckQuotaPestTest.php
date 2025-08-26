<?php

declare(strict_types=1);

use Develupers\PlanUsage\Middleware\CheckQuota;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

describe('CheckQuota Middleware', function () {
    
    beforeEach(function () {
        $this->middleware = new CheckQuota();
        $this->request = Request::create('/test', 'GET');
    });
    
    it('allows access when quota is available', function () {
        // Arrange
        $user = new class {
            public function canUseFeature($slug, $amount) {
                return true;
            }
            
            public function getRemainingQuota($slug) {
                return 100;
            }
        };
        
        $this->request->setUserResolver(fn() => $user);
        
        // Act
        $response = $this->middleware->handle(
            $this->request,
            fn($request) => response('success'),
            'api-calls',
            10
        );
        
        // Assert
        expect($response->getContent())->toBe('success');
    });
    
    it('denies access when quota is exceeded', function () {
        // Arrange
        $user = new class {
            public function canUseFeature($slug, $amount) {
                return false;
            }
            
            public function getRemainingQuota($slug) {
                return 5;
            }
        };
        
        $this->request->setUserResolver(fn() => $user);
        
        // Act & Assert
        try {
            $this->middleware->handle(
                $this->request,
                fn($request) => response('success'),
                'api-calls',
                10
            );
        } catch (HttpException $e) {
            expect($e->getStatusCode())->toBe(403)
                ->and($e->getMessage())->toContain('Remaining: 5');
        }
    });
    
    it('shows feature not available message when quota is null', function () {
        // Arrange
        $user = new class {
            public function canUseFeature($slug, $amount) {
                return false;
            }
            
            public function getRemainingQuota($slug) {
                return null;
            }
        };
        
        $this->request->setUserResolver(fn() => $user);
        
        // Act & Assert
        try {
            $this->middleware->handle(
                $this->request,
                fn($request) => response('success'),
                'unavailable-feature',
                1
            );
        } catch (HttpException $e) {
            expect($e->getStatusCode())->toBe(403)
                ->and($e->getMessage())->toContain('not available in your plan');
        }
    });
    
    it('uses default amount of 1 when not specified', function () {
        // Arrange
        $user = new class {
            public $lastAmount;
            
            public function canUseFeature($slug, $amount) {
                $this->lastAmount = $amount;
                return true;
            }
            
            public function getRemainingQuota($slug) {
                return 100;
            }
        };
        
        $this->request->setUserResolver(fn() => $user);
        
        // Act
        $this->middleware->handle(
            $this->request,
            fn($request) => response('success'),
            'api-calls'
        );
        
        // Assert
        expect($user->lastAmount)->toBe(1.0);
    });
});

describe('CheckQuota Middleware with datasets', function () {
    
    it('handles different quota amounts', function (int $amount) {
        // Arrange
        $user = new class {
            public $requestedAmount;
            
            public function canUseFeature($slug, $amount) {
                $this->requestedAmount = $amount;
                return $amount <= 100;
            }
            
            public function getRemainingQuota($slug) {
                return 100;
            }
        };
        
        $request = Request::create('/test', 'GET');
        $request->setUserResolver(fn() => $user);
        $middleware = new CheckQuota();
        
        // Act
        $canAccess = $amount <= 100;
        
        if ($canAccess) {
            $response = $middleware->handle(
                $request,
                fn($request) => response('success'),
                'api-calls',
                $amount
            );
            
            expect($response->getContent())->toBe('success')
                ->and($user->requestedAmount)->toBe((float)$amount);
        } else {
            expect(fn() => $middleware->handle(
                $request,
                fn($request) => response('success'),
                'api-calls',
                $amount
            ))->toThrow(HttpException::class);
        }
    })->with('usage_amounts');
});