<?php

namespace Ginkelsoft\EncryptedSearch\Observers;

use Illuminate\Database\Eloquent\Model;
use Ginkelsoft\EncryptedSearch\Traits\HasEncryptedSearchIndex;

/**
 * Class SearchIndexObserver
 *
 * A global Eloquent event listener that automatically maintains
 * encrypted search indexes for models using the
 * {@see HasEncryptedSearchIndex} trait.
 *
 * This observer listens to all Eloquent model events via the wildcard pattern:
 *
 *  Event::listen('eloquent.*: *', SearchIndexObserver::class);
 *
 * It reacts to model lifecycle events such as created, updated, saved,
 * touched, restored, deleted, and forceDeleted.
 *
 * When a model using the trait is created, updated, or touched, the
 * observer rebuilds its associated search tokens. When a model is
 * deleted or force-deleted, the corresponding index entries are removed.
 *
 * @package Ginkelsoft\EncryptedSearch\Observers
 */
class SearchIndexObserver
{
    /**
     * Handles all Eloquent events emitted through the wildcard listener.
     *
     * @param  string  $event    The Eloquent event name, e.g. "eloquent.saved: App\Models\Client".
     * @param  array   $payload  The event payload — typically contains the Model instance at index 0.
     * @return void
     */
    public function handle(string $event, array $payload): void
    {
        if (empty($payload[0]) || ! $payload[0] instanceof Model) {
            return;
        }

        /** @var Model $model */
        $model = $payload[0];

        // Only process models that use the HasEncryptedSearchIndex trait
        if (! $this->usesTrait($model, HasEncryptedSearchIndex::class)) {
            return;
        }

        $eventLower = strtolower($event);

        // Handle deletion events (deleted or forceDeleted)
        if (str_contains($eventLower, 'deleted')) {
            $this->removeIndex($model);
            return;
        }

        // Handle write and restore events that require index rebuilding
        if (
            str_contains($eventLower, 'saved')   ||
            str_contains($eventLower, 'updated') ||
            str_contains($eventLower, 'created') ||
            str_contains($eventLower, 'touched') ||
            str_contains($eventLower, 'restored')
        ) {
            $this->rebuildIndex($model);
        }
    }

    /**
     * Determines whether the given model uses a specific trait.
     *
     * @param  Model   $model      The model instance to inspect.
     * @param  string  $traitFqcn  The fully-qualified trait class name to check.
     * @return bool
     */
    protected function usesTrait(Model $model, string $traitFqcn): bool
    {
        $uses = class_uses_recursive($model);
        return in_array($traitFqcn, $uses, true);
    }

    /**
     * Rebuilds the search index for the given model.
     *
     * If the model defines the static method `updateSearchIndex`,
     * it will be called directly. This method is typically defined
     * in the {@see HasEncryptedSearchIndex} trait.
     *
     * @param  Model  $model  The model instance to reindex.
     * @return void
     */
    protected function rebuildIndex(Model $model): void
    {
        if (method_exists($model, 'updateSearchIndex')) {
            // @phpstan-ignore-next-line
            $model::updateSearchIndex($model);
        }
    }

    /**
     * Removes all index entries for the given model.
     *
     * If the model defines the static method `removeSearchIndex`,
     * it will be invoked to clear existing tokens associated with
     * the model’s primary key.
     *
     * @param  Model  $model  The model instance to remove from the index.
     * @return void
     */
    protected function removeIndex(Model $model): void
    {
        if (method_exists($model, 'removeSearchIndex')) {
            // @phpstan-ignore-next-line
            $model::removeSearchIndex($model);
        }
    }
}
