<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ConsumeQuota
{
    use ResolvesBillable;

    /**
     * Handle an incoming request.
     *
     * On successful responses, enforces quota, increments usage, and logs.
     *
     * @param  Closure(Request): (Response)  $next
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
}
