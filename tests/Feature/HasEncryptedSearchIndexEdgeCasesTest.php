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
 * Class HasEncryptedSearchIndexEdgeCasesTest
 *
 * Edge case and error handling tests for the HasEncryptedSearchIndex trait.
 *
 * Tests scenarios including:
 * - Empty field values
 * - Null values
 * - Special characters
 * - Non-encrypted fields
 * - Empty search queries
 * - SoftDeletes integration
 *
 * @package Ginkelsoft\EncryptedSearch\Tests\Feature
 * @covers  \Ginkelsoft\EncryptedSearch\Traits\HasEncryptedSearchIndex
 */
class HasEncryptedSearchIndexEdgeCasesTest extends TestCase
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

        \Illuminate\Database\Eloquent\Model::unsetEventDispatcher();
        \Illuminate\Database\Eloquent\Model::setEventDispatcher(app('events'));
        \Ginkelsoft\EncryptedSearch\Tests\Models\Client::boot();

        Schema::create('clients', function (Blueprint $table): void {
            $table->id();
            $table->string('first_names')->nullable();
            $table->string('last_names')->nullable();
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
     * Test that empty string fields do not generate tokens.
     *
     * @return void
     */
    public function test_empty_string_fields_do_not_generate_tokens(): void
    {
        $client = Client::create([
            'first_names' => '',
            'last_names'  => 'Doe',
        ]);

        $tokens = SearchIndex::where('model_id', $client->id)
            ->where('field', 'first_names')
            ->count();

        $this->assertEquals(0, $tokens, 'Empty string should not generate tokens');
    }

    /**
     * Test that null fields do not generate tokens.
     *
     * @return void
     */
    public function test_null_fields_do_not_generate_tokens(): void
    {
        $client = Client::create([
            'first_names' => null,
            'last_names'  => 'Doe',
        ]);

        $tokens = SearchIndex::where('model_id', $client->id)
            ->where('field', 'first_names')
            ->count();

        $this->assertEquals(0, $tokens, 'Null values should not generate tokens');
    }

    /**
     * Test that searching for empty string returns no results.
     *
     * @return void
     */
    public function test_searching_for_empty_string_returns_no_results(): void
    {
        Client::create(['first_names' => 'John', 'last_names' => 'Doe']);

        $results = Client::encryptedExact('first_names', '')->get();

        $this->assertCount(0, $results);
    }

    /**
     * Test that fields with only special characters do not generate tokens.
     *
     * @return void
     */
    public function test_special_characters_only_do_not_generate_tokens(): void
    {
        $client = Client::create([
            'first_names' => '!!!@@@',
            'last_names'  => 'Doe',
        ]);

        $tokens = SearchIndex::where('model_id', $client->id)
            ->where('field', 'first_names')
            ->count();

        $this->assertEquals(0, $tokens, 'Special characters only should not generate tokens');
    }

    /**
     * Test that spaces are removed during normalization.
     *
     * @return void
     */
    public function test_spaces_are_normalized_correctly(): void
    {
        Client::create(['first_names' => 'John Paul', 'last_names' => 'Doe']);

        // Search without space should match
        $results = Client::encryptedExact('first_names', 'JohnPaul')->get();
        $this->assertCount(1, $results);

        // Search with space should also match (gets normalized)
        $results = Client::encryptedExact('first_names', 'John Paul')->get();
        $this->assertCount(1, $results);
    }

    /**
     * Test that diacritics are handled consistently.
     *
     * @return void
     */
    public function test_diacritics_are_handled_consistently(): void
    {
        Client::create(['first_names' => 'José', 'last_names' => 'Garcia']);

        // Search without diacritic should match
        $results = Client::encryptedExact('first_names', 'Jose')->get();
        $this->assertCount(1, $results);

        // Search with diacritic should also match
        $results = Client::encryptedExact('first_names', 'José')->get();
        $this->assertCount(1, $results);
    }

    /**
     * Test that case is handled consistently.
     *
     * @return void
     */
    public function test_case_is_handled_consistently(): void
    {
        Client::create(['first_names' => 'John', 'last_names' => 'Doe']);

        // Lowercase search
        $results = Client::encryptedExact('first_names', 'john')->get();
        $this->assertCount(1, $results);

        // Uppercase search
        $results = Client::encryptedExact('first_names', 'JOHN')->get();
        $this->assertCount(1, $results);

        // Mixed case search
        $results = Client::encryptedExact('first_names', 'JoHn')->get();
        $this->assertCount(1, $results);
    }

    /**
     * Test that updating with empty value removes previous tokens.
     *
     * @return void
     */
    public function test_updating_with_empty_value_removes_tokens(): void
    {
        $client = Client::create(['first_names' => 'John', 'last_names' => 'Doe']);

        $initialCount = SearchIndex::where('model_id', $client->id)
            ->where('field', 'first_names')
            ->count();

        $this->assertGreaterThan(0, $initialCount);

        $client->update(['first_names' => '']);

        $finalCount = SearchIndex::where('model_id', $client->id)
            ->where('field', 'first_names')
            ->count();

        $this->assertEquals(0, $finalCount);
    }

    /**
     * Test that prefix search with single character works.
     *
     * @return void
     */
    public function test_prefix_search_with_single_character(): void
    {
        Client::create(['first_names' => 'John', 'last_names' => 'Doe']);
        Client::create(['first_names' => 'Jane', 'last_names' => 'Smith']);
        Client::create(['first_names' => 'Bob', 'last_names' => 'Johnson']);

        $results = Client::encryptedPrefix('first_names', 'J')->get();

        $this->assertCount(2, $results, 'Single character prefix should match John and Jane');
    }

    /**
     * Test that non-existent search terms return no results.
     *
     * @return void
     */
    public function test_non_existent_search_terms_return_no_results(): void
    {
        Client::create(['first_names' => 'John', 'last_names' => 'Doe']);

        $results = Client::encryptedExact('first_names', 'NonExistent')->get();
        $this->assertCount(0, $results);

        $results = Client::encryptedPrefix('first_names', 'XYZ')->get();
        $this->assertCount(0, $results);
    }

    /**
     * Test that multiple models can have the same encrypted value.
     *
     * @return void
     */
    public function test_multiple_models_with_same_value(): void
    {
        Client::create(['first_names' => 'John', 'last_names' => 'Doe']);
        Client::create(['first_names' => 'John', 'last_names' => 'Smith']);

        $results = Client::encryptedExact('first_names', 'John')->get();
        $this->assertCount(2, $results);
    }

    /**
     * Test that very long strings are handled correctly.
     *
     * @return void
     */
    public function test_very_long_strings_are_handled(): void
    {
        $longString = str_repeat('a', 1000);
        $client = Client::create(['first_names' => $longString, 'last_names' => 'Doe']);

        $tokens = SearchIndex::where('model_id', $client->id)
            ->where('field', 'first_names')
            ->count();

        $this->assertGreaterThan(0, $tokens);

        $results = Client::encryptedExact('first_names', $longString)->get();
        $this->assertCount(1, $results);
    }

    /**
     * Test that numbers are preserved in normalization.
     *
     * @return void
     */
    public function test_numbers_are_preserved(): void
    {
        Client::create(['first_names' => 'User123', 'last_names' => 'Doe']);

        $results = Client::encryptedExact('first_names', 'User123')->get();
        $this->assertCount(1, $results);

        $results = Client::encryptedExact('first_names', 'user123')->get();
        $this->assertCount(1, $results);
    }

    /**
     * Test that prefix search respects max depth configuration.
     *
     * @return void
     */
    public function test_prefix_search_respects_max_depth(): void
    {
        config()->set('encrypted-search.max_prefix_depth', 3);

        $client = Client::create(['first_names' => 'Alexander', 'last_names' => 'Doe']);

        // Count prefix tokens (should be max 3)
        $prefixTokens = SearchIndex::where('model_id', $client->id)
            ->where('field', 'first_names')
            ->where('type', 'prefix')
            ->count();

        $this->assertEquals(3, $prefixTokens, 'Should only generate 3 prefix tokens');
    }

    /**
     * Test that updating model without changing indexed fields does not cause errors.
     *
     * @return void
     */
    public function test_updating_non_indexed_fields_works(): void
    {
        $client = Client::create(['first_names' => 'John', 'last_names' => 'Doe']);

        $initialCount = SearchIndex::where('model_id', $client->id)->count();

        // Update timestamps (which are not indexed)
        $client->touch();

        $finalCount = SearchIndex::where('model_id', $client->id)->count();

        $this->assertEquals($initialCount, $finalCount);
    }
}
