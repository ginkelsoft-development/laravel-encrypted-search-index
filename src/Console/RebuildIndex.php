<?php

namespace Ginkelsoft\EncryptedSearch\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Ginkelsoft\EncryptedSearch\Models\SearchIndex;

/**
 * Class RebuildIndex
 *
 * This Artisan command rebuilds the encrypted search index for a given Eloquent model.
 * It is designed for maintenance operations where the search index may be outdated,
 * corrupted, or needs regeneration after schema or normalization changes.
 *
 * The command iterates over all records of the specified model and regenerates
 * both "exact" and "prefix" tokens as defined by the model’s
 * `HasEncryptedSearchIndex` trait configuration.
 *
 * Usage example:
 *   php artisan encryption:index-rebuild "App\Models\Client"
 *
 * Options:
 *   --chunk=100   Number of records processed per batch (default: 100)
 *
 * Implementation details:
 * - Before rebuilding, all existing search index entries for the given model
 *   are removed.
 * - Records are then reprocessed in chunks to prevent memory exhaustion.
 * - For each model instance, `updateSearchIndex()` is called to regenerate
 *   the normalized token rows.
 *
 * This ensures a clean and consistent index aligned with the current model data.
 */
class RebuildIndex extends Command
{
    /**
     * The name and signature of the console command.
     *
     * {model}  The fully qualified class name (FQCN) of the Eloquent model.
     * {--chunk=100}  The number of model records to process per batch.
     *
     * @var string
     */
    protected $signature = 'encryption:index-rebuild {model : FQCN of the Eloquent model} {--chunk=100}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Rebuild encrypted search index for a given model.';

    /**
     * Execute the console command.
     *
     * This method performs the following steps:
     *  1. Validates the provided model class.
     *  2. Deletes all existing search index entries for that model.
     *  3. Iterates through all model records in configurable chunks.
     *  4. Calls `updateSearchIndex()` for each record to regenerate tokens.
     *  5. Displays progress and a final summary of processed records.
     *
     * @return int Command exit code (0 on success, 1 on failure).
     */
    public function handle(): int
    {
        /** @var class-string<Model> $class */
        $class = $this->argument('model');

        if (! class_exists($class)) {
            $this->error("Model class not found: {$class}");
            return self::FAILURE;
        }

        $chunk = (int) $this->option('chunk');

        // Remove all existing search tokens for this model
        SearchIndex::where('model_type', $class)->delete();

        /** @var \Illuminate\Database\Eloquent\Builder $q */
        $q = $class::query();

        $count = 0;

        // Process model data in chunks to minimize memory usage
        $q->chunk($chunk, function ($rows) use (&$count, $class) {
            foreach ($rows as $model) {
                if (method_exists($class, 'updateSearchIndex')) {
                    $class::updateSearchIndex($model);
                }
                $count++;
            }
            // Write a dot to indicate progress
            $this->output->write('.');
        });

        $this->newLine();
        $this->info("Rebuilt index for {$count} records of {$class}.");

        return self::SUCCESS;
    }
}
