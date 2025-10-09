# Ginkelsoft Laravel Encrypted Search Index

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

---\n

## Key Features

* **Searchable encryption**: Enables exact and prefix-based searches over encrypted data.
* **Detached search index**: Tokens are stored separately from the main data, reducing exposure risk.
* **Deterministic hashing with peppering**: Each token is derived from normalized text combined with a secret pepper, preventing reverse-engineering.
* **No blind indexes in primary tables**: Encrypted fields remain opaque—only hashed references are stored elsewhere.
* **High scalability**: Indexes can handle millions of records efficiently using native database indexes.
* **Laravel-native integration**: Fully compatible with Eloquent models, query scopes, and events.

---

## How It Works

Each model can declare specific fields as searchable. When the model is saved, a background process normalizes the field value, generates one or more hashed tokens, and stores them in a separate database table named `encrypted_search_index`.

When you search, the package hashes your input using the same process and retrieves matching model IDs from the index.

### 1. Token Generation

For each configured field:

* **Exact match token:** A SHA-256 hash of the normalized value plus a secret pepper.
* **Prefix tokens:** Multiple SHA-256 hashes representing progressive prefixes of the normalized text (e.g., `w`, `wi`, `wie`).

### 2. Token Storage

All tokens are stored in `encrypted_search_index` with the following structure:

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

These queries use database-level indexes for efficient lookups even on large datasets.

---

## Security Model

| Threat                  | Mitigation                                                                  |
| ----------------------- | --------------------------------------------------------------------------- |
| Database dump or breach | Tokens cannot be reversed to plaintext (salted and peppered SHA-256).       |
| Statistical analysis    | Tokens are fully detached; frequency analysis yields no useful correlation. |
| Insider access          | No sensitive data in the index table; encrypted fields remain opaque.       |
| Leaked `APP_KEY`        | Does not affect token security; the pepper is stored separately in `.env`.  |

The system follows a **defense-in-depth** approach: encrypted data remains fully protected, while token search provides limited, controlled visibility for queries.

---

## Installation

```bash
composer require ginkelsoft/laravel-encrypted-search-index
php artisan vendor:publish --tag=config
php artisan migrate
```

Update your `.env` file with a unique pepper:

```
SEARCH_PEPPER=your-random-secret-string
```

---

## Configuration

`config/encrypted-search.php`

```php
return [
    'search_pepper' => env('SEARCH_PEPPER', ''),
    'max_prefix_depth' => 6,
];
```

---

## Usage

### Model Setup

```php
use Illuminate\Database\Eloquent\Model;
use Ginkelsoft\EncryptedSearch\Traits\HasEncryptedSearchIndex;

class Client extends Model
{
    use HasEncryptedSearchIndex;

    protected array $encryptedSearch = [
        'first_names' => ['exact' => true, 'prefix' => true],
        'last_names'  => ['exact' => true, 'prefix' => true],
        'bsn'         => ['exact' => true],
    ];
}
```

When a `Client` record is saved, its searchable tokens are automatically created or updated in the `encrypted_search_index` table.

### Searching

```php
// Exact match search
$clients = Client::encryptedExact('last_names', 'Vermeer')->get();

// Prefix match search
$clients = Client::encryptedPrefix('first_names', 'Wie')->get();
```

### Rebuilding the Index

You can rebuild the entire search index using an Artisan command:

```bash
php artisan encryption:index-rebuild "App\\Models\\Client"
```

This will reprocess all searchable fields for the specified model.

---

## Scalability and Performance

* **Optimized database lookups**: The `encrypted_search_index` table uses compound indexes for fast token-based lookups.
* **Chunked rebuilds**: The `index-rebuild` command supports chunked processing to handle large datasets efficiently.
* **Asynchronous rebuilds**: Can be safely run in queues or background jobs.

Unlike in-memory search systems, this index-based approach scales linearly with the size of your dataset and can efficiently handle millions of records.

---

## Compliance

This approach aligns with major privacy and compliance frameworks:

* GDPR: Minimal data exposure; encrypted and hashed data separation.
* HIPAA: Ensures ePHI remains protected even in breach scenarios.
* ISO 27001: Supports layered security controls for data confidentiality.

---

## License

MIT License
(c) 2025 Ginkelsoft
