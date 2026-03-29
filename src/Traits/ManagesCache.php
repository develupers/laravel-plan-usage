<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Traits;

use Illuminate\Support\Facades\Cache;

trait ManagesCache
{
    /**
     * Check if caching is enabled.
     */
    protected function isCacheEnabled(): bool
    {
        return (bool) config('plan-usage.cache.enabled', false);
    }

    /**
     * Get cache store instance
     */
    protected function getCacheStore()
    {
        $store = config('plan-usage.cache.store');

        return Cache::store($store);
    }

    /**
     * Check if cache tags are supported
     */
    protected function supportsCacheTags(): bool
    {
        if (! config('plan-usage.cache.use_tags', true)) {
            return false;
        }

        // Resolve the actual store driver name
        $store = config('plan-usage.cache.store') ?? config('cache.default');

        return in_array($store, ['redis', 'memcached', 'dynamodb', 'octane']);
    }

    /**
     * Get cache instance with optional tags
     */
    protected function cache(array $tags = [])
    {
        if (! $this->isCacheEnabled()) {
            return null;
        }

        $cache = $this->getCacheStore();

        if ($this->supportsCacheTags() && ! empty($tags)) {
            return $cache->tags($tags);
        }

        return $cache;
    }

    /**
     * Remember value in cache with optional tags
     */
    protected function cacheRemember(string $key, array $tags, \Closure $callback, ?string $type = null)
    {
        if (! $this->isCacheEnabled()) {
            return $callback();
        }

        // Check selective caching for specific type
        if ($type && ! config("plan-usage.cache.selective.{$type}", true)) {
            return $callback();
        }

        // Get TTL based on type or use default
        $ttl = $this->getCacheTtl($type);

        // If tags are supported and provided, use tagged cache
        if ($this->supportsCacheTags() && ! empty($tags)) {
            $cache = $this->getCacheStore()->tags($tags);

            return $cache->remember($key, $ttl, $callback);
        }

        // Otherwise use regular cache (this ensures Cache::has() works in tests)
        return $this->getCacheStore()->remember($key, $ttl, $callback);
    }

    /**
     * Forget cache key with optional tags
     */
    protected function cacheForget(string $key, array $tags = []): void
    {
        if (! $this->isCacheEnabled()) {
            return;
        }

        $cache = $this->cache($tags);

        if ($cache) {
            $cache->forget($key);
        }
    }

    /**
     * Flush cache by tags
     */
    protected function cacheFlushTags(array $tags): void
    {
        if (! $this->isCacheEnabled()) {
            return;
        }

        if ($this->supportsCacheTags()) {
            $cache = $this->cache($tags);
            if ($cache) {
                $cache->flush();
            }
        }
    }

    /**
     * Get cache tags for plans
     */
    protected function getPlanCacheTags(?int $planId = null): array
    {
        $tags = ['plan-usage', 'plans'];

        if ($planId) {
            $tags[] = "plan:{$planId}";
        }

        return $tags;
    }

    /**
     * Get cache tags for quotas
     */
    protected function getQuotaCacheTags(string $billableClass, $billableId): array
    {
        return [
            'plan-usage',
            'quotas',
            "billable:{$billableClass}:{$billableId}",
        ];
    }

    /**
     * Get cache tags for features
     */
    protected function getFeatureCacheTags(string $featureSlug): array
    {
        return [
            'plan-usage',
            'features',
            "feature:{$featureSlug}",
        ];
    }

    /**
     * Get cache TTL based on type
     */
    protected function getCacheTtl(?string $type = null): int
    {
        if ($type) {
            return config("plan-usage.cache.ttl.{$type}", config('plan-usage.cache.ttl.default', 3600));
        }

        return config('plan-usage.cache.ttl.default', 3600);
    }

    /**
     * Check if specific cache type is enabled
     */
    protected function isCacheTypeEnabled(string $type): bool
    {
        if (! $this->isCacheEnabled()) {
            return false;
        }

        return config("plan-usage.cache.selective.{$type}", true);
    }
}
