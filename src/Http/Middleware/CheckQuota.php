<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckQuota
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $featureSlug, float $amount = 1): Response
    {
        $billable = $this->getBillable($request);

        if (! $billable) {
            abort(403, 'No billable entity found.');
        }

        if (! method_exists($billable, 'canUseFeature')) {
            abort(500, 'Billable must use EnforcesQuotas trait.');
        }

        if (! $billable->canUseFeature($featureSlug, $amount)) {
            $remaining = method_exists($billable, 'getRemainingQuota')
                ? $billable->getRemainingQuota($featureSlug)
                : null;
            $message = is_null($remaining)
                ? "Feature '{$featureSlug}' is not available in your plan."
                : "Quota exceeded for '{$featureSlug}'. Remaining: {$remaining}.";

            abort(403, $message);
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
            if (method_exists($request->user(), 'canUseFeature')) {
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
