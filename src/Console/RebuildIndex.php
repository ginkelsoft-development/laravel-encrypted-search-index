<?php

namespace Ginkelsoft\EncryptedSearch\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Ginkelsoft\EncryptedSearch\Models\SearchIndex;

class RebuildIndex extends Command
{
    protected $signature = 'encryption:index-rebuild {model : FQCN of the Eloquent model} {--chunk=100}';
    protected $description = 'Rebuild encrypted search index for a given model.';

    public function handle(): int
    {
        /** @var class-string<Model> $class */
        $class = $this->argument('model');

        if (! class_exists($class)) {
            $this->error("Model class not found: {$class}");
            return self::FAILURE;
        }

        $chunk = (int) $this->option('chunk');

        // Drop alle tokens voor dit model
        SearchIndex::where('model_type', $class)->delete();

        /** @var \Illuminate\Database\Eloquent\Builder $q */
        $q = $class::query();

        $count = 0;
        $q->chunk($chunk, function ($rows) use (&$count, $class) {
            foreach ($rows as $model) {
                if (method_exists($class, 'updateSearchIndex')) {
                    $class::updateSearchIndex($model);
                }
                $count++;
            }
            $this->output->write('.');
        });

        $this->newLine();
        $this->info("Rebuilt index for {$count} records of {$class}.");

        return self::SUCCESS;
    }
}
