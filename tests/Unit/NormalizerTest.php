<?php

namespace Ginkelsoft\EncryptedSearch\Tests\Unit;

use Ginkelsoft\EncryptedSearch\Support\Normalizer;
use PHPUnit\Framework\TestCase;

/**
 * Class NormalizerTest
 *
 * Unit tests for the Normalizer utility class.
 *
 * Validates text normalization logic including:
 * - Lowercasing
 * - Diacritic removal
 * - Non-alphanumeric character stripping
 * - Null handling
 * - Empty string handling
 *
 * @package Ginkelsoft\EncryptedSearch\Tests\Unit
 * @covers  \Ginkelsoft\EncryptedSearch\Support\Normalizer
 */
class NormalizerTest extends TestCase
{
    /**
     * Test that uppercase text is converted to lowercase.
     *
     * @return void
     */
    public function test_it_lowercases_text(): void
    {
        $result = Normalizer::normalize('WIETSE');
        $this->assertEquals('wietse', $result);
    }

    /**
     * Test that diacritics are removed from text.
     *
     * @return void
     */
    public function test_it_removes_diacritics(): void
    {
        $result = Normalizer::normalize('Élodie');
        $this->assertEquals('elodie', $result);

        $result = Normalizer::normalize('Müller');
        $this->assertEquals('muller', $result);

        $result = Normalizer::normalize('José');
        $this->assertEquals('jose', $result);
    }

    /**
     * Test that spaces and special characters are stripped.
     *
     * @return void
     */
    public function test_it_removes_spaces_and_special_characters(): void
    {
        $result = Normalizer::normalize('Wietse van Ginkel');
        $this->assertEquals('wietsevanginkel', $result);

        $result = Normalizer::normalize('John-Paul');
        $this->assertEquals('johnpaul', $result);

        $result = Normalizer::normalize('O\'Brien');
        $this->assertEquals('obrien', $result);
    }

    /**
     * Test that numbers are preserved in normalized output.
     *
     * @return void
     */
    public function test_it_preserves_numbers(): void
    {
        $result = Normalizer::normalize('Address123');
        $this->assertEquals('address123', $result);

        $result = Normalizer::normalize('2024 Year');
        $this->assertEquals('2024year', $result);
    }

    /**
     * Test that null input returns null output.
     *
     * @return void
     */
    public function test_it_handles_null_input(): void
    {
        $result = Normalizer::normalize(null);
        $this->assertNull($result);
    }

    /**
     * Test that empty strings return empty strings.
     *
     * @return void
     */
    public function test_it_handles_empty_strings(): void
    {
        $result = Normalizer::normalize('');
        $this->assertEquals('', $result);
    }

    /**
     * Test that strings with only special characters result in empty string.
     *
     * @return void
     */
    public function test_it_returns_empty_for_special_characters_only(): void
    {
        $result = Normalizer::normalize('!!!@@@###');
        $this->assertEquals('', $result);

        $result = Normalizer::normalize('   ');
        $this->assertEquals('', $result);
    }

    /**
     * Test normalization with complex international characters.
     *
     * @return void
     */
    public function test_it_handles_complex_international_characters(): void
    {
        $result = Normalizer::normalize('Åse Øvergård');
        $this->assertEquals('asevergard', $result);

        $result = Normalizer::normalize('Françoise');
        $this->assertEquals('francoise', $result);
    }

    /**
     * Test that repeated normalization is idempotent.
     *
     * @return void
     */
    public function test_normalization_is_idempotent(): void
    {
        $input = 'Wietse van Ginkel';
        $first = Normalizer::normalize($input);
        $second = Normalizer::normalize($first);

        $this->assertEquals($first, $second);
    }
}
