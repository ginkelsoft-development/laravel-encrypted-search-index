<?php

namespace Ginkelsoft\EncryptedSearch\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Ginkelsoft\EncryptedSearch\Models\SearchIndex;
use Ginkelsoft\EncryptedSearch\Support\Normalizer;
use Ginkelsoft\EncryptedSearch\Support\Tokens;

/**
 * Trait HasEncryptedSearchIndex
 *
 * Provides automatic encrypted search indexing for Eloquent models.
 *
 * When attached to a model, this trait builds and maintains a companion index
 * table (`encrypted_search_index`) that stores deterministic, non-reversible
 * search tokens derived from model attributes.
 *
 * These tokens allow for efficient and privacy-preserving search queries
 * (exact or prefix-based) without exposing any plaintext values in the database.
 *
 * Example usage:
 * ```php
 * class Client extends Model {
 *     use HasEncryptedSearchIndex;
 *
 *     protected array $encryptedSearch = [
 *         'first_names' => ['exact' => true, 'prefix' => true],
 *         'last_names'  => ['exact' => true, 'prefix' => true],
 *     ];
 * }
 * ```
 *
 * On every save/delete:
 * - All configured fields are normalized and hashed into tokens.
 * - Tokens are stored in the `encrypted_search_index` table.
 * - Old entries for that record are replaced automatically.
 *
 * You can then perform secure search queries using:
 * ```php
 * Client::encryptedExact('last_names', 'vermeer')->get();
 * Client::encryptedPrefix('first_names', 'wie')->get();
 * ```
 *
 * @see \Ginkelsoft\EncryptedSearch\Models\SearchIndex
 */
trait HasEncryptedSearchIndex
{
    /**
     * Defines which fields should be included in the encrypted search index.
     *
     * Example:
     * ```php
     * protected array $encryptedSearch = [
     *     'first_names' => ['exact' => true, 'prefix' => true],
     *     'last_names'  => ['exact' => true],
     * ];
     * ```
     *
     * Each entry specifies whether an exact or prefix index (or both)
     * should be generated for that field.
     *
     * @var array<string, array{exact?: bool, prefix?: bool}>
     */
    protected array $encryptedSearch = [];

    /**
     * Boot logic for the trait.
     *
     * Automatically updates or removes encrypted search tokens whenever
     * a model instance is saved or deleted.
     *
     * @return void
     */
    public static function bootHasEncryptedSearchIndex(): void
    {
        // Rebuild index when model is created, updated or saved.
        foreach (['created', 'updated', 'saved'] as $event) {
            static::$event(function (Model $model) {
                static::updateSearchIndex($model);
            });
        }

        // Remove tokens when model is deleted or force-deleted
        static::deleted(fn(Model $m) => static::removeSearchIndex($m));
        static::forceDeleted(fn(Model $m) => static::removeSearchIndex($m));

        // Optional: if SoftDeletes is used, re-index on restore
        if (method_exists(static::class, 'restored')) {
            static::restored(fn(Model $m) => static::updateSearchIndex($m));
        }
    }

    /**
     * Create or refresh all search index entries for a given model instance.
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     * @return void
     */
    protected static function updateSearchIndex(Model $model): void
    {
        if (empty($model->encryptedSearch)) {
            return;
        }

        $pepper = (string) config('encrypted-search.search_pepper', '');
        $max    = (int) config('encrypted-search.max_prefix_depth', 6);

        // Remove previous entries for this model record
        SearchIndex::where('model_type', get_class($model))
            ->where('model_id', $model->getKey())
            ->delete();

        // Generate new tokens
        $rows = [];
        foreach ($model->encryptedSearch as $field => $modes) {
            $raw = (string) $model->getAttribute($field);
            if ($raw === '') {
                continue;
            }

            $norm = Normalizer::normalize($raw);
            if (! $norm) {
                continue;
            }

            if (! empty($modes['exact'])) {
                $rows[] = [
                    'model_type' => get_class($model),
                    'model_id'   => $model->getKey(),
                    'field'      => $field,
                    'type'       => 'exact',
                    'token'      => Tokens::exact($norm, $pepper),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if (! empty($modes['prefix'])) {
                foreach (Tokens::prefixes($norm, $max, $pepper) as $t) {
                    $rows[] = [
                        'model_type' => get_class($model),
                        'model_id'   => $model->getKey(),
                        'field'      => $field,
                        'type'       => 'prefix',
                        'token'      => $t,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }
        }

        if ($rows) {
            SearchIndex::insert($rows);
        }
    }

    /**
     * Remove all index entries for a deleted model instance.
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     * @return void
     */
    protected static function removeSearchIndex(Model $model): void
    {
        SearchIndex::where('model_type', get_class($model))
            ->where('model_id', $model->getKey())
            ->delete();
    }

    /**
     * Query scope: find models by exact match on an indexed field.
     *
     * Example:
     * ```php
     * Client::encryptedExact('last_names', 'vermeer')->get();
     * ```
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $field  The name of the field to search.
     * @param string $term   The plaintext term to look for.
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeEncryptedExact(Builder $query, string $field, string $term): Builder
    {
        $pepper = (string) config('encrypted-search.search_pepper', '');
        $norm   = Normalizer::normalize($term);
        if (! $norm) {
            return $query->whereRaw('1=0');
        }

        $token = Tokens::exact($norm, $pepper);

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
     * Query scope: find models by prefix match on an indexed field.
     *
     * Example:
     * ```php
     * Client::encryptedPrefix('first_names', 'wi')->get();
     * ```
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $field  The field name to search.
     * @param string $term   The search term (prefix).
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeEncryptedPrefix(Builder $query, string $field, string $term): Builder
    {
        $pepper = (string) config('encrypted-search.search_pepper', '');
        $norm   = Normalizer::normalize($term);
        if (! $norm) {
            return $query->whereRaw('1=0');
        }

        // Generate all prefix tokens up to the configured max depth
        $tokens = Tokens::prefixes($norm, (int) config('encrypted-search.max_prefix_depth', 6), $pepper);

        return $query->whereIn($this->getQualifiedKeyName(), function ($sub) use ($field, $tokens) {
            $sub->select('model_id')
                ->from('encrypted_search_index')
                ->where('model_type', static::class)
                ->where('field', $field)
                ->where('type', 'prefix')
                ->whereIn('token', $tokens);
        });
    }
}
