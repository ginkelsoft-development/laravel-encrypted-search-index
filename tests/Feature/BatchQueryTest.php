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
 * Class BatchQueryTest
 *
 * Tests for batch query optimization features.
 *
 * Validates:
 * - encryptedSearchAny() for OR logic across multiple fields
 * - encryptedSearchAll() for AND logic across multiple fields
 * - Performance optimization through single queries
 *
 * @package Ginkelsoft\EncryptedSearch\Tests\Feature
 * @covers  \Ginkelsoft\EncryptedSearch\Traits\HasEncryptedSearchIndex
 */
class BatchQueryTest extends TestCase
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [EncryptedSearchServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'testing');
        config()->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        config()->set('encrypted-search.elasticsearch.enabled', false);
        config()->set('encrypted-search.search_pepper', 'test-pepper-secret');
        config()->set('encrypted-search.min_prefix_length', 1);

        \Illuminate\Database\Eloquent\Model::unsetEventDispatcher();
        \Illuminate\Database\Eloquent\Model::setEventDispatcher(app('events'));
        \Ginkelsoft\EncryptedSearch\Tests\Models\Client::boot();

        Schema::create('clients', function (Blueprint $table): void {
            $table->id();
            $table->string('first_names');
            $table->string('last_names');
            $table->timestamps();
        });

        Schema::create('encrypted_search_index', function (Blueprint $table): void {
            $table->id();
            $table->string('model_type');
            $table->string('model_id', 36);
            $table->string('field');
            $table->string('type');
            $table->string('token');
            $table->timestamps();
            $table->index(['model_type', 'field', 'type', 'token'], 'esi_lookup');
        });
    }

    /**
     * Test encryptedSearchAny with exact match (OR logic).
     *
     * @return void
     */
    public function test_encrypted_search_any_with_exact_match(): void
    {
        Client::create(['first_names' => 'John', 'last_names' => 'Doe']);
        Client::create(['first_names' => 'Jane', 'last_names' => 'Smith']);
        Client::create(['first_names' => 'Bob', 'last_names' => 'John']);

        // Search for "John" in either first_names or last_names
        $results = Client::encryptedSearchAny(['first_names', 'last_names'], 'John', 'exact')->get();

        // Should find: John Doe (first_names) and Bob John (last_names)
        $this->assertCount(2, $results);
        $names = $results->pluck('first_names')->toArray();
        $this->assertContains('John', $names);
        $this->assertContains('Bob', $names);
    }

    /**
     * Test encryptedSearchAny with prefix match (OR logic).
     *
     * @return void
     */
    public function test_encrypted_search_any_with_prefix_match(): void
    {
        Client::create(['first_names' => 'Wietse', 'last_names' => 'van Ginkel']);
        Client::create(['first_names' => 'John', 'last_names' => 'Williams']);
        Client::create(['first_names' => 'Jane', 'last_names' => 'Wilson']);

        // Search for "Wi" prefix in either field
        $results = Client::encryptedSearchAny(['first_names', 'last_names'], 'Wi', 'prefix')->get();

        // Should find: Wietse (first_names), Williams (last_names), Wilson (last_names)
        $this->assertCount(3, $results);
    }

    /**
     * Test encryptedSearchAll with exact match (AND logic).
     *
     * @return void
     */
    public function test_encrypted_search_all_with_exact_match(): void
    {
        Client::create(['first_names' => 'John', 'last_names' => 'Doe']);
        Client::create(['first_names' => 'John', 'last_names' => 'Smith']);
        Client::create(['first_names' => 'Jane', 'last_names' => 'Doe']);

        // Search for John AND Doe
        $results = Client::encryptedSearchAll([
            'first_names' => 'John',
            'last_names' => 'Doe'
        ], 'exact')->get();

        // Should only find: John Doe
        $this->assertCount(1, $results);
        $this->assertEquals('John', $results->first()->first_names);
        $this->assertEquals('Doe', $results->first()->last_names);
    }

    /**
     * Test encryptedSearchAll with prefix match (AND logic).
     *
     * @return void
     */
    public function test_encrypted_search_all_with_prefix_match(): void
    {
        Client::create(['first_names' => 'John', 'last_names' => 'Doe']);
        Client::create(['first_names' => 'Johnny', 'last_names' => 'Doyle']);
        Client::create(['first_names' => 'Jane', 'last_names' => 'Smith']);

        // Search for "Jo" prefix in first_names AND "Do" prefix in last_names
        $results = Client::encryptedSearchAll([
            'first_names' => 'Jo',
            'last_names' => 'Do'
        ], 'prefix')->get();

        // Should find: John Doe, Johnny Doyle (both have "Jo" in first_names and "Do" in last_names)
        // Jane Smith is excluded (no "Do" prefix in last_names)
        $this->assertCount(2, $results);
        $names = $results->pluck('first_names')->toArray();
        $this->assertContains('John', $names);
        $this->assertContains('Johnny', $names);
    }

    /**
     * Test encryptedSearchAny with no matching results.
     *
     * @return void
     */
    public function test_encrypted_search_any_with_no_results(): void
    {
        Client::create(['first_names' => 'John', 'last_names' => 'Doe']);

        $results = Client::encryptedSearchAny(['first_names', 'last_names'], 'NonExistent', 'exact')->get();

        $this->assertCount(0, $results);
    }

    /**
     * Test encryptedSearchAll with no matching results.
     *
     * @return void
     */
    public function test_encrypted_search_all_with_no_results(): void
    {
        Client::create(['first_names' => 'John', 'last_names' => 'Doe']);

        // John exists but Smith doesn't
        $results = Client::encryptedSearchAll([
            'first_names' => 'John',
            'last_names' => 'Smith'
        ], 'exact')->get();

        $this->assertCount(0, $results);
    }

    /**
     * Test encryptedSearchAny with empty fields array.
     *
     * @return void
     */
    public function test_encrypted_search_any_with_empty_fields(): void
    {
        Client::create(['first_names' => 'John', 'last_names' => 'Doe']);

        $results = Client::encryptedSearchAny([], 'John', 'exact')->get();

        $this->assertCount(0, $results);
    }

    /**
     * Test encryptedSearchAll with empty field terms.
     *
     * @return void
     */
    public function test_encrypted_search_all_with_empty_field_terms(): void
    {
        Client::create(['first_names' => 'John', 'last_names' => 'Doe']);

        $results = Client::encryptedSearchAll([], 'exact')->get();

        $this->assertCount(0, $results);
    }

    /**
     * Test encryptedSearchAny with single field (should work like regular search).
     *
     * @return void
     */
    public function test_encrypted_search_any_with_single_field(): void
    {
        Client::create(['first_names' => 'John', 'last_names' => 'Doe']);
        Client::create(['first_names' => 'Jane', 'last_names' => 'Smith']);

        $results = Client::encryptedSearchAny(['first_names'], 'John', 'exact')->get();

        $this->assertCount(1, $results);
        $this->assertEquals('John', $results->first()->first_names);
    }

    /**
     * Test encryptedSearchAll with single field.
     *
     * @return void
     */
    public function test_encrypted_search_all_with_single_field(): void
    {
        Client::create(['first_names' => 'John', 'last_names' => 'Doe']);
        Client::create(['first_names' => 'Jane', 'last_names' => 'Smith']);

        $results = Client::encryptedSearchAll(['first_names' => 'John'], 'exact')->get();

        $this->assertCount(1, $results);
        $this->assertEquals('John', $results->first()->first_names);
    }

    /**
     * Test that encryptedSearchAny is more efficient than multiple OR queries.
     *
     * @return void
     */
    public function test_encrypted_search_any_efficiency(): void
    {
        // Create test data
        for ($i = 0; $i < 50; $i++) {
            Client::create([
                'first_names' => 'Name' . $i,
                'last_names' => 'Last' . $i
            ]);
        }

        // Single batch query
        $startTime = microtime(true);
        $results1 = Client::encryptedSearchAny(['first_names', 'last_names'], 'Name1', 'exact')->get();
        $batchTime = microtime(true) - $startTime;

        // Multiple individual queries
        $startTime = microtime(true);
        $results2 = Client::where(function ($query) {
            $query->encryptedExact('first_names', 'Name1')
                  ->orWhere(function ($q) {
                      $q->encryptedExact('last_names', 'Name1');
                  });
        })->get();
        $individualTime = microtime(true) - $startTime;

        // Both should return same results
        $this->assertEquals($results1->count(), $results2->count());

        // Batch query should be faster (or at least not significantly slower)
        // We don't assert this strictly as it depends on system performance
        $this->assertLessThanOrEqual($individualTime * 2, $batchTime);
    }

    /**
     * Test encryptedSearchAny respects minimum prefix length.
     *
     * @return void
     */
    public function test_encrypted_search_any_respects_min_prefix_length(): void
    {
        config()->set('encrypted-search.min_prefix_length', 3);

        Client::create(['first_names' => 'Wilma', 'last_names' => 'Jansen']);

        // Should return no results (too short)
        $results = Client::encryptedSearchAny(['first_names', 'last_names'], 'Wi', 'prefix')->get();
        $this->assertCount(0, $results);

        // Should work (meets minimum)
        $results = Client::encryptedSearchAny(['first_names', 'last_names'], 'Wil', 'prefix')->get();
        $this->assertCount(1, $results);
    }

    /**
     * Test encryptedSearchAll respects minimum prefix length.
     *
     * @return void
     */
    public function test_encrypted_search_all_respects_min_prefix_length(): void
    {
        config()->set('encrypted-search.min_prefix_length', 3);

        Client::create(['first_names' => 'Wilma', 'last_names' => 'Jansen']);

        // Should return no results (first_names too short)
        $results = Client::encryptedSearchAll([
            'first_names' => 'Wi',
            'last_names' => 'Jan'
        ], 'prefix')->get();
        $this->assertCount(0, $results);

        // Should work (both meet minimum)
        $results = Client::encryptedSearchAll([
            'first_names' => 'Wil',
            'last_names' => 'Jan'
        ], 'prefix')->get();
        $this->assertCount(1, $results);
    }
}
