<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckQuota
{
    use ResolvesBillable;

    /**
     * Handle an incoming request.
     *
     * Read-only gate — blocks the request if quota is exceeded.
     * Actual quota consumption is handled by ConsumeQuota middleware or consume() method.
     *
     * @param  \Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $featureSlug, float $amount = 1): Response
    {
        $billable = $this->getBillable($request);

        if (! $billable) {
            abort(403, 'No billable entity found.');
        }

        if (! method_exists($billable, 'checkQuota')) {
            abort(500, 'Billable must use EnforcesQuotas trait.');
        }

        if (! $billable->checkQuota($featureSlug, $amount)) {
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
}
