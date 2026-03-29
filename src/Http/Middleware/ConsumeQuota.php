<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ConsumeQuota
{
    /**
     * Handle an incoming request.
     *
     * On successful responses, enforces quota, increments usage, and logs.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $featureSlug, float $amount = 1): Response
    {
        $response = $next($request);

        // Only consume on successful responses
        if ($response->isSuccessful()) {
            $billable = $this->getBillable($request);

            if ($billable && method_exists($billable, 'consume')) {
                $metadata = [
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'url' => $request->fullUrl(),
                    'method' => $request->method(),
                ];

                $billable->consume($featureSlug, $amount, $metadata);
            }
        }

        return $response;
    }

    /**
     * Get the billable entity from the request
     */
    protected function getBillable(Request $request): mixed
    {
        // Try to get billable from authenticated user
        if ($request->user()) {
            // Check if user has a billable relationship
            if (method_exists($request->user(), 'billable')) {
                return $request->user()->billable();
            }

            // Check if user itself is billable
            if (method_exists($request->user(), 'consume')) {
                return $request->user();
            }

            // Check for account relationship
            /** @var object $user */
            $user = $request->user();
            if (method_exists($user, 'account') && property_exists($user, 'account')) {
                return $user->account;
            }

            // Check for current team
            if (method_exists($user, 'currentTeam') && property_exists($user, 'currentTeam')) {
                return $user->currentTeam;
            }
        }

        return null;
    }
}
