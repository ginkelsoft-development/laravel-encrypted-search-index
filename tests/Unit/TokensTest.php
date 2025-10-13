<?php

namespace Ginkelsoft\EncryptedSearch\Tests\Unit;

use Ginkelsoft\EncryptedSearch\Support\Tokens;
use PHPUnit\Framework\TestCase;

/**
 * Class TokensTest
 *
 * Unit tests for the Tokens utility class.
 *
 * Validates token generation logic including:
 * - Exact-match token generation
 * - Prefix token generation
 * - Pepper validation
 * - Deterministic output
 * - Edge cases (empty strings, max depth)
 *
 * @package Ginkelsoft\EncryptedSearch\Tests\Unit
 * @covers  \Ginkelsoft\EncryptedSearch\Support\Tokens
 */
class TokensTest extends TestCase
{
    /**
     * Test that exact() generates a SHA-256 hash.
     *
     * @return void
     */
    public function test_exact_generates_sha256_hash(): void
    {
        $token = Tokens::exact('wietse', 'test-pepper');

        // SHA-256 produces 64 hex characters
        $this->assertEquals(64, strlen($token));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
    }

    /**
     * Test that exact() is deterministic (same input = same output).
     *
     * @return void
     */
    public function test_exact_is_deterministic(): void
    {
        $token1 = Tokens::exact('wietse', 'test-pepper');
        $token2 = Tokens::exact('wietse', 'test-pepper');

        $this->assertEquals($token1, $token2);
    }

    /**
     * Test that different peppers produce different tokens.
     *
     * @return void
     */
    public function test_exact_varies_with_pepper(): void
    {
        $token1 = Tokens::exact('wietse', 'pepper1');
        $token2 = Tokens::exact('wietse', 'pepper2');

        $this->assertNotEquals($token1, $token2);
    }

    /**
     * Test that exact() throws exception when pepper is empty.
     *
     * @return void
     */
    public function test_exact_throws_exception_for_empty_pepper(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SEARCH_PEPPER is not configured');

        Tokens::exact('wietse', '');
    }

    /**
     * Test that prefixes() generates correct number of tokens.
     *
     * @return void
     */
    public function test_prefixes_generates_correct_count(): void
    {
        $tokens = Tokens::prefixes('wietse', 3, 'test-pepper');

        $this->assertCount(3, $tokens);
    }

    /**
     * Test that prefixes() respects max depth shorter than string length.
     *
     * @return void
     */
    public function test_prefixes_respects_max_depth(): void
    {
        $tokens = Tokens::prefixes('wietsevanginkel', 4, 'test-pepper');

        // Should generate tokens for: "w", "wi", "wie", "wiet"
        $this->assertCount(4, $tokens);
    }

    /**
     * Test that prefixes() does not exceed string length.
     *
     * @return void
     */
    public function test_prefixes_does_not_exceed_string_length(): void
    {
        $tokens = Tokens::prefixes('joe', 10, 'test-pepper');

        // Should only generate 3 tokens for "j", "jo", "joe"
        $this->assertCount(3, $tokens);
    }

    /**
     * Test that prefixes() is deterministic.
     *
     * @return void
     */
    public function test_prefixes_is_deterministic(): void
    {
        $tokens1 = Tokens::prefixes('wietse', 3, 'test-pepper');
        $tokens2 = Tokens::prefixes('wietse', 3, 'test-pepper');

        $this->assertEquals($tokens1, $tokens2);
    }

    /**
     * Test that prefixes() varies with pepper.
     *
     * @return void
     */
    public function test_prefixes_varies_with_pepper(): void
    {
        $tokens1 = Tokens::prefixes('wietse', 3, 'pepper1');
        $tokens2 = Tokens::prefixes('wietse', 3, 'pepper2');

        $this->assertNotEquals($tokens1, $tokens2);
    }

    /**
     * Test that prefixes() throws exception when pepper is empty.
     *
     * @return void
     */
    public function test_prefixes_throws_exception_for_empty_pepper(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SEARCH_PEPPER is not configured');

        Tokens::prefixes('wietse', 3, '');
    }

