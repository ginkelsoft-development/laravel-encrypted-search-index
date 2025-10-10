<?php

namespace Ginkelsoft\EncryptedSearch\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use Ginkelsoft\EncryptedSearch\Traits\HasEncryptedSearchIndex;

/**
 * Class Client
 *
 * Minimal Eloquent model used for integration testing of
 * the Encrypted Search Index package.
 *
 * @package Ginkelsoft\EncryptedSearch\Tests\Models
 */
class Client extends Model
{
    use HasEncryptedSearchIndex;

    protected $table = 'clients';
    protected $fillable = ['first_names', 'last_names'];

    /**
     * Define which fields should be indexed for encrypted search.
     *
     * @return array<string, array<string,bool>>
     */
    public function getEncryptedSearchFields(): array
    {
        return [
            'first_names' => ['exact' => true, 'prefix' => true],
            'last_names'  => ['exact' => true, 'prefix' => true],
        ];
    }
}
