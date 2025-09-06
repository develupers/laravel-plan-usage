<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Traits;

use Illuminate\Support\Facades\Cache;

trait ManagesCache
{
    /**
     * Get cache store instance
     */
    protected function getCacheStore()
    {
        $store = config('plan-usage.cache.store', 'redis');
        return Cache::store($store);
    }

    /**
     * Check if cache tags are supported
     */
    protected function supportsCacheTags(): bool
    {
        if (!config('plan-usage.cache.use_tags', true)) {
            return false;
        }
        
        $store = config('plan-usage.cache.store', 'redis');
        return in_array($store, ['redis', 'memcached', 'dynamodb', 'octane']);
    }

    /**
     * Get cache instance with optional tags
     */
    protected function cache(array $tags = [])
    {
        if (!config('plan-usage.cache.enabled', true)) {
            return null;
        }

        $cache = $this->getCacheStore();

        if ($this->supportsCacheTags() && !empty($tags)) {
            return $cache->tags($tags);
        }

        return $cache;
    }

    /**
     * Remember value in cache with optional tags
     */
    protected function cacheRemember(string $key, array $tags, \Closure $callback, ?string $type = null)
    {
        if (!config('plan-usage.cache.enabled', true)) {
            return $callback();
        }

        // Check selective caching for specific type
        if ($type && !config("plan-usage.cache.selective.{$type}", true)) {
            return $callback();
        }

        // Get TTL based on type or use default
        $ttl = $this->getCacheTtl($type);
        $cache = $this->cache($tags);

        if ($cache) {
            return $cache->remember($key, $ttl, $callback);
        }

        return $callback();
    }

    /**
     * Forget cache key with optional tags
     */
    protected function cacheForget(string $key, array $tags = []): void
    {
        if (!config('plan-usage.cache.enabled', true)) {
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
        if (!config('plan-usage.cache.enabled', true)) {
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
        if (!config('plan-usage.cache.enabled', true)) {
            return false;
        }

        return config("plan-usage.cache.selective.{$type}", true);
    }
}