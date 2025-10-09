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

---

## Key Features

* **Searchable encryption** — Enables exact and prefix-based searches over encrypted data.
* **Detached search index** — Tokens are stored separately from the main data, reducing exposure risk.
* **Deterministic hashing with peppering** — Each token is derived from normalized text combined with a secret pepper.
* **No blind indexes in primary tables** — Encrypted fields remain opaque; only hashed references are stored elsewhere.
* **High scalability** — Efficient for millions of records through database indexing.
* **Laravel-native integration** — Works directly with Eloquent models, query scopes, and model events.

---

## How It Works

Each model can declare specific fields as searchable. When the model is saved, the system normalizes the field value, generates one or more hashed tokens, and stores them in a separate table named `encrypted_search_index`.

When you search, the package hashes your input using the same process and retrieves matching model IDs from the index.

### 1. Token Generation

For each configured field:

* **Exact match token:** A SHA-256 hash of the normalized value + secret pepper.
* **Prefix tokens:** Multiple SHA-256 hashes representing progressive prefixes of the normalized text (e.g. `w`, `wi`, `wie`).

### 2. Token Storage

All tokens are stored in `encrypted_search_index`:

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

These use indexed lookups and remain performant even at scale.

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
php artisan vendor:publish --tag=config
php artisan migrate
```

Then add a unique pepper to your `.env` file:

```
SEARCH_PEPPER=your-random-secret-string
```

---

## Configuration

`config/encrypted-search.php`:

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

When a record is saved, searchable tokens are automatically generated in `encrypted_search_index`.

### Searching

```php
// Exact match
$clients = Client::encryptedExact('last_names', 'Vermeer')->get();

// Prefix match
$clients = Client::encryptedPrefix('first_names', 'Wie')->get();
```

### Rebuilding the Index

Rebuild indexes via Artisan:

```bash
php artisan encryption:index-rebuild "App\\Models\\Client"
```

---

## Scalability and Performance

* **Indexed database lookups** for efficient token search.
* **Chunked rebuilds** for large datasets (`--chunk` option).
* **Queue-compatible** for asynchronous index rebuilds.

The detached index structure scales linearly and supports millions of records efficiently.

---

## Framework Compatibility

| Laravel Version | PHP Version(s) Supported |
| --------------- | ------------------------ |
| 8.x             | 8.0 – 8.1                |
| 9.x             | 8.1 – 8.2                |
| 10.x            | 8.1 – 8.3                |
| 11.x            | 8.2 – 8.3                |
| 12.x            | 8.3+                     |

The package is continuously tested across all supported combinations using GitHub Actions.

---

## Continuous Integration

This repository includes automated testing for all Laravel 8–12 releases.
Each test matrix validates functionality on PHP 8.1, 8.2, and 8.3.

Example badge (replace `USERNAME/REPOSITORY` with yours):

```
![Tests](https://github.com/USERNAME/REPOSITORY/actions/workflows/tests.yml/badge.svg)
```

---

## Compliance

* **GDPR** — Encrypted and hashed separation ensures minimal data exposure.
* **HIPAA** — Meets encryption-at-rest requirements for ePHI.
* **ISO 27001** — Aligns with confidentiality and cryptographic control standards.

---

## License

MIT License
(c) 2025 Ginkelsoft
