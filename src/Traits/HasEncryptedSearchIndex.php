<?php

namespace Ginkelsoft\EncryptedSearch\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Ginkelsoft\EncryptedSearch\Models\SearchIndex;
use Ginkelsoft\EncryptedSearch\Services\ElasticsearchService;
use Ginkelsoft\EncryptedSearch\Support\Normalizer;
use Ginkelsoft\EncryptedSearch\Support\Tokens;

/**
 * Trait HasEncryptedSearchIndex
 *
 * Adds encrypted search indexing capabilities to Eloquent models.
 * Depending on configuration, the generated tokens are stored either
 * in a local database table (`encrypted_search_index`) or directly in
 * Elasticsearch for external indexing.
 *
 * Configuration:
 * - If `encrypted-search.elasticsearch.enabled = true`, tokens are
 *   sent directly to Elasticsearch (database index is skipped).
 * - Otherwise, tokens are stored in the local database index.
 *
 * Example usage:
 *
 * class Client extends Model {
 *     use HasEncryptedSearchIndex;
 *
 *     protected array $encryptedSearch = [
 *         'first_names' => ['exact' => true, 'prefix' => true],
 *         'last_names'  => ['exact' => true, 'prefix' => true],
 *     ];
 * }
 */
trait HasEncryptedSearchIndex
{
    /**
     * Boot logic for this trait.
     *
     * Automatically rebuilds or removes search index entries on
     * create, update, save, delete, restore, and force-delete events.
     *
     * @return void
     */
    public static function bootHasEncryptedSearchIndex(): void
    {
        static::created(fn(Model $m) => $m->updateSearchIndex());
        static::updated(fn(Model $m) => $m->updateSearchIndex());
        static::saved(fn(Model $m) => $m->updateSearchIndex());
        static::deleted(fn(Model $m) => $m->removeSearchIndex());

        if (in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
            static::forceDeleted(fn(Model $m) => $m->removeSearchIndex());
            static::restored(fn(Model $m) => $m->updateSearchIndex());
        }
    }

