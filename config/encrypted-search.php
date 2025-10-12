<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Search Pepper
    |--------------------------------------------------------------------------
    | Een geheime “pepper” die wordt mee-gehasht met genormaliseerde tokens.
    | Dit voorkomt dat een gelekte index triviaal terug te rekenen is.
    | Zet dit in .env als SEARCH_PEPPER="random-string".
    */
    'search_pepper' => env('SEARCH_PEPPER', ''),

    /*
    |--------------------------------------------------------------------------
    | Prefix token lengte
    |--------------------------------------------------------------------------
    | Maximaal aantal prefix-niveaus voor prefix-zoekopdrachten.
    | Bijv. "wietse" -> ["w","wi","wie"]
    */
    'max_prefix_depth' => 6,

    /*
    |--------------------------------------------------------------------------
    | Auto-index encrypted casts
    |--------------------------------------------------------------------------
    |
    | When enabled, the package will automatically include any model attributes
    | that use Laravel's "encrypted" casts (e.g. AsEncryptedString, AsEncryptedArray)
    | in the encrypted search index.
    |
    | You can still override or fine-tune behavior via:
    | - Attributes: #[EncryptedSearch(exact: true, prefix: false)]
    | - The $encryptedSearch property on your model
    |
    */
    'auto_index_encrypted_casts' => true,

    'elasticsearch' => [
        'enabled' => env('ENCRYPTED_SEARCH_ELASTIC_ENABLED', false),
        'host'    => env('ELASTICSEARCH_HOST', 'http://elasticsearch:9200'),
        'index'   => env('ELASTICSEARCH_INDEX', 'encrypted_search'),
    ],
];
