<?php

namespace Ginkelsoft\EncryptedSearch\Tests\Feature;

use Ginkelsoft\EncryptedSearch\Tests\Models\Client;
use Ginkelsoft\EncryptedSearch\EncryptedSearchServiceProvider;
use Ginkelsoft\EncryptedSearch\Models\SearchIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Orchestra\Testbench\TestCase;

/**
 * Feature tests for multi-field encrypted search functionality.
 *
 * Tests the ability to search across multiple fields simultaneously using
 * both exact and prefix matching strategies.
 */
class MultiFieldSearchTest extends TestCase
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

        // Disable Elasticsearch during tests
        config()->set('encrypted-search.elasticsearch.enabled', false);

        config(['encrypted-search.search_pepper' => 'test-pepper']);
        config(['encrypted-search.max_prefix_depth' => 6]);
        config(['encrypted-search.min_prefix_length' => 1]);

        // Ensure Eloquent events are active
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
            $table->string('model_id', 36);
            $table->string('field');
            $table->string('type');
            $table->string('token');
            $table->timestamps();
            $table->index(['model_type', 'field', 'type', 'token'], 'esi_lookup');
        });
    }

    /** @test */
    public function it_can_search_exact_match_across_multiple_fields(): void
    {
        // Create test clients
        $client1 = Client::create([
            'first_names' => 'John',
            'last_names' => 'Doe',
        ]);

        $client2 = Client::create([
            'first_names' => 'Jane',
            'last_names' => 'Smith',
        ]);

        $client3 = Client::create([
            'first_names' => 'John',
            'last_names' => 'Johnson',
        ]);

        // Search for "John" in both first_names and last_names
        $results = Client::encryptedExactMulti(['first_names', 'last_names'], 'John')
            ->pluck('id')
            ->toArray();

        // Should find client1 (first_names=John) and client3 (first_names=John and last_names contains John)
        $this->assertCount(2, $results);
        $this->assertContains($client1->id, $results);
        $this->assertContains($client3->id, $results);
        $this->assertNotContains($client2->id, $results);
    }

    /** @test */
    public function it_can_search_prefix_match_across_multiple_fields(): void
    {
        $client1 = Client::create([
            'first_names' => 'Wietse',
            'last_names' => 'Vermeer',
        ]);

        $client2 = Client::create([
            'first_names' => 'Vincent',
            'last_names' => 'Wieland',
        ]);

        $client3 = Client::create([
            'first_names' => 'Tom',
            'last_names' => 'Schmidt',
        ]);

        // Search for "Wie" prefix in both fields
        $results = Client::encryptedPrefixMulti(['first_names', 'last_names'], 'Wie')
            ->pluck('id')
            ->toArray();

        // Should find client1 (first_names starts with Wie) and client2 (last_names starts with Wie)
        $this->assertCount(2, $results);
        $this->assertContains($client1->id, $results);
        $this->assertContains($client2->id, $results);
        $this->assertNotContains($client3->id, $results);
    }

    /** @test */
    public function exact_multi_returns_unique_results_when_matching_multiple_fields(): void
    {
        // Create client where search term appears in multiple fields
        $client = Client::create([
            'first_names' => 'Smith',
            'last_names' => 'Smith',
        ]);

        $results = Client::encryptedExactMulti(['first_names', 'last_names'], 'Smith')
            ->get();

        // Should only return the client once, even though it matches in two fields
        $this->assertCount(1, $results);
        $this->assertEquals($client->id, $results->first()->id);
    }

    /** @test */
    public function prefix_multi_returns_unique_results_when_matching_multiple_fields(): void
    {
        $client = Client::create([
            'first_names' => 'Alexander',
            'last_names' => 'Alexis',
        ]);

        $results = Client::encryptedPrefixMulti(['first_names', 'last_names'], 'Alex')
            ->get();

        // Should only return the client once
        $this->assertCount(1, $results);
        $this->assertEquals($client->id, $results->first()->id);
    }

    /** @test */
    public function exact_multi_returns_no_results_when_no_fields_match(): void
    {
        Client::create([
            'first_names' => 'John',
            'last_names' => 'Doe',
        ]);

        $results = Client::encryptedExactMulti(['first_names', 'last_names'], 'NonExistent')
            ->get();

        $this->assertCount(0, $results);
    }

    /** @test */
    public function prefix_multi_returns_no_results_when_no_fields_match(): void
    {
        Client::create([
            'first_names' => 'John',
            'last_names' => 'Doe',
        ]);

        $results = Client::encryptedPrefixMulti(['first_names', 'last_names'], 'Xyz')
            ->get();

        $this->assertCount(0, $results);
    }

    /** @test */
    public function exact_multi_with_empty_fields_array_returns_no_results(): void
    {
        Client::create([
            'first_names' => 'John',
            'last_names' => 'Doe',
        ]);

        $results = Client::encryptedExactMulti([], 'John')->get();

        $this->assertCount(0, $results);
    }

    /** @test */
    public function prefix_multi_with_empty_fields_array_returns_no_results(): void
    {
        Client::create([
            'first_names' => 'John',
            'last_names' => 'Doe',
        ]);

        $results = Client::encryptedPrefixMulti([], 'John')->get();

        $this->assertCount(0, $results);
    }

    /** @test */
    public function exact_multi_with_empty_search_term_returns_no_results(): void
    {
        Client::create([
            'first_names' => 'John',
            'last_names' => 'Doe',
        ]);

        $results = Client::encryptedExactMulti(['first_names', 'last_names'], '')->get();

        $this->assertCount(0, $results);
    }

    /** @test */
    public function prefix_multi_with_empty_search_term_returns_no_results(): void
    {
        Client::create([
            'first_names' => 'John',
            'last_names' => 'Doe',
        ]);

        $results = Client::encryptedPrefixMulti(['first_names', 'last_names'], '')->get();

        $this->assertCount(0, $results);
    }

    /** @test */
    public function exact_multi_search_is_case_insensitive(): void
    {
        $client = Client::create([
            'first_names' => 'JOHN',
            'last_names' => 'doe',
        ]);

        $results = Client::encryptedExactMulti(['first_names', 'last_names'], 'john')
            ->pluck('id')
            ->toArray();

        $this->assertContains($client->id, $results);
    }

    /** @test */
    public function prefix_multi_search_is_case_insensitive(): void
    {
        $client = Client::create([
            'first_names' => 'JOHN',
            'last_names' => 'DOE',
        ]);

        $results = Client::encryptedPrefixMulti(['first_names', 'last_names'], 'joh')
            ->pluck('id')
            ->toArray();

        $this->assertContains($client->id, $results);
    }

    /** @test */
    public function exact_multi_handles_diacritics_consistently(): void
    {
        $client = Client::create([
            'first_names' => 'José',
            'last_names' => 'García',
        ]);

        $results = Client::encryptedExactMulti(['first_names', 'last_names'], 'Jose')
            ->pluck('id')
            ->toArray();

        $this->assertContains($client->id, $results);
    }

    /** @test */
    public function prefix_multi_handles_diacritics_consistently(): void
    {
        $client = Client::create([
            'first_names' => 'José',
            'last_names' => 'García',
        ]);

        $results = Client::encryptedPrefixMulti(['first_names', 'last_names'], 'Jos')
            ->pluck('id')
            ->toArray();

        $this->assertContains($client->id, $results);
    }

    /** @test */
    public function prefix_multi_respects_minimum_length_requirement(): void
    {
        config(['encrypted-search.min_prefix_length' => 3]);

        $client = Client::create([
            'first_names' => 'John',
            'last_names' => 'Doe',
        ]);

        // Search with 2 characters (below minimum)
        $results = Client::encryptedPrefixMulti(['first_names', 'last_names'], 'Jo')->get();
        $this->assertCount(0, $results);

        // Search with 3 characters (at minimum)
        $results = Client::encryptedPrefixMulti(['first_names', 'last_names'], 'Joh')->get();
        $this->assertCount(1, $results);
    }

    /** @test */
    public function exact_multi_can_search_single_field(): void
    {
        $client = Client::create([
            'first_names' => 'John',
            'last_names' => 'Doe',
        ]);

        // Multi-field with single field should work the same as regular exact
        $results = Client::encryptedExactMulti(['first_names'], 'John')
            ->pluck('id')
            ->toArray();

        $this->assertContains($client->id, $results);
    }

    /** @test */
    public function prefix_multi_can_search_single_field(): void
    {
        $client = Client::create([
            'first_names' => 'John',
            'last_names' => 'Doe',
        ]);

        // Multi-field with single field should work the same as regular prefix
        $results = Client::encryptedPrefixMulti(['first_names'], 'Joh')
            ->pluck('id')
            ->toArray();

        $this->assertContains($client->id, $results);
    }
}
