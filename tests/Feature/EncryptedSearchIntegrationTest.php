<?php

namespace Ginkelsoft\EncryptedSearch\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Orchestra\Testbench\TestCase;
use Ginkelsoft\EncryptedSearch\EncryptedSearchServiceProvider;
use Ginkelsoft\EncryptedSearch\Models\SearchIndex;
use Tests\Models\Client;

/**
 * Class EncryptedSearchIntegrationTest
 *
 * Integration test suite for the Ginkelsoft Encrypted Search package.
 *
 * These tests verify that the encrypted search index:
 *  - Automatically builds entries upon model creation.
 *  - Correctly supports exact and prefix search queries.
 *  - Rebuilds successfully using the console command.
 *  - Updates when model data changes.
 *  - Removes entries when a model is deleted.
 *
 * The test uses an in-memory SQLite database and a minimal
 * `Client` model configured with the `HasEncryptedSearchIndex` trait.
 *
 * @covers \Ginkelsoft\EncryptedSearch\Traits\HasEncryptedSearchIndex
 * @covers \Ginkelsoft\EncryptedSearch\Console\RebuildIndex
 * @covers \Ginkelsoft\EncryptedSearch\Models\SearchIndex
 */
class EncryptedSearchIntegrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Register the package service provider for the test environment.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app)
    {
        return [
            EncryptedSearchServiceProvider::class,
        ];
    }

    /**
     * Set up the test database schema and configuration.
     *
     * Uses an in-memory SQLite connection and creates both
     * the model table (`clients`) and the search index table.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'testing');
        config()->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

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
     * Verify that the search index is built correctly on save and
     * that exact match queries return the expected record.
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
            'Search index should contain entries after save().'
        );

        $results = Client::encryptedExact('last_names', 'van Ginkel')->get();

        $this->assertCount(1, $results);
        $this->assertEquals('Wietse', $results->first()->first_names);
    }

    /**
     * Verify that prefix search returns all matching records.
     *
     * Example: searching for "Wi" should match both
     * "Wietse" and "Wilma".
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
     * Verify that the index rebuild command recreates the same
     * number of entries as before, after truncating the index table.
     *
     * @return void
     */
    public function test_rebuild_index_command_recreates_index(): void
    {
        Client::create(['first_names' => 'Mark', 'last_names' => 'Vermeer']);

        $initial = SearchIndex::count();
        $this->assertGreaterThan(0, $initial, 'Initial index should be built.');

        SearchIndex::truncate();
        $this->assertEquals(0, SearchIndex::count());

        $this->artisan('encryption:index-rebuild', [
            'model' => Client::class,
        ])->assertExitCode(0);

        $this->assertEquals(
            $initial,
            SearchIndex::count(),
            'Rebuilt index should match initial count.'
        );
    }

    /**
     * Verify that changing a model field updates the associated
     * index tokens, ensuring that searches remain consistent.
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
            'Index tokens should change after updating the record.'
        );
    }

    /**
     * Verify that deleting a model also deletes its index entries
     * from the `encrypted_search_index` table.
     *
     * @return void
     */
    public function test_it_removes_index_entries_on_delete(): void
    {
        $client = Client::create(['first_names' => 'Jane', 'last_names' => 'Doe']);

        $this->assertGreaterThan(
            0,
            SearchIndex::where('model_id', $client->id)->count()
        );

        $client->delete();

        $this->assertEquals(
            0,
            SearchIndex::where('model_id', $client->id)->count(),
            'Index entries should be deleted when the record is deleted.'
        );
    }
}
