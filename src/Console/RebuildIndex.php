<?php

namespace Ginkelsoft\EncryptedSearch\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
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
        $encrypted = 0;

        $query->chunk($chunk, function ($models) use (&$count, &$encrypted) {
            foreach ($models as $model) {
                // Check if model has encrypted casts and ensure data is encrypted
                if (method_exists($model, 'getCasts')) {
                    $casts = $model->getCasts();
                    $needsSave = false;

                    foreach ($casts as $field => $cast) {
                        if (str_contains(strtolower($cast), 'encrypted')) {
                            // Get the raw value from database (bypassing accessors)
                            $attributes = $model->getAttributes();
                            $rawValue = $attributes[$field] ?? null;

                            // Check if value exists and is not already encrypted
                            $isEncrypted = false;
                            if ($rawValue) {
                                try {
                                    Crypt::decryptString($rawValue);
                                    $isEncrypted = true;
                                } catch (\Throwable) {
                                    $isEncrypted = false;
                                }
                            }

                            if ($rawValue && !$isEncrypted) {
                                // Value is not encrypted, encrypt it now
                                $decrypted = $rawValue; // Value is already decrypted in DB
                                $model->setAttribute($field, $decrypted); // This will encrypt via cast
                                $needsSave = true;
                                $encrypted++;
                            }
                        }
                    }

                    // Save if any fields were re-encrypted
                    if ($needsSave) {
                        $model->save();
                    }
                }

                // Update search index
                if (method_exists($model, 'updateSearchIndex')) {
                    $model->updateSearchIndex();
                }
                $count++;
            }

            // Write a dot to indicate progress
            $this->output->write('.');
        });

        $this->newLine();
        $this->info("Rebuilt index for {$count} records of {$class}.");

        if ($encrypted > 0) {
            $this->info("Encrypted {$encrypted} unencrypted field(s) during rebuild.");
        }

        return self::SUCCESS;
    }
}
