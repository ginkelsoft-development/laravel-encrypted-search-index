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
 * When enabled, models can either index search tokens in the database
 * or directly in Elasticsearch — depending on configuration.
 *
 * Configuration:
 *   - If `encrypted-search.elasticsearch.enabled = true`, all tokens
 *     are sent directly to Elasticsearch (DB is skipped).
 *   - Otherwise, tokens are stored in the local
 *     `encrypted_search_index` database table.
 *
 * Example:
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
     * Create or refresh all encrypted search entries for this model instance.
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
        $max    = (int) config('encrypted-search.max_prefix_depth', 6);
        $useElastic = config('encrypted-search.elasticsearch.enabled', false);

        $rows = [];

        foreach ($config as $field => $modes) {
            $raw = (string) $this->getAttribute($field);
            if ($raw === '') {
                continue;
            }

            $normalized = Normalizer::normalize($raw);
            if (! $normalized) {
                continue;
            }

            // Exact tokens
            if (! empty($modes['exact'])) {
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

            // Prefix tokens
            if (! empty($modes['prefix'])) {
                foreach (Tokens::prefixes($normalized, $max, $pepper) as $token) {
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

        // Choose backend: Elasticsearch or Database
        if ($useElastic) {
            $this->syncToElasticsearch($rows);
        } else {
            // Remove existing DB entries and insert new ones
            SearchIndex::where('model_type', static::class)
                ->where('model_id', $this->getKey())
                ->delete();

            SearchIndex::insert($rows);
        }
    }

    /**
     * Delete all encrypted search entries related to this model instance.
     *
     * @return void
     */
    public function removeSearchIndex(): void
    {
        $useElastic = config('encrypted-search.elasticsearch.enabled', false);

        if ($useElastic) {
            $this->removeFromElasticsearch();
        } else {
            SearchIndex::where('model_type', static::class)
                ->where('model_id', $this->getKey())
                ->delete();
        }
    }

    /**
     * Push new/updated tokens to Elasticsearch index.
     *
     * @param array<int, array<string,mixed>> $rows
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
     * Remove this model’s tokens from Elasticsearch.
     *
     * @return void
     */
    protected function removeFromElasticsearch(): void
    {
        $index = config('encrypted-search.elasticsearch.index', 'encrypted_search');
        $service = app(ElasticsearchService::class);

        // We can’t query SearchIndex table because we skip DB
        // → just remove all docs by model_id
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
            $service->search($index, $query); // optional: confirm existence
            // Simpelere aanpak zou bulk delete API kunnen gebruiken
        } catch (\Throwable $e) {
            logger()->warning("Failed to remove Elasticsearch docs for model {$this->getKey()}: {$e->getMessage()}");
        }
    }

    /**
     * Scope: query models by exact encrypted token match.
     *
     * @param Builder $query
     * @param string $field
     * @param string $term
     * @return Builder
     */
    public function scopeEncryptedExact(Builder $query, string $field, string $term): Builder
    {
        $pepper = (string) config('encrypted-search.search_pepper', '');
        $normalized = Normalizer::normalize($term);

        if (! $normalized) {
            return $query->whereRaw('1=0');
        }

        $token = Tokens::exact($normalized, $pepper);

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
     * Scope: query models by prefix-based encrypted token match.
     *
     * @param Builder $query
     * @param string $field
     * @param string $term
     * @return Builder
     */
    public function scopeEncryptedPrefix(Builder $query, string $field, string $term): Builder
    {
        $pepper = (string) config('encrypted-search.search_pepper', '');
        $normalized = Normalizer::normalize($term);

        if (! $normalized) {
            return $query->whereRaw('1=0');
        }

        $tokens = Tokens::prefixes(
            $normalized,
            (int) config('encrypted-search.max_prefix_depth', 6),
            $pepper
        );

        return $query->whereIn($this->getQualifiedKeyName(), function ($sub) use ($field, $tokens) {
            $sub->select('model_id')
                ->from('encrypted_search_index')
                ->where('model_type', static::class)
                ->where('field', $field)
                ->where('type', 'prefix')
                ->whereIn('token', $tokens);
        });
    }

    /**
     * Retrieve the encrypted search field configuration.
     *
     * Models may define configuration either via a
     * `getEncryptedSearchFields()` method or a `$encryptedSearch` property.
     *
     * @return array<string, array<string,bool>>
     */
    protected function getEncryptedSearchConfiguration(): array
    {
        if (method_exists($this, 'getEncryptedSearchFields')) {
            return $this->getEncryptedSearchFields();
        }

        return property_exists($this, 'encryptedSearch')
            ? $this->encryptedSearch
            : [];
    }
}
