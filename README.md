# Ginkelsoft Laravel Encrypted Search Index

[![Tests](https://github.com/ginkelsoft-development/laravel-encrypted-search-index/actions/workflows/tests.yml/badge.svg)](https://github.com/ginkelsoft-development/laravel-encrypted-search-index/actions/workflows/tests.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/ginkelsoft/laravel-encrypted-search-index.svg?style=flat-square)](https://packagist.org/packages/ginkelsoft/laravel-encrypted-search-index)
[![Total Downloads](https://img.shields.io/packagist/dt/ginkelsoft/laravel-encrypted-search-index.svg?style=flat-square)](https://packagist.org/packages/ginkelsoft/laravel-encrypted-search-index)
[![License](https://img.shields.io/github/license/ginkelsoft-development/laravel-encrypted-search-index.svg?style=flat-square)](LICENSE.md)
[![Laravel](https://img.shields.io/badge/Laravel-8--12-brightgreen?style=flat-square&logo=laravel)](https://laravel.com)
[![PHP](https://img.shields.io/badge/PHP-8.1%20--%208.4-blue?style=flat-square&logo=php)](https://php.net)
[![Elasticsearch](https://img.shields.io/badge/Search-DB%20or%20Elasticsearch-ff9900?style=flat-square&logo=elasticsearch)](#elasticsearch-integration)

## Overview

Modern applications that handle sensitive user data—such as healthcare, financial, or membership systems—must ensure that all personally identifiable information (PII) is properly encrypted at rest. However, standard encryption creates a practical challenge: **once data is encrypted, it can no longer be searched efficiently.**

Laravel's built-in `Crypt` system offers strong encryption (AES-256-CBC) but provides no mechanism for searching encrypted values. Some systems attempt to address this by storing partial plaintext or using blind indexes, which can leak statistical patterns and increase the risk of correlation attacks.

The **Laravel Encrypted Search Index** package provides a clean, secure, and scalable alternative. It allows encrypted model fields to be **searched using deterministic hashed tokens**, without ever exposing plaintext data.

---

## Problem Statement

### The traditional trade-off

When data is fully encrypted, you lose the ability to perform meaningful queries. Developers must choose between:

1. **Strong security (no search):** Encrypt every value with a random IV; searches become impossible.
2. **Weak security (searchable):** Store hashed or partially-encrypted values that can be compared, leaking patterns.

This package removes that trade-off by introducing a **detached searchable index** that maps encrypted records to deterministic tokens.

---

## Key Features

* **Searchable encryption** — Enables exact and prefix-based searches over encrypted data.
* **Detached search index** — Tokens are stored separately from the main data, reducing exposure risk.
* **Deterministic hashing with peppering** — Each token is derived from normalized text combined with a secret pepper.
* **No blind indexes in primary tables** — Encrypted fields remain opaque; only hashed references are stored elsewhere.
* **High scalability** — Efficient for millions of records through database indexing or Elasticsearch.
* **Elasticsearch integration** — Optionally store and query search tokens directly in an Elasticsearch index.
* **Laravel-native integration** — Works directly with Eloquent models, query scopes, and model events.
* **Automatic field detection** — Automatically indexes fields that use an encrypted cast when enabled.
* **Fine-grained configuration** — Supports attributes (`#[EncryptedSearch]`) and `$encryptedSearch` arrays for per-field behavior.
---

## How It Works

Each model can declare specific fields as searchable. When the model is saved, the system normalizes the field value, generates one or more hashed tokens, and stores them in a separate table named `encrypted_search_index` **or** in an Elasticsearch index if configured.

When you search, the package hashes your input using the same process and retrieves matching model IDs from the index.

### 1. Token Generation

For each configured field:

* **Exact match token:** A SHA-256 hash of the normalized value + secret pepper.
* **Prefix tokens:** Multiple SHA-256 hashes representing progressive prefixes of the normalized text (e.g. `w`, `wi`, `wie`).

### 2. Token Storage

By default, all tokens are stored in the database table `encrypted_search_index`. When Elasticsearch is enabled, they are stored in the configured Elasticsearch index instead.

Example structure:

| model_type        | model_id | field      | type   | token  |
| ----------------- | -------- | ---------- | ------ | ------ |
| App\Models\Client | 42       | last_names | exact  | [hash] |
| App\Models\Client | 42       | last_names | prefix | [hash] |

### 3. Querying

The package provides two Eloquent scopes:

```php
Client::encryptedExact('last_names', 'Vermeer')->get();
Client::encryptedPrefix('first_names', 'Wie')->get();
```

These use indexed lookups (DB or Elasticsearch) and remain performant even at scale.

---

## Elasticsearch Integration

### Enabling Elasticsearch

To enable Elasticsearch as the storage and query backend for encrypted tokens, set the following in your `.env` file:

```
ENCRYPTED_SEARCH_DRIVER=elasticsearch
ELASTICSEARCH_HOST=http://localhost:9200
ELASTICSEARCH_INDEX=encrypted_search
```

In `config/encrypted-search.php`:

```php
return [
    'search_pepper' => env('SEARCH_PEPPER', ''),
    'max_prefix_depth' => 6,

    'elasticsearch' => [
        'enabled' => env('ENCRYPTED_SEARCH_DRIVER', 'database') === 'elasticsearch',
        'host' => env('ELASTICSEARCH_HOST', 'http://localhost:9200'),
        'index' => env('ELASTICSEARCH_INDEX', 'encrypted_search'),
    ],
];
```

When enabled, the package will **skip database writes** to `encrypted_search_index` and instead sync tokens directly to Elasticsearch via the `ElasticsearchService`.

### Searching via Elasticsearch

To manually query Elasticsearch for a specific token:

```bash
curl -X GET "http://localhost:9200/encrypted_search/_search?pretty" \
-H 'Content-Type: application/json' \
-d '{
  "query": {
    "term": {
      "token.keyword": "<your-token-here>"
    }
  }
}'
```

Both the database and Elasticsearch drivers use the same search scopes —
your application code remains identical regardless of which backend is active.

For prefix-based queries, you can match multiple tokens:

```bash
curl -X GET "http://localhost:9200/encrypted_search/_search?pretty" \
-H 'Content-Type: application/json' \
-d '{
  "query": {
    "bool": {
      "should": [
        { "terms": { "token.keyword": ["token1", "token2", "token3"] } }
      ]
    }
  }
}'
```

The same token-based hashing rules apply — plaintext values must first be converted into deterministic tokens.

---

## Security Model

| Threat                  | Mitigation                                                        |
| ----------------------- | ----------------------------------------------------------------- |
| Database dump or breach | Tokens cannot be reversed (salted + peppered SHA-256).            |
| Statistical analysis    | Tokens are detached; frequency analysis yields no correlation.    |
| Insider access          | No sensitive data in index table; encrypted fields remain opaque. |
| Leaked `APP_KEY`        | Irrelevant for tokens; pepper is stored separately in `.env`.     |

This design follows a **defense-in-depth** model: encrypted data stays secure, while search operations remain practical.

---

## Installation

```bash
composer require ginkelsoft/laravel-encrypted-search-index
php artisan vendor:publish --provider="Ginkelsoft\EncryptedSearch\EncryptedSearchServiceProvider" --tag=config
php artisan vendor:publish --provider="Ginkelsoft\EncryptedSearch\EncryptedSearchServiceProvider" --tag=migrations
php artisan migrate
```

If you plan to use the Elasticsearch integration, make sure an Elasticsearch instance (version **8.x or newer**) is running and accessible at the host defined in your `.env` file.

Then add a unique pepper to your `.env` file:

```
SEARCH_PEPPER=your-random-secret-string
```

---

## Configuration

`config/encrypted-search.php`:

```php
return [
    // Secret pepper for token hashing
    'search_pepper' => env('SEARCH_PEPPER', ''),

    // Maximum prefix depth for token generation
    'max_prefix_depth' => 6,

    // Minimum prefix length for search queries (default: 3)
    'min_prefix_length' => env('ENCRYPTED_SEARCH_MIN_PREFIX', 3),

    // Automatic indexing of encrypted casts
    'auto_index_encrypted_casts' => true,

    // Elasticsearch integration
    'elasticsearch' => [
        'enabled' => env('ENCRYPTED_SEARCH_ELASTIC_ENABLED', false),
        'host' => env('ELASTICSEARCH_HOST', 'http://elasticsearch:9200'),
        'index' => env('ELASTICSEARCH_INDEX', 'encrypted_search'),
    ],

    // Debug logging
    'debug' => env('ENCRYPTED_SEARCH_DEBUG', false),
];
```

### Configuration Options

| Option | Default | Description |
|--------|---------|-------------|
| `search_pepper` | `''` | Secret pepper value for token hashing. **Required for security.** |
| `max_prefix_depth` | `6` | Maximum number of prefix characters to index (e.g., "wietse" → w, wi, wie, wiet, wiets, wietse) |
| `min_prefix_length` | `3` | Minimum search term length for prefix queries. Prevents overly broad matches from short terms like "w" or "de". |
| `auto_index_encrypted_casts` | `true` | Automatically index fields with `encrypted` cast types |
| `elasticsearch.enabled` | `false` | Use Elasticsearch instead of database for token storage |
| `elasticsearch.host` | `http://elasticsearch:9200` | Elasticsearch host URL |
| `elasticsearch.index` | `encrypted_search` | Elasticsearch index name |
| `debug` | `false` | Enable debug logging for index operations |

### Minimum Prefix Length

The `min_prefix_length` setting prevents performance issues and false positives from very short search terms.

**Example with `min_prefix_length = 3` (default):**

```php
// ❌ Returns no results (too short)
Client::encryptedPrefix('first_names', 'Wi')->get();

// ✅ Works normally (meets minimum)
Client::encryptedPrefix('first_names', 'Wil')->get();  // Finds "Wilma"

// ✅ Exact search always works (ignores minimum)
Client::encryptedExact('first_names', 'Wi')->get();
```

**Recommended values:**
- `1`: Allow single-character searches (more flexible, more false positives)
- `2`: Require two characters (good for short names)
- `3`: Require three characters (recommended - good balance)
- `4`: Require four characters (very precise, less flexible)

To adjust this setting, add to your `.env`:

```env
ENCRYPTED_SEARCH_MIN_PREFIX=3
```

---

## Usage

### Model Setup

If `auto_index_encrypted_casts` is enabled in the configuration (default: **true**),
all model fields that use an `encrypted:` cast will be automatically indexed for exact search,
even if they are not explicitly listed in `$encryptedSearch`.

You can also use PHP attributes to control search behavior per field:

```php
use Ginkelsoft\EncryptedSearch\Attributes\EncryptedSearch;

class Client extends Model
{
    #[EncryptedSearch(exact: true, prefix: true)]
    public string $last_names;
}

When a record is saved, searchable tokens are automatically generated in `encrypted_search_index` or synced to Elasticsearch.

### Searching

```php
// Exact match
$clients = Client::encryptedExact('last_names', 'Vermeer')->get();

// Prefix match
$clients = Client::encryptedPrefix('first_names', 'Wie')->get();
```
Attributes always override global or $encryptedSearch configuration for the same field.

---

#### ✅ 3. **Configuration block (insert this before `'elasticsearch' => [...]`)**
```php
'auto_index_encrypted_casts' => true,

## Rebuilding or Syncing the Search Index
This command automatically detects whether you are using the database or Elasticsearch driver,
and rebuilds the appropriate index accordingly.

Rebuild indexes via Artisan:

```bash
php artisan encryption:index-rebuild "App\\Models\\Client"
```

If Elasticsearch is enabled, this will repopulate the Elasticsearch index instead of the database.

---

## Scalability and Performance

* **Indexed database or Elasticsearch lookups** for efficient token search.
* **Chunked rebuilds** for large datasets (`--chunk` option).
* **Queue-compatible** for asynchronous index rebuilds.
* **Elasticsearch mode** scales horizontally for enterprise use.

The detached index structure scales linearly and supports millions of records efficiently.

---

## Framework Compatibility

| Laravel Version | Supported PHP Versions |
|-----------------|------------------------|
| **8.x**         | 8.0 – 8.1              |
| **9.x**         | 8.1 – 8.2              |
| **10.x**        | 8.1 – 8.3              |
| **11.x**        | 8.2 – 8.3              |
| **12.x**        | 8.3 and higher         |

The package is continuously tested across all supported combinations using GitHub Actions.

---

## Compliance

* **GDPR** — Encrypted and hashed separation ensures minimal data exposure.
* **HIPAA** — Meets encryption-at-rest requirements for ePHI.
* **ISO 27001** — Aligns with confidentiality and cryptographic control standards.

---

## Troubleshooting

**ConnectionException (cURL error 7)**  
Ensure your Elasticsearch container or service is running and reachable at the configured `ELASTICSEARCH_HOST`.

**Missing index mappings**  
If you haven’t created the Elasticsearch index yet, initialize it manually:
```bash
curl -X PUT http://localhost:9200/encrypted_search

## License

MIT License
(c) 2025 Ginkelsoft
