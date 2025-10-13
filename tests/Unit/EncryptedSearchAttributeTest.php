<?php

namespace Ginkelsoft\EncryptedSearch\Tests\Unit;

use Ginkelsoft\EncryptedSearch\Attributes\EncryptedSearch;
use PHPUnit\Framework\TestCase;

/**
 * Class EncryptedSearchAttributeTest
 *
 * Unit tests for the EncryptedSearch PHP attribute.
 *
 * Validates:
 * - Default values (exact=true, prefix=false)
 * - Custom configuration
 * - toArray() method output
 *
 * @package Ginkelsoft\EncryptedSearch\Tests\Unit
 * @covers  \Ginkelsoft\EncryptedSearch\Attributes\EncryptedSearch
 */
class EncryptedSearchAttributeTest extends TestCase
{
    /**
     * Test that default values are set correctly.
     *
     * @return void
     */
    public function test_it_has_correct_default_values(): void
    {
        $attribute = new EncryptedSearch();

        $this->assertTrue($attribute->exact);
        $this->assertFalse($attribute->prefix);
    }

    /**
     * Test that custom values can be set.
     *
     * @return void
     */
    public function test_it_accepts_custom_values(): void
    {
        $attribute = new EncryptedSearch(exact: false, prefix: true);

        $this->assertFalse($attribute->exact);
        $this->assertTrue($attribute->prefix);
    }

    /**
     * Test that both exact and prefix can be enabled.
     *
     * @return void
     */
    public function test_it_allows_both_modes_enabled(): void
    {
        $attribute = new EncryptedSearch(exact: true, prefix: true);

        $this->assertTrue($attribute->exact);
        $this->assertTrue($attribute->prefix);
    }

    /**
     * Test that both exact and prefix can be disabled.
     *
     * @return void
     */
    public function test_it_allows_both_modes_disabled(): void
    {
        $attribute = new EncryptedSearch(exact: false, prefix: false);

        $this->assertFalse($attribute->exact);
        $this->assertFalse($attribute->prefix);
    }

    /**
     * Test that toArray() returns correct structure.
     *
     * @return void
     */
    public function test_to_array_returns_correct_structure(): void
    {
        $attribute = new EncryptedSearch(exact: true, prefix: true);
        $array = $attribute->toArray();

        $this->assertIsArray($array);
        $this->assertArrayHasKey('exact', $array);
        $this->assertArrayHasKey('prefix', $array);
        $this->assertTrue($array['exact']);
        $this->assertTrue($array['prefix']);
    }

    /**
     * Test that toArray() reflects current values.
     *
     * @return void
     */
    public function test_to_array_reflects_values(): void
    {
        $attribute = new EncryptedSearch(exact: false, prefix: true);
        $array = $attribute->toArray();

        $this->assertFalse($array['exact']);
        $this->assertTrue($array['prefix']);
    }
}
