<?php

namespace Ginkelsoft\EncryptedSearch\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Class SearchIndex
 *
 * Represents a single tokenized entry in the encrypted search index.
 *
 * Each record in this table corresponds to one searchable token derived from
 * a field of an Eloquent model that uses the `HasEncryptedSearchIndex` trait.
 * These tokens enable efficient searching over encrypted or normalized fields
 * without exposing the original plaintext data.
 *
 * Structure overview:
 * - model_type: Fully Qualified Class Name (FQCN) of the related Eloquent model.
 * - model_id:   Primary key of the model record.
 * - field:      Name of the model field from which the token was derived.
 * - type:       Type of token — "exact" for full-word matches, "prefix" for prefix matches.
 * - token:      The deterministic, non-reversible hash (e.g. SHA-256) used for lookups.
 *
 * Typical usage:
 * - Automatically maintained by the HasEncryptedSearchIndex trait.
 * - Used internally to resolve `encryptedExact()` and `encryptedPrefix()` scopes.
 *
 * Security notes:
 * - Tokens are irreversible and contain no plaintext information.
 * - The table can be safely indexed and queried without leaking sensitive data.
 */
class SearchIndex extends Model
{
    /**
     * The database table associated with the model.
     *
     * @var string
     */
    protected $table = 'encrypted_search_index';

    /**
     * The attributes that are mass assignable.
     *
     * @var string[]
     */
    protected $fillable = [
        'model_type',
        'model_id',
        'field',
        'type',
        'token',
    ];
}
