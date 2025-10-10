<?php

namespace Ginkelsoft\EncryptedSearch\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
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
 * These tokens enable privacy-preserving search queries (exact or prefix-based)
 * without revealing plaintext values in the database.
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
 *
 * On each save or delete:
 * - Tokens are (re)generated and stored in `encrypted_search_index`.
 * - Old entries for the record are automatically replaced or removed.
 *
 * Search queries:
 *
 * Client::encryptedExact('last_names', 'vermeer')->get();
 * Client::encryptedPrefix('first_names', 'wie')->get();
 */
trait HasEncryptedSearchIndex
{
    /**
     * Boot logic for the trait.
     *
     * Automatically updates or removes encrypted search tokens whenever
     * a model instance is saved, updated, deleted, or restored.
     *
     * @return void
     */
    public static function bootHasEncryptedSearchIndex(): void
    {
        // Rebuild index when model is created, updated or saved
        foreach (['created', 'updated', 'saved'] as $event) {
            static::$event(function (Model $model) {
                $model->updateSearchIndex();
            });
        }

        // Always remove tokens when model is deleted
        static::deleted(fn(Model $m) => $m->removeSearchIndex());

        // Register forceDeleted/restored events only if the model uses SoftDeletes
        if (in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
            static::forceDeleted(fn(Model $m) => $m->removeSearchIndex());
            static::restored(fn(Model $m) => $m->updateSearchIndex());
        }
    }

    /**
     * Create or refresh all search index entries for this model instance.
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

        // Remove existing entries
        SearchIndex::where('model_type', static::class)
            ->where('model_id', $this->getKey())
            ->delete();

        $rows = [];

        foreach ($config as $field => $modes) {
            $raw = (string) $this->getAttribute($field);
            if ($raw === '') {
                continue;
            }

            $norm = Normalizer::normalize($raw);
            if (! $norm) {
                continue;
            }

            if (! empty($modes['exact'])) {
                $rows[] = [
                    'model_type' => static::class,
                    'model_id'   => $this->getKey(),
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
                        'model_type' => static::class,
                        'model_id'   => $this->getKey(),
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
     * Remove all index entries for this model instance.
     *
     * @return void
     */
    public function removeSearchIndex(): void
    {
        SearchIndex::where('model_type', static::class)
            ->where('model_id', $this->getKey())
            ->delete();
    }

    /**
     * Query scope: find models by exact match on an indexed field.
     *
     * Example:
     * Client::encryptedExact('last_names', 'vermeer')->get();
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $field
     * @param  string  $term
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
     * Client::encryptedPrefix('first_names', 'wi')->get();
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $field
     * @param  string  $term
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeEncryptedPrefix(Builder $query, string $field, string $term): Builder
    {
        $pepper = (string) config('encrypted-search.search_pepper', '');
        $norm   = Normalizer::normalize($term);

        if (! $norm) {
            return $query->whereRaw('1=0');
        }

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

    /**
     * Resolve the encrypted search configuration for this model.
     *
     * This allows models to define searchable fields either via a
     * `getEncryptedSearchFields()` method or a `$encryptedSearch` property.
     *
     * @return array<string, array<string,bool>>
     */
    protected function getEncryptedSearchConfiguration(): array
    {
        if (method_exists($this, 'getEncryptedSearchFields')) {
            return $this->getEncryptedSearchFields();
        }

        return property_exists($this, 'encryptedSearch') ? $this->encryptedSearch : [];
    }
}
