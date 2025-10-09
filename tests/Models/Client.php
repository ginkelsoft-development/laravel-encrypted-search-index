<?php

namespace Tests\Models;

use Illuminate\Database\Eloquent\Model;
use Ginkelsoft\EncryptedSearch\Traits\HasEncryptedSearchIndex;

/**
 * Class Client
 *
 * A lightweight Eloquent model used exclusively for integration testing
 * of the Encrypted Search Index package.
 *
 * This model represents a typical entity that uses the
 * `HasEncryptedSearchIndex` trait to automatically maintain encrypted
 * search tokens for selected attributes.
 *
 * Attributes:
 * - `first_names`
 * - `last_names`
 *
 * Both fields are configured for exact and prefix-based search.
 * The configuration is applied dynamically within the constructor
 * to avoid property redefinition conflicts with the trait.
 *
 * Example:
 * ```php
 * $client = Client::create(['first_names' => 'John', 'last_names' => 'Doe']);
 * $results = Client::encryptedPrefix('first_names', 'Jo')->get();
 * ```
 *
 * @package Tests\Models
 * @see \Ginkelsoft\EncryptedSearch\Traits\HasEncryptedSearchIndex
 */
class Client extends Model
{
    use HasEncryptedSearchIndex;

    /**
     * The associated database table.
     *
     * @var string
     */
    protected $table = 'clients';

    /**
     * Mass assignable attributes.
     *
     * @var string[]
     */
    protected $fillable = ['first_names', 'last_names'];

    /**
     * Constructor override.
     *
     * This method applies the encrypted search field configuration dynamically
     * to prevent property collision with the trait’s default `$encryptedSearch`
     * definition.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        // Define encrypted search configuration at runtime.
        $this->encryptedSearch = [
            'first_names' => ['exact' => true, 'prefix' => true],
            'last_names'  => ['exact' => true, 'prefix' => true],
        ];
    }
}
