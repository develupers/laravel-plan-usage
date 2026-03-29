<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckFeature
{
    use ResolvesBillable;

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
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
}
