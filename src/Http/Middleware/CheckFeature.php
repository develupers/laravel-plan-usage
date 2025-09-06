<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckFeature
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $featureSlug): Response
    {
        $billable = $this->getBillable($request);

        if (! $billable) {
            abort(403, 'No billable entity found.');
        }

        if (! method_exists($billable, 'hasFeature')) {
            abort(500, 'Billable must use HasPlanFeatures trait.');
        }

        if (! $billable->hasFeature($featureSlug)) {
            abort(403, "Access denied. Your plan doesn't include the '{$featureSlug}' feature.");
        }

        return $next($request);
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
            if (method_exists($request->user(), 'hasFeature')) {
                return $request->user();
            }

            // Check for account relationship (property or method)
            if (property_exists($request->user(), 'account') || method_exists($request->user(), 'account')) {
                $account = property_exists($request->user(), 'account')
                    ? $request->user()->account
                    : $request->user()->account();
                if ($account && method_exists($account, 'hasFeature')) {
                    return $account;
                }
            }

            // Check for current team (property or method)
            if (property_exists($request->user(), 'currentTeam') || method_exists($request->user(), 'currentTeam')) {
                $team = method_exists($request->user(), 'currentTeam')
                    ? $request->user()->currentTeam()
                    : $request->user()->currentTeam;
                if ($team && method_exists($team, 'hasFeature')) {
                    return $team;
                }
            }
        }

        return null;
    }
}
