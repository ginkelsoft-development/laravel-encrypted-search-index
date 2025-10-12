<?php

/**
 * -----------------------------------------------------------------------------
 * Ginkelsoft Laravel Encrypted Search Index - Configuration
 * -----------------------------------------------------------------------------
 *
 * This configuration file defines how the encrypted search indexing system
 * behaves. It supports both local database indexing and Elasticsearch as
 * a backend. It also provides automatic detection of encrypted model casts,
 * customizable prefix depth, and peppering for secure token hashing.
 *
 * @package   Ginkelsoft\EncryptedSearch
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Search Pepper
    |--------------------------------------------------------------------------
    |
    | A secret “pepper” value that is concatenated with the normalized text
    | before hashing. This ensures that even if the token index is leaked,
    | it cannot be reversed or correlated with plaintext values.
    |
    | Define this in your `.env` file, for example:
    |   SEARCH_PEPPER="random-secret-string"
    |
    */
    'search_pepper' => env('SEARCH_PEPPER', ''),

    /*
    |--------------------------------------------------------------------------
    | Maximum Prefix Depth
    |--------------------------------------------------------------------------
    |
    | The maximum number of prefix levels to generate for prefix-based search.
    | For example, the term “wietse” would generate:
    |   ["w", "wi", "wie", "wiet", "wiets", "wietse"]
    |
    | Increasing this value improves search precision for short terms, but
    | slightly increases the number of stored tokens per record.
    |
    */
    'max_prefix_depth' => 6,

    /*
    |--------------------------------------------------------------------------
    | Automatic Indexing of Encrypted Casts
    |--------------------------------------------------------------------------
    |
    | When enabled, the package automatically includes any model attributes
    | that use Laravel’s encrypted cast types (e.g. AsEncryptedString,
    | AsEncryptedArrayObject, etc.) in the search index.
    |
    | You can still override or refine behavior per field via:
    | - Attributes: #[EncryptedSearch(exact: true, prefix: false)]
    | - Model property: protected array $encryptedSearch
    |
    */
    'auto_index_encrypted_casts' => true,

    /*
    |--------------------------------------------------------------------------
    | Elasticsearch Integration
    |--------------------------------------------------------------------------
    |
    | Configure Elasticsearch as the backend for storing and querying
    | encrypted search tokens. When enabled, the package will skip all
    | database writes to `encrypted_search_index` and instead sync tokens
    | directly to Elasticsearch using the internal ElasticsearchService.
    |
    | Example environment configuration:
    |   ENCRYPTED_SEARCH_ELASTIC_ENABLED=true
    |   ELASTICSEARCH_HOST=http://localhost:9200
    |   ELASTICSEARCH_INDEX=encrypted_search
    |
    */
    'elasticsearch' => [
        'enabled' => env('ENCRYPTED_SEARCH_ELASTIC_ENABLED', false),
        'host'    => env('ELASTICSEARCH_HOST', 'http://elasticsearch:9200'),
        'index'   => env('ELASTICSEARCH_INDEX', 'encrypted_search'),
    ],
];