    /**
     * Build or refresh all encrypted search tokens for this model instance.
     * Tokens are written to either the local database or Elasticsearch.
     *
     * @return void
     */
    public function updateSearchIndex(): void
    {
        $config = $this->getEncryptedSearchConfiguration();

        if (empty($config)) {
            return;
        }

        $pepper = (string) config('encrypted-search.search_pepper', '');
        $max = (int) config('encrypted-search.max_prefix_depth', 6);
        $min = (int) config('encrypted-search.min_prefix_length', 1);
        $useElastic = config('encrypted-search.elasticsearch.enabled', false);

        $rows = [];

        foreach ($config as $field => $modes) {
            // Skip fields that don't have an encrypted cast
            if (!$this->hasEncryptedCast($field)) {
                continue;
            }

            $raw = (string) $this->getAttribute($field);
            if ($raw === '') {
                continue;
            }

            $normalized = Normalizer::normalize($raw);
            if (!$normalized) {
                continue;
            }

            // Generate exact-match tokens
            if (!empty($modes['exact'])) {
                $rows[] = [
                    'model_type' => static::class,
                    'model_id'   => $this->getKey(),
                    'field'      => $field,
                    'type'       => 'exact',
                    'token'      => Tokens::exact($normalized, $pepper),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            // Generate prefix-based tokens
            if (!empty($modes['prefix'])) {
                foreach (Tokens::prefixes($normalized, $max, $pepper, $min) as $token) {
                    $rows[] = [
                        'model_type' => static::class,
                        'model_id'   => $this->getKey(),
                        'field'      => $field,
                        'type'       => 'prefix',
                        'token'      => $token,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }
        }

        if (empty($rows)) {
            return;
        }

        // Debug logging
        if (config('encrypted-search.debug', false)) {
            logger()->debug('[EncryptedSearch] Updating search index', [
                'model' => static::class,
                'model_id' => $this->getKey(),
                'token_count' => count($rows),
                'backend' => $useElastic ? 'elasticsearch' : 'database',
            ]);
        }

        // Choose backend: Elasticsearch or Database
        if ($useElastic) {
            $this->syncToElasticsearch($rows);
        } else {
            // Remove existing tokens for this model before inserting new ones
            SearchIndex::where('model_type', static::class)
                ->where('model_id', $this->getKey())
                ->delete();

            // Bulk insert all new tokens in a single query
            SearchIndex::insert($rows);
        }
    }

    /**
     * Remove all search index entries related to this model instance.
     *
     * Depending on configuration, either the database index rows or
     * the corresponding Elasticsearch documents are deleted.
     *
     * @return void
     */
    public function removeSearchIndex(): void
    {
        $useElastic = config('encrypted-search.elasticsearch.enabled', false);

        // Debug logging
        if (config('encrypted-search.debug', false)) {
            logger()->debug('[EncryptedSearch] Removing search index', [
                'model' => static::class,
                'model_id' => $this->getKey(),
                'backend' => $useElastic ? 'elasticsearch' : 'database',
            ]);
        }

        if ($useElastic) {
            $this->removeFromElasticsearch();
        } else {
            SearchIndex::where('model_type', static::class)
                ->where('model_id', $this->getKey())
                ->delete();
        }
    }

    /**
     * Push generated tokens to the configured Elasticsearch index.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return void
     */
    protected function syncToElasticsearch(array $rows): void
    {
        $index = config('encrypted-search.elasticsearch.index', 'encrypted_search');
        $service = app(ElasticsearchService::class);

        foreach ($rows as $row) {
            $id = "{$row['model_type']}_{$row['model_id']}_{$row['field']}_{$row['type']}_{$row['token']}";
            $service->indexDocument($index, $id, $row);
        }
    }

    /**
     * Remove this model's tokens from the configured Elasticsearch index.
     *
     * Uses delete-by-query to efficiently remove all documents matching
     * the model_type and model_id.
     *
     * @return void
     */
    protected function removeFromElasticsearch(): void
    {
        $index = config('encrypted-search.elasticsearch.index', 'encrypted_search');
        $service = app(ElasticsearchService::class);

        $query = [
            'query' => [
                'bool' => [
                    'must' => [
                        ['term' => ['model_type.keyword' => static::class]],
                        ['term' => ['model_id' => $this->getKey()]],
                    ],
                ],
            ],
        ];

        try {
            $service->deleteByQuery($index, $query);
        } catch (\Throwable $e) {
            logger()->warning("Failed to remove Elasticsearch docs for model {$this->getKey()}: {$e->getMessage()}");
        }
    }

    /**
     * Scope: query models by exact encrypted token match.
     *
     * @param  Builder  $query
     * @param  string  $field
     * @param  string  $term
     * @return Builder
     */
    public function scopeEncryptedExact(Builder $query, string $field, string $term): Builder
    {
        $pepper = (string) config('encrypted-search.search_pepper', '');
        $normalized = Normalizer::normalize($term);

        if (!$normalized) {
            return $query->whereRaw('1=0');
        }

        $token = Tokens::exact($normalized, $pepper);

        // Check if Elasticsearch is enabled
        if (config('encrypted-search.elasticsearch.enabled', false)) {
            $modelIds = $this->searchElasticsearch($field, $token, 'exact');
            return $query->whereIn($this->getQualifiedKeyName(), $modelIds);
        }

        // Fallback to database
        return $query->whereIn($this->getQualifiedKeyName(), function ($sub) use ($field, $token) {
            $sub->select('model_id')
                ->from('encrypted_search_index')
                ->where('model_type', static::class)
                ->where('field', $field)
                ->where('type', 'exact')
                ->where('token', $token);
        });
    }

    /**
     * Scope: query models by exact encrypted token match across multiple fields.
     *
     * Searches for an exact match in any of the specified fields (OR logic).
     *
     * @param  Builder  $query
     * @param  array<int, string>  $fields
     * @param  string  $term
     * @return Builder
     */
    public function scopeEncryptedExactMulti(Builder $query, array $fields, string $term): Builder
    {
        if (empty($fields)) {
            return $query->whereRaw('1=0');
        }

        $pepper = (string) config('encrypted-search.search_pepper', '');
        $normalized = Normalizer::normalize($term);

        if (!$normalized) {
            return $query->whereRaw('1=0');
        }

        $token = Tokens::exact($normalized, $pepper);

        // Check if Elasticsearch is enabled
        if (config('encrypted-search.elasticsearch.enabled', false)) {
            $modelIds = $this->searchElasticsearchMulti($fields, $token, 'exact');
            return $query->whereIn($this->getQualifiedKeyName(), $modelIds);
        }

        // Fallback to database - use OR logic for multiple fields
        return $query->whereIn($this->getQualifiedKeyName(), function ($sub) use ($fields, $token) {
            $sub->select('model_id')
                ->from('encrypted_search_index')
                ->where('model_type', static::class)
                ->whereIn('field', $fields)
                ->where('type', 'exact')
                ->where('token', $token)
                ->distinct();
        });
    }

    /**
     * Scope: query models by prefix-based encrypted token match.
     *
     * @param  Builder  $query
     * @param  string  $field
     * @param  string  $term
     * @return Builder
     */
    public function scopeEncryptedPrefix(Builder $query, string $field, string $term): Builder
    {
        $pepper = (string) config('encrypted-search.search_pepper', '');
        $minLength = (int) config('encrypted-search.min_prefix_length', 1);
        $normalized = Normalizer::normalize($term);

        if (!$normalized) {
            return $query->whereRaw('1=0');
        }

        // Check if search term meets minimum length requirement
        if (mb_strlen($normalized, 'UTF-8') < $minLength) {
            return $query->whereRaw('1=0');
        }

        $tokens = Tokens::prefixes(
            $normalized,
            (int) config('encrypted-search.max_prefix_depth', 6),
            $pepper,
            $minLength
        );

        // If no tokens generated (term too short), return no results
        if (empty($tokens)) {
            return $query->whereRaw('1=0');
        }

        // Check if Elasticsearch is enabled
        if (config('encrypted-search.elasticsearch.enabled', false)) {
            $modelIds = $this->searchElasticsearch($field, $tokens, 'prefix');
            return $query->whereIn($this->getQualifiedKeyName(), $modelIds);
        }

        // Fallback to database with relevance sorting
        // Sort by field length (shorter matches = more relevant)
        return $query->whereIn($this->getQualifiedKeyName(), function ($sub) use ($field, $tokens) {
            $sub->select('model_id')
                ->from('encrypted_search_index')
                ->where('model_type', static::class)
                ->where('field', $field)
                ->where('type', 'prefix')
                ->whereIn('token', $tokens);
        })->orderByRaw("LENGTH({$field}) ASC");
    }

    /**
     * Scope: query models by prefix-based encrypted token match across multiple fields.
     *
     * Searches for a prefix match in any of the specified fields (OR logic).
     *
     * @param  Builder  $query
     * @param  array<int, string>  $fields
     * @param  string  $term
     * @return Builder
     */
    public function scopeEncryptedPrefixMulti(Builder $query, array $fields, string $term): Builder
    {
        if (empty($fields)) {
            return $query->whereRaw('1=0');
        }

        $pepper = (string) config('encrypted-search.search_pepper', '');
        $minLength = (int) config('encrypted-search.min_prefix_length', 1);
        $normalized = Normalizer::normalize($term);

        if (!$normalized) {
            return $query->whereRaw('1=0');
        }

        // Check if search term meets minimum length requirement
        if (mb_strlen($normalized, 'UTF-8') < $minLength) {
            return $query->whereRaw('1=0');
        }

        $tokens = Tokens::prefixes(
            $normalized,
            (int) config('encrypted-search.max_prefix_depth', 6),
            $pepper,
            $minLength
        );

        // If no tokens generated (term too short), return no results
        if (empty($tokens)) {
            return $query->whereRaw('1=0');
        }

        // Check if Elasticsearch is enabled
        if (config('encrypted-search.elasticsearch.enabled', false)) {
            $modelIds = $this->searchElasticsearchMulti($fields, $tokens, 'prefix');
            return $query->whereIn($this->getQualifiedKeyName(), $modelIds);
        }

        // Fallback to database - use OR logic for multiple fields
        // Note: Multi-field searches don't have relevance sorting due to database compatibility
        // Use single-field searches for relevance-sorted results
        return $query->whereIn($this->getQualifiedKeyName(), function ($sub) use ($fields, $tokens) {
            $sub->select('model_id')
                ->from('encrypted_search_index')
                ->where('model_type', static::class)
                ->whereIn('field', $fields)
                ->where('type', 'prefix')
                ->whereIn('token', $tokens)
                ->distinct();
        });
    }

    /**
     * Scope: search for a term across multiple fields using OR logic.
     *
     * @param  Builder  $query
     * @param  array<int, string>  $fields
     * @param  string  $term
     * @param  string  $type  Either 'exact' or 'prefix'
     * @return Builder
     */
    public function scopeEncryptedSearchAny(Builder $query, array $fields, string $term, string $type = 'exact'): Builder
    {
        if ($type === 'exact') {
            return $this->scopeEncryptedExactMulti($query, $fields, $term);
        }

        return $this->scopeEncryptedPrefixMulti($query, $fields, $term);
    }

    /**
     * Scope: search for multiple field-term pairs using AND logic.
     *
     * All specified field-term pairs must match for a record to be returned.
     *
     * @param  Builder  $query
     * @param  array<string, string>  $fieldTerms  Associative array of field => term pairs
     * @param  string  $type  Either 'exact' or 'prefix'
     * @return Builder
     */
    public function scopeEncryptedSearchAll(Builder $query, array $fieldTerms, string $type = 'exact'): Builder
    {
        if (empty($fieldTerms)) {
            return $query->whereRaw('1=0');
        }

        $pepper = (string) config('encrypted-search.search_pepper', '');
        $minLength = (int) config('encrypted-search.min_prefix_length', 1);
        $useElastic = config('encrypted-search.elasticsearch.enabled', false);

        // Build conditions for each field-term pair
        foreach ($fieldTerms as $field => $term) {
            $normalized = Normalizer::normalize($term);

            if (!$normalized) {
                return $query->whereRaw('1=0');
            }

            if ($type === 'prefix') {
                // Check minimum length for prefix searches
                if (mb_strlen($normalized, 'UTF-8') < $minLength) {
                    return $query->whereRaw('1=0');
                }

                $tokens = Tokens::prefixes(
                    $normalized,
                    (int) config('encrypted-search.max_prefix_depth', 6),
                    $pepper,
                    $minLength
                );

                if (empty($tokens)) {
                    return $query->whereRaw('1=0');
                }

                // AND logic: intersect model IDs for each field-term pair
                if ($useElastic) {
                    $modelIds = $this->searchElasticsearch($field, $tokens, 'prefix');
                    $query->whereIn($this->getQualifiedKeyName(), $modelIds);
                } else {
                    $query->whereIn($this->getQualifiedKeyName(), function ($sub) use ($field, $tokens) {
                        $sub->select('model_id')
                            ->from('encrypted_search_index')
                            ->where('model_type', static::class)
                            ->where('field', $field)
                            ->where('type', 'prefix')
                            ->whereIn('token', $tokens);
                    });
                }
            } else {
                // Exact match
                $token = Tokens::exact($normalized, $pepper);

                // AND logic: intersect model IDs for each field-term pair
                if ($useElastic) {
                    $modelIds = $this->searchElasticsearch($field, $token, 'exact');
                    $query->whereIn($this->getQualifiedKeyName(), $modelIds);
                } else {
                    $query->whereIn($this->getQualifiedKeyName(), function ($sub) use ($field, $token) {
                        $sub->select('model_id')
                            ->from('encrypted_search_index')
                            ->where('model_type', static::class)
                            ->where('field', $field)
                            ->where('type', 'exact')
                            ->where('token', $token);
                    });
                }
            }
        }

        return $query;
    }

    /**
     * Check if a field has an encrypted cast.
     *
     * @param  string  $field
     * @return bool
     */
    protected function hasEncryptedCast(string $field): bool
    {
        $casts = $this->getCasts();

        if (!isset($casts[$field])) {
            return false;
        }

        return str_contains(strtolower($casts[$field]), 'encrypted');
    }

    /**
     * Search for model IDs in Elasticsearch based on token(s).
     *
     * @param  string  $field
     * @param  string|array<int, string>  $tokens  Single token or array of tokens
     * @param  string  $type  Either 'exact' or 'prefix'
     * @return array<int, mixed>  Array of model IDs
     */
    protected function searchElasticsearch(string $field, $tokens, string $type): array
    {
        $index = config('encrypted-search.elasticsearch.index', 'encrypted_search');
        $service = app(ElasticsearchService::class);

        // Normalize tokens to array
        $tokenArray = is_array($tokens) ? $tokens : [$tokens];

        // Build Elasticsearch query
        $query = [
            'query' => [
                'bool' => [
                    'must' => [
                        ['term' => ['model_type.keyword' => static::class]],
                        ['term' => ['field.keyword' => $field]],
                        ['term' => ['type.keyword' => $type]],
                        ['terms' => ['token.keyword' => $tokenArray]],
                    ],
                ],
            ],
            '_source' => ['model_id'],
            'size' => 10000,
        ];

        try {
            $results = $service->search($index, $query);

            // Extract unique model IDs from results
            return collect($results)
                ->pluck('_source.model_id')
                ->unique()
                ->values()
                ->toArray();
        } catch (\Throwable $e) {
            logger()->warning('[EncryptedSearch] Elasticsearch search failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Search for model IDs in Elasticsearch based on token(s) across multiple fields.
     *
     * @param  array<int, string>  $fields
     * @param  string|array<int, string>  $tokens  Single token or array of tokens
     * @param  string  $type  Either 'exact' or 'prefix'
     * @return array<int, mixed>  Array of model IDs
     */
    protected function searchElasticsearchMulti(array $fields, $tokens, string $type): array
    {
        $index = config('encrypted-search.elasticsearch.index', 'encrypted_search');
        $service = app(ElasticsearchService::class);

        // Normalize tokens to array
        $tokenArray = is_array($tokens) ? $tokens : [$tokens];

        // Build Elasticsearch query with multiple fields (OR logic)
        $query = [
            'query' => [
                'bool' => [
                    'must' => [
                        ['term' => ['model_type.keyword' => static::class]],
                        ['terms' => ['field.keyword' => $fields]],
                        ['term' => ['type.keyword' => $type]],
                        ['terms' => ['token.keyword' => $tokenArray]],
                    ],
                ],
            ],
            '_source' => ['model_id'],
            'size' => 10000,
        ];

        try {
            $results = $service->search($index, $query);

            // Extract unique model IDs from results
            return collect($results)
                ->pluck('_source.model_id')
                ->unique()
                ->values()
                ->toArray();
        } catch (\Throwable $e) {
            logger()->warning('[EncryptedSearch] Elasticsearch multi-field search failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Resolve the encrypted search configuration for this model.
     *
     * The configuration may be determined from:
     * - auto-detected encrypted casts (if enabled),
     * - PHP attributes (#[EncryptedSearch]),
     * - the `$encryptedSearch` property on the model.
     *
     * Priority:
     * 1. $encryptedSearch (explicit overrides)
     * 2. #[EncryptedSearch] attributes
     * 3. auto-detected encrypted casts
     *
     * @return array<string, array<string, bool>>
     */
    protected function getEncryptedSearchConfiguration(): array
    {
        $config = [];

        // Auto-detect encrypted casts (if enabled)
        if (config('encrypted-search.auto_index_encrypted_casts', true)) {
            foreach ($this->getCasts() as $field => $cast) {
                if (str_contains(strtolower($cast), 'encrypted')) {
                    $config[$field] = ['exact' => true, 'prefix' => false];
                }
            }
        }

        // Detect #[EncryptedSearch] attributes
        $reflection = new \ReflectionClass($this);
        foreach ($reflection->getProperties() as $property) {
            foreach ($property->getAttributes(\Ginkelsoft\EncryptedSearch\Attributes\EncryptedSearch::class) as $attr) {
                $config[$property->getName()] = $attr->newInstance()->toArray();
            }
        }

        // Merge with explicit $encryptedSearch property (highest priority)
        if (property_exists($this, 'encryptedSearch')) {
            $config = array_merge($config, $this->encryptedSearch);
        }

        return $config;
    }
}
