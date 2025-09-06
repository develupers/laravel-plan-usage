<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrackUsage
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $featureSlug, float $amount = 1): Response
    {
        $response = $next($request);

        // Only track usage on successful responses
        if ($response->isSuccessful()) {
            $billable = $this->getBillable($request);

            if ($billable && method_exists($billable, 'recordUsage')) {
                $metadata = [
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'url' => $request->fullUrl(),
                    'method' => $request->method(),
                ];

                $billable->recordUsage($featureSlug, $amount, $metadata);
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
            if (method_exists($request->user(), 'recordUsage')) {
                return $request->user();
            }

            // Check for account relationship
            if (method_exists($request->user(), 'account')) {
                return $request->user()->account;
            }

            // Check for current team
            if (method_exists($request->user(), 'currentTeam')) {
                return $request->user()->currentTeam;
            }
        }

        return null;
    }
}
