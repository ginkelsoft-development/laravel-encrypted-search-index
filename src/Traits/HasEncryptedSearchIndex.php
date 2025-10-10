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
 * Adds encrypted search indexing capabilities to Eloquent models.
 *
 * When applied, the model automatically generates deterministic, non-reversible
 * search tokens for configured fields and stores them in the
 * `encrypted_search_index` table. These tokens support privacy-preserving
 * search queries while keeping plaintext data out of the database.
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
        // Rebuild index on save-related events
        foreach (['created', 'updated', 'saved'] as $event) {
            static::$event(function (Model $model) {
                $model->updateSearchIndex();
            });
        }

        // Remove index entries when deleted
        static::deleted(fn(Model $m) => $m->removeSearchIndex());

        // Register SoftDelete-specific hooks only if supported
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

        // Remove existing entries for this model
        SearchIndex::where('model_type', static::class)
            ->where('model_id', $this->getKey())
            ->delete();

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

            // Exact matches
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

            // Prefix matches
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

        if (! empty($rows)) {
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
        SearchIndex::where('model_type', static::class)
            ->where('model_id', $this->getKey())
            ->delete();
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
