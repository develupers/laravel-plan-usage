<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Support;

/**
 * The single source of truth for what a provider subscription status means
 * for entitlements. Webhook listeners, reconciliation, and subscription
 * enforcement all consult this class — divergent inline policies previously
 * let the enforcement job revoke customers the listeners intentionally kept
 * (e.g. Paddle trials, past_due under the keep policy).
 */
class EntitlementStatusPolicy
{
    public const GRANT = 'grant';

    public const KEEP = 'keep';

    public const REVOKE = 'revoke';

    /**
     * Decide the entitlement outcome for a subscription status.
     *
     * - active/trialing        → GRANT (sync the plan)
     * - past_due               → KEEP or REVOKE per
     *                            plan-usage.{provider}.past_due_keeps_entitlements
     * - everything else        → REVOKE (canceled, paused, unpaid, incomplete,
     *                            incomplete_expired, unknown). `incomplete`
     *                            revokes deliberately: the default subscription
     *                            being unpaid must not leave an older paid plan
     *                            in place.
     */
    public static function decide(string $provider, string $status): string
    {
        if (in_array($status, ['active', 'trialing'], true)) {
            return self::GRANT;
        }

        // Polar reports a scheduled (grace-period) cancellation as status
        // 'canceled' while entitlements remain until subscription.revoked /
        // ended_at — unlike Stripe/Paddle, whose canceled status is terminal.
        if ($provider === 'polar' && $status === 'canceled') {
            return self::KEEP;
        }

        if ($status === 'past_due') {
            return config("plan-usage.{$provider}.past_due_keeps_entitlements", true)
                ? self::KEEP
                : self::REVOKE;
        }

        return self::REVOKE;
    }

    /**
     * Whether a locally stored subscription status still holds entitlements —
     * used by enforcement paths that only have the local row (no remote
     * refetch). GRANT and KEEP both hold; only REVOKE does not.
     */
    public static function statusHoldsEntitlements(string $provider, mixed $status): bool
    {
        if ($status instanceof \BackedEnum) {
            $status = $status->value;
        }

        if (! is_string($status) || $status === '') {
            return false;
        }

        return self::decide($provider, $status) !== self::REVOKE;
    }
}
