<?php

namespace Ginkelsoft\EncryptedSearch\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use Ginkelsoft\EncryptedSearch\Traits\HasEncryptedSearchIndex;

class Client extends Model
{
    use HasEncryptedSearchIndex;

    protected $fillable = ['first_names', 'last_names'];

    protected $casts = [
        'first_names' => 'encrypted',
        'last_names'  => 'encrypted',
    ];

    protected array $encryptedSearch = [
        'first_names' => ['exact' => true, 'prefix' => true],
        'last_names'  => ['exact' => true, 'prefix' => true],
    ];
}
