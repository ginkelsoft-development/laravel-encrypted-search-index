<?php

namespace Ginkelsoft\EncryptedSearch\Models;

use Illuminate\Database\Eloquent\Model;

class SearchIndex extends Model
{
    protected $table = 'encrypted_search_index';

    protected $fillable = [
        'model_type', 'model_id', 'field', 'type', 'token',
    ];
}
