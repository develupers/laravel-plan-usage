<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Http\Middleware;

use Illuminate\Http\Request;

trait ResolvesBillable
{
    /**
     * Get the billable entity from the request.
     */
    protected function getBillable(Request $request): mixed
    {
        if (! $request->user()) {
            return null;
        }

        $user = $request->user();

        // Check if user has a billable relationship
        if (method_exists($user, 'billable')) {
            return $user->billable();
        }

        // Check if user itself is billable
        if (method_exists($user, 'consume') || method_exists($user, 'checkQuota') || method_exists($user, 'hasFeature')) {
            return $user;
        }

        // Check for account relationship (property or method)
        if (property_exists($user, 'account') || method_exists($user, 'account')) {
            $account = property_exists($user, 'account')
                ? $user->account
                : $user->account();

            if ($account && method_exists($account, 'consume')) {
                return $account;
            }
        }

        // Check for current team (property or method)
        if (property_exists($user, 'currentTeam') || method_exists($user, 'currentTeam')) {
            $team = method_exists($user, 'currentTeam')
                ? $user->currentTeam()
                : $user->currentTeam;

            if ($team && method_exists($team, 'consume')) {
                return $team;
            }
        }

        return null;
    }
}
