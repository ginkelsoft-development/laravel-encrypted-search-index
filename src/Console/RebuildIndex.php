<?php

namespace Ginkelsoft\EncryptedSearch\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Ginkelsoft\EncryptedSearch\Models\SearchIndex;

/**
 * Class RebuildIndex
 *
 * Artisan command that rebuilds the encrypted search index for a given Eloquent model.
 * It now supports short model names (e.g. "Client") and automatically resolves them
 * under the `App\Models` namespace if not fully qualified.
 *
 * Example:
 *   php artisan encryption:index-rebuild Client
 *   php artisan encryption:index-rebuild "App\Models\Client"
 */
class RebuildIndex extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'encryption:index-rebuild
        {model : Model name or FQCN of the Eloquent model}
        {--chunk=100 : Number of records processed per batch}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Rebuild encrypted search index for a given model.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $input = trim($this->argument('model'));

        // Automatically resolve models under App\Models namespace if not fully qualified
        if (! class_exists($input)) {
            $guessed = "App\\Models\\{$input}";
            if (class_exists($guessed)) {
                $input = $guessed;
            }
        }

        /** @var class-string<Model> $class */
        $class = $input;

        if (! class_exists($class)) {
            $this->error("Model class not found: {$this->argument('model')}");
            return self::FAILURE;
        }

        $chunk = (int) $this->option('chunk');
        $this->info("Rebuilding encrypted search index for: {$class}");
        $this->line("Processing in chunks of {$chunk}...");

        // Remove all existing search tokens for this model
        SearchIndex::where('model_type', $class)->delete();

        /** @var \Illuminate\Database\Eloquent\Builder $query */
        $query = $class::query();

        $count = 0;

        $query->chunk($chunk, function ($models) use (&$count) {
            foreach ($models as $model) {
                if (method_exists($model, 'updateSearchIndex')) {
                    $model->updateSearchIndex(); // <-- FIXED
                }
                $count++;
            }

            // Write a dot to indicate progress
            $this->output->write('.');
        });

        $this->newLine();
        $this->info("✅ Rebuilt index for {$count} records of {$class}.");

        return self::SUCCESS;
    }
}
