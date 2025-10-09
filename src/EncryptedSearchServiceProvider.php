<?php

namespace Ginkelsoft\EncryptedSearch;

use Illuminate\Support\ServiceProvider;

class EncryptedSearchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/encrypted-search.php', 'encrypted-search');
    }

    public function boot(): void
    {
        // Publish config & migration
        $this->publishes([
            __DIR__.'/../config/encrypted-search.php' => config_path('encrypted-search.php'),
        ], 'config');

        $timestamp = date('Y_m_d_His');
        $this->publishes([
            __DIR__."/../database/migrations/create_encrypted_search_index_table.php"
            => database_path("migrations/{$timestamp}_create_encrypted_search_index_table.php"),
        ], 'migrations');

        // Commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\RebuildIndex::class,
            ]);
        }
    }
}
