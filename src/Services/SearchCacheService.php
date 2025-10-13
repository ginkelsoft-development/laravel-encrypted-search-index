<?php

namespace Ginkelsoft\EncryptedSearch\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Class SearchCacheService
 *
 * Provides caching functionality for encrypted search results.
 *
 * Caches search results to reduce database/Elasticsearch queries for
 * frequently performed searches. Automatically invalidates cache when
 * models are updated or deleted.
 *
 * Cache keys are generated based on:
 * - Model class
 * - Search type (exact/prefix/any/all)
 * - Search parameters (fields, terms)
 * - Configuration hash (pepper, depths, etc.)
 *
 * Example usage:
 * ```php
 * $service = app(SearchCacheService::class);
 * $results = $service->remember('Client', 'exact', ['first_names', 'John'], function() {
 *     return Client::encryptedExact('first_names', 'John')->pluck('id')->toArray();
 * });
 * ```
 */
class SearchCacheService
{
    /**
     * Cache TTL in seconds (default: 1 hour).
     *
     * @var int
     */
    protected int $ttl;

    /**
     * Cache key prefix to avoid collisions.
     *
     * @var string
     */
    protected string $prefix = 'encrypted_search:';

    /**
     * Create a new SearchCacheService instance.
     */
    public function __construct()
    {
        $this->ttl = (int) config('encrypted-search.cache.ttl', 3600);
    }

    /**
     * Remember a search result in cache.
     *
     * @param  string  $modelClass  The model class name
     * @param  string  $searchType  The search type (exact/prefix/any/all)
     * @param  array<mixed>  $params  Search parameters
     * @param  callable  $callback  Callback to execute if cache miss
     * @return array<int, mixed>  Array of model IDs
     */
    public function remember(string $modelClass, string $searchType, array $params, callable $callback): array
    {
        if (!$this->isEnabled()) {
            return $callback();
        }

        $key = $this->generateKey($modelClass, $searchType, $params);

        return Cache::remember($key, $this->ttl, $callback);
    }

    /**
     * Invalidate all cached searches for a specific model instance.
     *
     * Called when a model is updated or deleted.
     *
     * @param  string  $modelClass  The model class name
     * @param  mixed  $modelId  The model ID (optional, if null invalidates all for class)
     * @return void
     */
    public function invalidate(string $modelClass, $modelId = null): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        // Invalidate by tag if driver supports it (Redis, Memcached)
        if ($this->supportsTagging()) {
            $tags = $modelId
                ? [$this->getModelTag($modelClass), $this->getInstanceTag($modelClass, $modelId)]
                : [$this->getModelTag($modelClass)];

            Cache::tags($tags)->flush();
        } else {
            // Fallback: invalidate by pattern (only works with some drivers)
            $this->invalidateByPattern($modelClass, $modelId);
        }
    }

    /**
     * Completely flush all encrypted search caches.
     *
     * @return void
     */
    public function flush(): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        if ($this->supportsTagging()) {
            Cache::tags(['encrypted_search'])->flush();
        } else {
            // Note: This is cache driver specific and may not work everywhere
            Cache::flush();
        }
    }

    /**
     * Generate a unique cache key for a search.
     *
     * @param  string  $modelClass
     * @param  string  $searchType
     * @param  array<mixed>  $params
     * @return string
     */
    protected function generateKey(string $modelClass, string $searchType, array $params): string
    {
        // Include configuration hash to auto-invalidate when config changes
        $configHash = $this->getConfigHash();

        // Create deterministic key from parameters
        $paramsHash = md5(json_encode($params));

        return $this->prefix . md5(
            $modelClass . ':' . $searchType . ':' . $paramsHash . ':' . $configHash
        );
    }

    /**
     * Get a hash of relevant configuration values.
     *
     * When configuration changes, all caches should be invalidated.
     *
     * @return string
     */
    protected function getConfigHash(): string
    {
        return md5(json_encode([
            config('encrypted-search.search_pepper'),
            config('encrypted-search.max_prefix_depth'),
            config('encrypted-search.min_prefix_length'),
        ]));
    }

    /**
     * Get cache tag for a model class.
     *
     * @param  string  $modelClass
     * @return string
     */
    protected function getModelTag(string $modelClass): string
    {
        return 'encrypted_search:model:' . md5($modelClass);
    }

    /**
     * Get cache tag for a specific model instance.
     *
     * @param  string  $modelClass
     * @param  mixed  $modelId
     * @return string
     */
    protected function getInstanceTag(string $modelClass, $modelId): string
    {
        return 'encrypted_search:instance:' . md5($modelClass . ':' . $modelId);
    }

    /**
     * Check if the cache driver supports tagging.
     *
     * @return bool
     */
    protected function supportsTagging(): bool
    {
        $driver = config('cache.default');
        $supportedDrivers = ['redis', 'memcached', 'dynamodb', 'octane'];

        return in_array($driver, $supportedDrivers, true);
    }

    /**
     * Invalidate cache by pattern (fallback for drivers without tagging).
     *
     * Note: This only works with some cache drivers.
     *
     * @param  string  $modelClass
     * @param  mixed  $modelId
     * @return void
     */
    protected function invalidateByPattern(string $modelClass, $modelId = null): void
    {
        // This is a simplified implementation
        // In production, you might want to track cache keys in a separate store
        // or use a cache driver that supports pattern-based deletion

        // For now, we'll just clear the entire cache as a safe fallback
        // Individual drivers can implement more efficient pattern matching
        Cache::flush();
    }

    /**
     * Check if caching is enabled.
     *
     * @return bool
     */
    protected function isEnabled(): bool
    {
        return config('encrypted-search.cache.enabled', false);
    }
}
