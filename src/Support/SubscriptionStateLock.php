<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Support;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Serializes every mutation of a billable's subscription state: plan-change
 * actions, cancellation, webhook processing, and reconciliation all acquire
 * the same per-billable lock so none of them can interleave.
 *
 * Every path follows the same ordering: cache lock → short database
 * transaction (row locks, local mutation) → commit.
 */
class SubscriptionStateLock
{
    /**
     * The lease must outlive the slowest provider API call made while the
     * lock is held — Stripe's SDK default request timeout is ~80 seconds.
     */
    public const LEASE_SECONDS = 120;

    public const WAIT_SECONDS = 10;

    /**
     * Run the callback while holding the billable's subscription-state lock.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     *
     * @throws LockTimeoutException when the lock cannot be acquired in time
     */
    public function block(Model $billable, callable $callback, int $waitSeconds = self::WAIT_SECONDS): mixed
    {
        $store = Cache::store(config('plan-usage.cache.store'))->getStore();

        if (! $store instanceof LockProvider) {
            throw new \RuntimeException(
                'The configured plan-usage cache store does not support atomic locks. '
                .'Set PLAN_USAGE_CACHE_STORE to a lock-capable store (redis, memcached, database, file).'
            );
        }

        return $store
            ->lock($this->key($billable), self::LEASE_SECONDS)
            ->block($waitSeconds, $callback);
    }

    public function key(Model $billable): string
    {
        return implode(':', [
            'plan-usage',
            'subscription-change',
            $billable->getMorphClass(),
            (string) $billable->getKey(),
        ]);
    }
}
