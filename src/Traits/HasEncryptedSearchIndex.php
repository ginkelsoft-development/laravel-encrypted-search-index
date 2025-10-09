<?php

namespace Ginkelsoft\EncryptedSearch\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Ginkelsoft\EncryptedSearch\Models\SearchIndex;
use Ginkelsoft\EncryptedSearch\Support\Normalizer;
use Ginkelsoft\EncryptedSearch\Support\Tokens;

trait HasEncryptedSearchIndex
{
    /**
     * Geef op welke velden geïndexeerd worden.
     * Voorbeeld in model:
     * protected array $encryptedSearch = [
     *   'first_names' => ['exact' => true, 'prefix' => true],
     *   'last_names'  => ['exact' => true, 'prefix' => true],
     * ];
     */
    protected array $encryptedSearch = [];

    public static function bootHasEncryptedSearchIndex(): void
    {
        static::saved(function (Model $model) {
            static::updateSearchIndex($model);
        });

        static::deleted(function (Model $model) {
            static::removeSearchIndex($model);
        });
    }

    protected static function updateSearchIndex(Model $model): void
    {
        if (empty($model->encryptedSearch)) return;

        $pepper = (string) config('encrypted-search.search_pepper', '');
        $max    = (int) config('encrypted-search.max_prefix_depth', 6);

        // Verwijder oude tokens
        SearchIndex::where('model_type', get_class($model))
            ->where('model_id', $model->getKey())
            ->delete();

        // Voeg nieuwe tokens toe
        $rows = [];
        foreach ($model->encryptedSearch as $field => $modes) {
            $raw = (string) $model->getAttribute($field);
            if ($raw === '') continue;

            $norm = Normalizer::normalize($raw);
            if (!$norm) continue;

            if (!empty($modes['exact'])) {
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

            if (!empty($modes['prefix'])) {
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

    protected static function removeSearchIndex(Model $model): void
    {
        SearchIndex::where('model_type', get_class($model))
            ->where('model_id', $model->getKey())
            ->delete();
    }

    /**
     * Scope: exact search op één veld.
     */
    public function scopeEncryptedExact(Builder $query, string $field, string $term): Builder
    {
        $pepper = (string) config('encrypted-search.search_pepper', '');
        $norm   = Normalizer::normalize($term);
        if (!$norm) return $query->whereRaw('1=0');

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
     * Scope: prefix search op één veld.
     */
    public function scopeEncryptedPrefix(Builder $query, string $field, string $term): Builder
    {
        $pepper = (string) config('encrypted-search.search_pepper', '');
        $norm   = Normalizer::normalize($term);
        if (!$norm) return $query->whereRaw('1=0');

        // Alle prefix tokens tot lengte N genereren en matchen
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
