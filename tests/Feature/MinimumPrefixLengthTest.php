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
 * Class MinimumPrefixLengthTest
 *
 * Tests for the minimum prefix length configuration option.
 *
 * Validates that:
 * - Search queries shorter than the minimum length return no results
 * - Search queries at or above the minimum length work correctly
 * - Token generation respects the minimum length setting
 * - The feature prevents overly broad searches
 *
 * @package Ginkelsoft\EncryptedSearch\Tests\Feature
 * @covers  \Ginkelsoft\EncryptedSearch\Traits\HasEncryptedSearchIndex
 * @covers  \Ginkelsoft\EncryptedSearch\Support\Tokens
 */
class MinimumPrefixLengthTest extends TestCase
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
        config()->set('encrypted-search.min_prefix_length', 3);
        config()->set('encrypted-search.max_prefix_depth', 6);

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
     * Test that searches shorter than minimum length return no results.
     *
     * @return void
     */
    public function test_searches_shorter_than_minimum_length_return_no_results(): void
    {
        Client::create(['first_names' => 'Wilma', 'last_names' => 'Jansen']);
        Client::create(['first_names' => 'Wietse', 'last_names' => 'van Ginkel']);

        // Search with 1 character (min is 3)
        $results = Client::encryptedPrefix('first_names', 'W')->get();
        $this->assertCount(0, $results, 'Single character search should return no results');

        // Search with 2 characters (min is 3)
        $results = Client::encryptedPrefix('first_names', 'Wi')->get();
        $this->assertCount(0, $results, 'Two character search should return no results');
    }

    /**
     * Test that searches at minimum length work correctly.
     *
     * @return void
     */
    public function test_searches_at_minimum_length_work(): void
    {
        Client::create(['first_names' => 'Wilma', 'last_names' => 'Jansen']);
        Client::create(['first_names' => 'Wietse', 'last_names' => 'van Ginkel']);
        Client::create(['first_names' => 'Tom', 'last_names' => 'Bakker']);

        // Search with exactly 3 characters (minimum length)
        $results = Client::encryptedPrefix('first_names', 'Wil')->get();
        $this->assertCount(1, $results, 'Should find Wilma');
        $this->assertEquals('Wilma', $results->first()->first_names);
    }

    /**
     * Test that searches above minimum length work correctly.
     *
     * @return void
     */
    public function test_searches_above_minimum_length_work(): void
    {
        Client::create(['first_names' => 'Wilma', 'last_names' => 'Jansen']);
        Client::create(['first_names' => 'Wietse', 'last_names' => 'van Ginkel']);

        // Search with 4 characters
        $results = Client::encryptedPrefix('first_names', 'Wilm')->get();
        $this->assertCount(1, $results);
        $this->assertEquals('Wilma', $results->first()->first_names);

        // Search with 5 characters
        $results = Client::encryptedPrefix('first_names', 'Wietse')->get();
        $this->assertCount(1, $results);
        $this->assertEquals('Wietse', $results->first()->first_names);
    }

    /**
     * Test that token generation respects minimum length.
     *
     * @return void
     */
    public function test_token_generation_respects_minimum_length(): void
    {
        $client = Client::create(['first_names' => 'Wilma', 'last_names' => 'Jansen']);

        // Count prefix tokens for first_names
        // "wilma" normalized = 5 chars, with min_length=3, max_depth=6
        // Should generate tokens for: "wil", "wilm", "wilma" = 3 tokens
        $prefixTokens = SearchIndex::where('model_id', $client->id)
            ->where('field', 'first_names')
            ->where('type', 'prefix')
            ->count();

        $this->assertEquals(3, $prefixTokens, 'Should generate 3 prefix tokens (wil, wilm, wilma)');
    }

    /**
     * Test that short names still generate tokens when long enough.
     *
     * @return void
     */
    public function test_short_names_generate_tokens_when_long_enough(): void
    {
        // "Tom" = 3 characters, exactly at minimum length
        $client = Client::create(['first_names' => 'Tom', 'last_names' => 'Bakker']);

        // Should generate exactly 1 prefix token for "tom"
        $prefixTokens = SearchIndex::where('model_id', $client->id)
            ->where('field', 'first_names')
            ->where('type', 'prefix')
            ->count();

        $this->assertEquals(1, $prefixTokens, 'Should generate 1 prefix token for 3-char name');

        // Can search for it
        $results = Client::encryptedPrefix('first_names', 'Tom')->get();
        $this->assertCount(1, $results);
    }

    /**
     * Test that very short names don't generate prefix tokens.
     *
     * @return void
     */
    public function test_very_short_names_dont_generate_prefix_tokens(): void
    {
        // Create a model with 2-character first name (below minimum)
        Schema::table('clients', function (Blueprint $table) {
            $table->string('first_names')->nullable()->change();
        });

        $client = Client::create(['first_names' => 'Jo', 'last_names' => 'Smith']);

        // Should generate 0 prefix tokens (name too short)
        $prefixTokens = SearchIndex::where('model_id', $client->id)
            ->where('field', 'first_names')
            ->where('type', 'prefix')
            ->count();

        $this->assertEquals(0, $prefixTokens, 'Should not generate prefix tokens for 2-char name');

        // But should still generate exact token
        $exactTokens = SearchIndex::where('model_id', $client->id)
            ->where('field', 'first_names')
            ->where('type', 'exact')
            ->count();

        $this->assertEquals(1, $exactTokens, 'Should generate exact token even for short names');
    }

    /**
     * Test with minimum length set to 1 (backwards compatibility).
     *
     * @return void
     */
    public function test_minimum_length_one_allows_all_prefixes(): void
    {
        config()->set('encrypted-search.min_prefix_length', 1);

        $client = Client::create(['first_names' => 'Tom', 'last_names' => 'Bakker']);

        // With min_length=1, max_depth=6, "tom" (3 chars) should generate 3 tokens
        $prefixTokens = SearchIndex::where('model_id', $client->id)
            ->where('field', 'first_names')
            ->where('type', 'prefix')
            ->count();

        $this->assertEquals(3, $prefixTokens, 'Should generate tokens for t, to, tom');

        // Single character search should work
        $results = Client::encryptedPrefix('first_names', 'T')->get();
        $this->assertCount(1, $results);
    }

    /**
     * Test with higher minimum length (4 characters).
     *
     * @return void
     */
    public function test_higher_minimum_length_restricts_more(): void
    {
        config()->set('encrypted-search.min_prefix_length', 4);

        Client::create(['first_names' => 'Alexander', 'last_names' => 'Smith']);

        // 3-character search should fail
        $results = Client::encryptedPrefix('first_names', 'Ale')->get();
        $this->assertCount(0, $results);

        // 4-character search should work
        $results = Client::encryptedPrefix('first_names', 'Alex')->get();
        $this->assertCount(1, $results);
    }

    /**
     * Test that exact search is not affected by minimum prefix length.
     *
     * @return void
     */
    public function test_exact_search_not_affected_by_minimum_length(): void
    {
        config()->set('encrypted-search.min_prefix_length', 10);

        Client::create(['first_names' => 'Tom', 'last_names' => 'Bakker']);

        // Exact search should still work regardless of minimum prefix length
        $results = Client::encryptedExact('first_names', 'Tom')->get();
        $this->assertCount(1, $results);
    }

    /**
     * Test that normalized length is checked, not original length.
     *
     * @return void
     */
    public function test_normalized_length_is_checked(): void
    {
        Client::create(['first_names' => 'Élo', 'last_names' => 'Dupont']);

        // "Élo" with spaces and diacritics: "Élo" -> normalized "elo" = 3 chars
        // Should work with min_length=3
        $results = Client::encryptedPrefix('first_names', 'Élo')->get();
        $this->assertCount(1, $results);

        // But "É" normalized to "e" = 1 char, should not work
        $results = Client::encryptedPrefix('first_names', 'É')->get();
        $this->assertCount(0, $results);
    }
}
