<?php

namespace Ginkelsoft\EncryptedSearch\Observers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Ginkelsoft\EncryptedSearch\Traits\HasEncryptedSearchIndex;

/**
 * Class SearchIndexObserver
 *
 * Synchronizes the encrypted search index with Eloquent model lifecycle events.
 *
 * This observer listens to all model-level Eloquent events and ensures that
 * the search index remains accurate after any create, update, delete,
 * or restore operation.
 *
 * - Only acts on models that use the {@see HasEncryptedSearchIndex} trait.
 * - Handles soft-delete events ("restored", "forceDeleted") safely.
 * - Prevents Laravel from attempting to call non-existent methods on models
 *   that do not use {@see SoftDeletes}.
 *
 * Typical use:
 * The service provider registers this observer globally:
 *
 * Event::listen('eloquent.*: *', SearchIndexObserver::class);
 *
 * The observer then determines at runtime whether the model supports each event
 * before performing index updates.
 */
class SearchIndexObserver
{
    /**
     * Handle an incoming Eloquent event for models using encrypted search.
     *
     * @param  string  $event    The full event name, e.g. "eloquent.saved: App\Models\Client".
     * @param  array<int, mixed>  $payload  The event payload, typically [Model $model].
     * @return void
     */
    public function handle(string $event, array $payload): void
    {
        if (empty($payload[0]) || ! $payload[0] instanceof Model) {
            return;
        }

        /** @var Model $model */
        $model = $payload[0];

        // Only handle models that use the encrypted search trait
        if (! $this->usesTrait($model, HasEncryptedSearchIndex::class)) {
            return;
        }

        $eventLower = strtolower($event);
        $usesSoftDeletes = $this->usesTrait($model, SoftDeletes::class);

        /**
         * Safety guard:
         * Some Laravel versions dispatch "forceDeleted" or "restored" events
         * even for models that do not use SoftDeletes.
         * Skip these events to prevent BadMethodCallException errors.
         */
        if (
            ! $usesSoftDeletes &&
            (str_contains($eventLower, 'forcedeleted') || str_contains($eventLower, 'restored'))
        ) {
            return;
        }

        // Remove index entries on delete or force delete
        if (str_contains($eventLower, 'forcedeleted')) {
            $this->safeRemoveIndex($model);
            return;
        }

        if (str_contains($eventLower, 'deleted')) {
            $this->safeRemoveIndex($model);
            return;
        }

        // Rebuild index on save, update, create, touch, or restore
        if (
            str_contains($eventLower, 'saved') ||
            str_contains($eventLower, 'updated') ||
            str_contains($eventLower, 'created') ||
            str_contains($eventLower, 'touched') ||
            str_contains($eventLower, 'restored')
        ) {
            $this->safeRebuildIndex($model);
        }
    }

    /**
     * Determine whether a given model uses a specific trait, recursively.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $traitFqcn
     * @return bool
     */
    protected function usesTrait(Model $model, string $traitFqcn): bool
    {
        return in_array($traitFqcn, class_uses_recursive($model), true);
    }

    /**
     * Rebuild the search index for the given model instance, ignoring exceptions.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return void
     */
    protected function safeRebuildIndex(Model $model): void
    {
        try {
            if (method_exists($model, 'updateSearchIndex')) {
                $model->updateSearchIndex();
            }
        } catch (\Throwable $e) {
            logger()->warning('[EncryptedSearch] Failed to rebuild index for ' . get_class($model) . ': ' . $e->getMessage());
        }
    }

    /**
     * Remove search index entries for the given model instance, ignoring exceptions.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return void
     */
    protected function safeRemoveIndex(Model $model): void
    {
        try {
            if (method_exists($model, 'removeSearchIndex')) {
                $model->removeSearchIndex();
            }
        } catch (\Throwable $e) {
            logger()->warning('[EncryptedSearch] Failed to remove index for ' . get_class($model) . ': ' . $e->getMessage());
        }
    }
}