    /**
     * Test that prefixes() returns all SHA-256 hashes.
     *
     * @return void
     */
    public function test_prefixes_returns_sha256_hashes(): void
    {
        $tokens = Tokens::prefixes('alex', 4, 'test-pepper');

        foreach ($tokens as $token) {
            $this->assertEquals(64, strlen($token));
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        }
    }

    /**
     * Test that prefixes() handles single character strings.
     *
     * @return void
     */
    public function test_prefixes_handles_single_character(): void
    {
        $tokens = Tokens::prefixes('a', 3, 'test-pepper');

        $this->assertCount(1, $tokens);
    }

    /**
     * Test that prefixes() handles UTF-8 characters correctly.
     *
     * @return void
     */
    public function test_prefixes_handles_utf8_characters(): void
    {
        $tokens = Tokens::prefixes('café', 4, 'test-pepper');

        // Should generate 4 tokens for "c", "ca", "caf", "café"
        $this->assertCount(4, $tokens);
    }

    /**
     * Test that each prefix token is unique.
     *
     * @return void
     */
    public function test_prefix_tokens_are_unique(): void
    {
        $tokens = Tokens::prefixes('wietse', 6, 'test-pepper');

        $uniqueTokens = array_unique($tokens);
        $this->assertCount(count($tokens), $uniqueTokens);
    }

    /**
     * Test that prefix tokens differ from exact token.
     *
     * @return void
     */
    public function test_prefix_tokens_differ_from_exact(): void
    {
        $exact = Tokens::exact('wietse', 'test-pepper');
        $prefixes = Tokens::prefixes('wietse', 6, 'test-pepper');

        // The last prefix should match the exact token (full string)
        $this->assertEquals($exact, end($prefixes));
    }

    /**
     * Test that minimum length parameter filters short prefixes.
     *
     * @return void
     */
    public function test_prefixes_respects_minimum_length(): void
    {
        // With minLength=3, "wietse" should generate tokens for: "wie", "wiet", "wiets", "wietse"
        $tokens = Tokens::prefixes('wietse', 6, 'test-pepper', 3);

        $this->assertCount(4, $tokens, 'Should skip first 2 characters and generate 4 tokens');
    }

    /**
     * Test that minimum length of 1 generates all prefixes (backwards compatible).
     *
     * @return void
     */
    public function test_prefixes_with_min_length_one(): void
    {
        // With minLength=1, should generate all prefixes
        $tokens = Tokens::prefixes('alex', 4, 'test-pepper', 1);

        $this->assertCount(4, $tokens, 'Should generate tokens for a, al, ale, alex');
    }

    /**
     * Test that minimum length equal to string length generates one token.
     *
     * @return void
     */
    public function test_prefixes_with_min_length_equal_to_string_length(): void
    {
        $tokens = Tokens::prefixes('tom', 6, 'test-pepper', 3);

        $this->assertCount(1, $tokens, 'Should generate only one token for "tom"');
    }

    /**
     * Test that minimum length exceeding string length generates no tokens.
     *
     * @return void
     */
    public function test_prefixes_with_min_length_exceeding_string_length(): void
    {
        $tokens = Tokens::prefixes('ab', 6, 'test-pepper', 3);

        $this->assertCount(0, $tokens, 'Should generate no tokens when string is shorter than minimum');
    }

    /**
     * Test that minimum length works with UTF-8 strings.
     *
     * @return void
     */
    public function test_prefixes_minimum_length_with_utf8(): void
    {
        // "café" = 4 UTF-8 characters, with minLength=2
        $tokens = Tokens::prefixes('café', 4, 'test-pepper', 2);

        // Should generate tokens for: "ca", "caf", "café" (3 tokens)
        $this->assertCount(3, $tokens);
    }

    /**
     * Test default minimum length parameter (backwards compatibility).
     *
     * @return void
     */
    public function test_prefixes_default_minimum_length(): void
    {
        // Without specifying minLength, should default to 1
        $tokens = Tokens::prefixes('alex', 4, 'test-pepper');

        $this->assertCount(4, $tokens, 'Default minLength should be 1');
    }
}
