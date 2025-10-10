<?php

namespace Ginkelsoft\EncryptedSearch\Contracts;

use Illuminate\Database\Eloquent\Model;

interface SearchDriver
{
    public function index(Model $model): void;
    public function delete(Model $model): void;
    public function search(string $field, string $term, string $mode = 'exact'): array;
}
