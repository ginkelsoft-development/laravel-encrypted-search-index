<?php

namespace Ginkelsoft\EncryptedSearch;

use Illuminate\Support\ServiceProvider;

/**
 * Class EncryptedSearchServiceProvider
 *
 * Registers and bootstraps the Laravel Encrypted Search Index package.
 *
 * This service provider integrates the package into a Laravel application by:
 *  - Registering configuration (`config/encrypted-search.php`)
 *  - Publishing database migrations for the `encrypted_search_index` table
 *  - Registering console commands (e.g., index rebuilding)
 *
 * The provider ensures that the encrypted search system is fully
 * self-contained and can be seamlessly installed into any Laravel project.
 *
 * Typical installation:
 * ```bash
 * composer require ginkelsoft/laravel-encrypted-search-index
 * php artisan vendor:publish --tag=config
 * php artisan vendor:publish --tag=migrations
 * php artisan migrate
 * ```
 *
 * After registration, Eloquent models can use the
 * `HasEncryptedSearchIndex` trait to automatically build searchable,
 * privacy-preserving index entries.
 *
 * @see \Ginkelsoft\EncryptedSearch\Traits\HasEncryptedSearchIndex
 * @see \Ginkelsoft\EncryptedSearch\Console\RebuildIndex
 */
class EncryptedSearchServiceProvider extends ServiceProvider
{
    /**
     * Register bindings and configuration.
     *
     * This merges the package configuration into the application’s
     * global config namespace under the key `encrypted-search`.
     *
     * @return void
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/encrypted-search.php',
            'encrypted-search'
        );
    }

    /**
     * Bootstrap package resources (config, migrations, and commands).
     *
     * This method is executed after all other service providers have
     * been registered and is responsible for publishing configuration,
     * registering migrations, and exposing console commands.
     *
     * @return void
     */
    public function boot(): void
    {
        // Publish configuration file
        $this->publishes([
            __DIR__ . '/../config/encrypted-search.php' => config_path('encrypted-search.php'),
        ], 'config');

        // Publish migration with timestamped filename
        $timestamp = date('Y_m_d_His');
        $this->publishes([
            __DIR__ . '/../database/migrations/create_encrypted_search_index_table.php'
            => database_path("migrations/{$timestamp}_create_encrypted_search_index_table.php"),
        ], 'migrations');

        // Register CLI commands only in console context
        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\RebuildIndex::class,
            ]);
        }
    }
}
