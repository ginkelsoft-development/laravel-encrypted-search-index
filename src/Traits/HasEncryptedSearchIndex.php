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
 * Adds automatic encrypted search indexing to Eloquent models.
 *
 * When this trait is applied to a model, it maintains an associated
 * index table (`encrypted_search_index`) containing deterministic,
 * non-reversible tokens derived from selected model attributes.
 *
 * The generated tokens make it possible to perform privacy-preserving
 * searches (exact or prefix-based) without exposing plaintext data.
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
 * On every save or delete event:
 * - Tokens are created, updated, or removed in the `encrypted_search_index` table.
 * - Outdated tokens for the same record are automatically deleted.
 *
 * Example search:
 *   Client::encryptedExact('last_names', 'vermeer')->get();
 *   Client::encryptedPrefix('first_names', 'wie')->get();
 */
trait HasEncryptedSearchIndex
{
    /**
     * Boot logic for the trait.
     *
     * Automatically updates or removes encrypted search tokens whenever
     * a model instance is created, updated, saved, deleted, or restored.
     *
     * @return void
     */
    public static function bootHasEncryptedSearchIndex(): void
    {
        // Rebuild index when model is created, updated, or saved
        foreach (['created', 'updated', 'saved'] as $event) {
            static::$event(function (Model $model): void {
                $model->updateSearchIndex();
            });
        }

        // Always remove tokens when a model is deleted
        static::deleted(fn(Model $m): bool => $m->removeSearchIndex());

        // Register forceDeleted/restored only for models using SoftDeletes
        if (in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
            static::forceDeleted(fn(Model $m): bool => $m->removeSearchIndex());
            static::restored(fn(Model $m): bool => $m->updateSearchIndex());
        }
    }

    /**
     * Build or refresh all search index entries for this model instance.
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

        // Remove existing entries for this record
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

            // Exact match token
            if (! empty($modes['exact'])) {
                $rows[] = [
                    'model_type' => static::class,
                    'model_id' => $this->getKey(),
                    'field' => $field,
                    'type' => 'exact',
                    'token' => Tokens::exact($norm, $pepper),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            // Prefix tokens
            if (! empty($modes['prefix'])) {
                foreach (Tokens::prefixes($norm, $max, $pepper) as $t) {
                    $rows[] = [
                        'model_type' => static::class,
                        'model_id' => $this->getKey(),
                        'field' => $field,
                        'type' => 'prefix',
                        'token' => $t,
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
     * Scope: perform an exact encrypted search on a specific field.
     *
     * Example:
     *   Client::encryptedExact('last_names', 'vermeer')->get();
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $field  The name of the field to search
     * @param  string  $term   The plaintext search term
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeEncryptedExact(Builder $query, string $field, string $term): Builder
    {
        $pepper = (string) config('encrypted-search.search_pepper', '');
        $norm = Normalizer::normalize($term);

        if (! $norm) {
            return $query->whereRaw('1=0');
        }

        $token = Tokens::exact($norm, $pepper);

        return $query->whereIn($this->getQualifiedKeyName(), function ($sub) use ($field, $token): void {
            $sub->select('model_id')
                ->from('encrypted_search_index')
                ->where('model_type', static::class)
                ->where('field', $field)
                ->where('type', 'exact')
                ->where('token', $token);
        });
    }

    /**
     * Scope: perform a prefix-based encrypted search on a specific field.
     *
     * Example:
     *   Client::encryptedPrefix('first_names', 'wi')->get();
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $field  The field name to search
     * @param  string  $term   The prefix term to search for
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeEncryptedPrefix(Builder $query, string $field, string $term): Builder
    {
        $pepper = (string) config('encrypted-search.search_pepper', '');
        $norm = Normalizer::normalize($term);

        if (! $norm) {
            return $query->whereRaw('1=0');
        }

        $tokens = Tokens::prefixes($norm, (int) config('encrypted-search.max_prefix_depth', 6), $pepper);

        return $query->whereIn($this->getQualifiedKeyName(), function ($sub) use ($field, $tokens): void {
            $sub->select('model_id')
                ->from('encrypted_search_index')
                ->where('model_type', static::class)
                ->where('field', $field)
                ->where('type', 'prefix')
                ->whereIn('token', $tokens);
        });
    }

    /**
     * Get the encrypted search configuration for this model.
     *
     * Models can define searchable fields either through a
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
