<?php

namespace Ginkelsoft\EncryptedSearch\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Orchestra\Testbench\TestCase;
use Ginkelsoft\EncryptedSearch\EncryptedSearchServiceProvider;
use Ginkelsoft\EncryptedSearch\Models\SearchIndex;
use Ginkelsoft\EncryptedSearch\Tests\Models\Client;

/**
 * Class EncryptedSearchIntegrationTest
 *
 * Integration tests for the Ginkelsoft Encrypted Search package.
 *
 * This suite verifies the full lifecycle of encrypted search index behavior:
 *  - Building index entries automatically upon model creation.
 *  - Executing exact and prefix-based search queries.
 *  - Rebuilding the search index via artisan command.
 *  - Updating index entries when model data changes.
 *  - Removing related index entries upon model deletion.
 *
 * Uses an in-memory SQLite database to ensure full isolation and fast execution.
 *
 * @package Ginkelsoft\EncryptedSearch\Tests\Feature
 * @covers  \Ginkelsoft\EncryptedSearch\Traits\HasEncryptedSearchIndex
 * @covers  \Ginkelsoft\EncryptedSearch\Console\RebuildIndex
 * @covers  \Ginkelsoft\EncryptedSearch\Models\SearchIndex
 */
class EncryptedSearchIntegrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Register the package service provider for Orchestra Testbench.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [EncryptedSearchServiceProvider::class];
    }

    /**
     * Set up the in-memory SQLite database schema before each test.
     *
     * Creates both the `clients` model table and the `encrypted_search_index`
     * table used to store search tokens.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Configure in-memory SQLite database
        config()->set('database.default', 'testing');
        config()->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        // Disable Elasticsearch during tests (we test DB index)
        config()->set('encrypted-search.elasticsearch.enabled', false);

        // Ensure Eloquent events are active (boot model & dispatcher)
        \Illuminate\Database\Eloquent\Model::unsetEventDispatcher();
        \Illuminate\Database\Eloquent\Model::setEventDispatcher(app('events'));

        // Boot model traits manually for Testbench
        \Ginkelsoft\EncryptedSearch\Tests\Models\Client::boot();

        // Create schema tables
        Schema::create('clients', function (Blueprint $table): void {
            $table->id();
            $table->string('first_names');
            $table->string('last_names');
            $table->timestamps();
        });

        Schema::create('encrypted_search_index', function (Blueprint $table): void {
            $table->id();
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->string('field');
            $table->string('type');
            $table->string('token');
            $table->timestamps();
            $table->index(['model_type', 'field', 'type', 'token'], 'esi_lookup');
        });
    }

    /**
     * Ensure that a new model automatically builds encrypted index entries
     * and that an exact search query retrieves the correct record.
     *
     * @return void
     */
    public function test_it_builds_search_index_and_can_query_exact_matches(): void
    {
        $client = Client::create([
            'first_names' => 'Wietse',
            'last_names'  => 'van Ginkel',
        ]);

        $this->assertGreaterThan(
            0,
            SearchIndex::count(),
            'Search index should contain entries after model creation.'
        );

        $results = Client::encryptedExact('last_names', 'van Ginkel')->get();

        $this->assertCount(1, $results);
        $this->assertEquals('Wietse', $results->first()->first_names);
    }

    /**
     * Verify that prefix-based searches match all records sharing
     * a given text prefix across the configured fields.
     *
     * Example: searching for “Wi” should return both “Wietse” and “Wilma”.
     *
     * @return void
     */
    public function test_it_can_query_by_prefix(): void
    {
        Client::create(['first_names' => 'Wietse', 'last_names' => 'van Ginkel']);
        Client::create(['first_names' => 'Wilma',  'last_names' => 'Jansen']);
        Client::create(['first_names' => 'Tom',    'last_names' => 'Bakker']);

        $results = Client::encryptedPrefix('first_names', 'Wi')->get();

        $this->assertCount(2, $results, 'Prefix search should match both Wietse and Wilma.');
        $this->assertEqualsCanonicalizing(['Wietse', 'Wilma'], $results->pluck('first_names')->toArray());
    }

    /**
     * Confirm that the index rebuild artisan command restores
     * all expected tokens after manual deletion.
     *
     * @return void
     */
    public function test_rebuild_index_command_recreates_index(): void
    {
        Client::create(['first_names' => 'Mark', 'last_names' => 'Vermeer']);

        $initial = SearchIndex::count();
        $this->assertGreaterThan(0, $initial, 'Initial index should be built.');

        SearchIndex::truncate();
        $this->assertEquals(0, SearchIndex::count(), 'Index table should be empty after truncate.');

        $this->artisan('encryption:index-rebuild', [
            'model' => Client::class,
        ])->assertExitCode(0);

        $this->assertEquals(
            $initial,
            SearchIndex::count(),
            'Rebuilt index should match initial token count.'
        );
    }

    /**
     * Verify that updating a model’s encrypted fields regenerates
     * the corresponding index tokens.
     *
     * @return void
     */
    public function test_it_updates_index_when_data_changes(): void
    {
        $client = Client::create(['first_names' => 'John', 'last_names' => 'Doe']);
        $initialTokens = SearchIndex::where('model_id', $client->id)->pluck('token')->toArray();

        $client->update(['last_names' => 'Smith']);
        $updatedTokens = SearchIndex::where('model_id', $client->id)->pluck('token')->toArray();

        $this->assertNotEquals(
            $initialTokens,
            $updatedTokens,
            'Index tokens should change after updating an encrypted field.'
        );
    }

    /**
     * Ensure that deleting a model cascades removal of its
     * related encrypted search index entries.
     *
     * @return void
     */
    public function test_it_removes_index_entries_on_delete(): void
    {
        $client = Client::create(['first_names' => 'Jane', 'last_names' => 'Doe']);

        $this->assertGreaterThan(
            0,
            SearchIndex::where('model_id', $client->id)->count(),
            'Index should contain entries before deletion.'
        );

        $client->delete();

        $this->assertEquals(
            0,
            SearchIndex::where('model_id', $client->id)->count(),
            'Index entries should be removed after deleting the record.'
        );
    }
}
