<?php

namespace Ginkelsoft\EncryptedSearch;

use Illuminate\Support\ServiceProvider;

/**
 * Class EncryptedSearchServiceProvider
 *
 * Registers and bootstraps the Laravel Encrypted Search Index package.
 *
 * Responsibilities:
 * - Merge and publish configuration.
 * - Publish database migration for the `encrypted_search_index` table.
 * - Register console commands for index rebuilding.
 * - Attach a global observer that synchronizes encrypted search indexes
 *   with Eloquent model lifecycle events.
 *
 * Typical installation:
 *
 * composer require ginkelsoft/laravel-encrypted-search-index
 * php artisan vendor:publish --tag=config
 * php artisan vendor:publish --tag=migrations
 * php artisan migrate
 *
 * After installation, any model using the
 * {@see \Ginkelsoft\EncryptedSearch\Traits\HasEncryptedSearchIndex}
 * trait will automatically maintain a privacy-preserving search index.
 */
class EncryptedSearchServiceProvider extends ServiceProvider
{
    /**
     * Register bindings and configuration.
     *
     * Merges the package configuration into the application's config repository.
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
     * Bootstrap the package resources, commands, and event listeners.
     *
     * @return void
     */
    public function boot(): void
    {
        // Validate configuration
        $this->validateConfiguration();

        // Publish configuration
        $this->publishes([
            __DIR__ . '/../config/encrypted-search.php' => config_path('encrypted-search.php'),
        ], 'config');

        // Publish migration file
        $timestamp = date('Y_m_d_His');
        $this->publishes([
            __DIR__ . '/../database/migrations/create_encrypted_search_index_table.php'
            => database_path("migrations/{$timestamp}_create_encrypted_search_index_table.php"),
        ], 'migrations');

        // Register Artisan commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\RebuildIndex::class,
            ]);
        }

    }

    /**
     * Validate package configuration at boot time.
     *
     * @return void
     *
     * @throws \InvalidArgumentException if configuration is invalid
     */
    protected function validateConfiguration(): void
    {
        // Validate Elasticsearch configuration if enabled
        if (config('encrypted-search.elasticsearch.enabled', false)) {
            $host = config('encrypted-search.elasticsearch.host');

            if (empty($host)) {
                throw new \InvalidArgumentException(
                    'Elasticsearch is enabled but ELASTICSEARCH_HOST is not configured. ' .
                    'Set it in your .env file or disable Elasticsearch mode.'
                );
            }

            $index = config('encrypted-search.elasticsearch.index');

            if (empty($index)) {
                throw new \InvalidArgumentException(
                    'Elasticsearch is enabled but ELASTICSEARCH_INDEX is not configured. ' .
                    'Set it in your .env file.'
                );
            }
        }
    }
}
