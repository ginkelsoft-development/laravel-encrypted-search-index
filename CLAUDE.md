# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What This Is

A Laravel package (`ginkelsoft/laravel-encrypted-search-index`) that enables searching on encrypted Eloquent model fields using deterministic hashed tokens (SHA-256 + pepper). It stores detached token indexes rather than blind indexes to avoid statistical pattern leakage.

## Commands

```bash
# Run all tests (SQLite in-memory, no external deps needed)
vendor/bin/phpunit --testdox --colors=always

# Run a single test file
vendor/bin/phpunit tests/Feature/EncryptedSearchIntegrationTest.php

# Run a single test method
vendor/bin/phpunit --filter test_method_name

# Rebuild search index (artisan command provided by the package)
php artisan encryption:index-rebuild "App\\Models\\ModelName" --chunk=100
```

No build step, no linter configured. CI runs tests across Laravel 9-12 / PHP 8.1-8.4 matrix using Orchestra Testbench.

## Architecture

### Core Flow

```
Model field value → Normalizer (lowercase, strip diacritics) → Tokens (SHA-256 + pepper)
    → stored in `encrypted_search_index` table (or Elasticsearch)
    → query scopes match tokens to find model IDs
```

### Key Components

- **`HasEncryptedSearchIndex` trait** (`src/Traits/`) — The main integration point. Added to Eloquent models. Provides query scopes (`encryptedExact`, `encryptedPrefix`, `encryptedExactMulti`, `encryptedPrefixMulti`, `encryptedSearchAny`, `encryptedSearchAll`), manages token generation/storage, handles model event syncing.
- **`Tokens`** (`src/Support/`) — Generates deterministic SHA-256 hashes. Exact tokens hash the full normalized value; prefix tokens hash each substring from length 1 to `max_prefix_depth`.
- **`Normalizer`** (`src/Support/`) — Text normalization using PHP `intl` extension (transliterator). Lowercases, removes diacritics, strips non-alphanumeric.
- **`SearchIndex` model** (`src/Models/`) — Eloquent model for the `encrypted_search_index` table.
- **`SearchIndexObserver`** (`src/Observers/`) — Global observer that auto-syncs tokens on model create/update/delete events.
- **`ElasticsearchService`** (`src/Services/`) — Alternative backend; same scopes work against Elasticsearch via Guzzle HTTP.
- **`SearchCacheService`** (`src/Services/`) — Optional caching layer with tag-based invalidation (Redis/Memcached).
- **`#[EncryptedSearch]` attribute** (`src/Attributes/`) — PHP 8 attribute for per-field config (`exact`, `prefix` booleans).

### Field Configuration Priority

1. Explicit `$encryptedSearch` array property on the model (highest)
2. `#[EncryptedSearch]` PHP attributes on properties
3. Auto-detected `encrypted` casts (if `auto_index_encrypted_casts` is enabled)

### Two Storage Backends

Database (default) and Elasticsearch — controlled via `encrypted-search.elasticsearch.enabled` config. The query scopes abstract over both backends transparently.

## Testing

- **Framework:** PHPUnit with Orchestra Testbench
- **Database:** SQLite in-memory (`:memory:`)
- **Test model:** `tests/Models/Client.php`
- **Feature tests** (`tests/Feature/`): integration, multi-field search, prefix length validation, batch queries, edge cases
- **Unit tests** (`tests/Unit/`): token generation, normalization, attributes, Elasticsearch service

Environment variables set in `phpunit.xml.dist`:
- `APP_KEY`, `SEARCH_PEPPER=test-pepper-secret`, `DB_CONNECTION=sqlite`

## Configuration

Published via `config/encrypted-search.php`. Key settings:
- `search_pepper` — **required**, sourced from `SEARCH_PEPPER` env var
- `max_prefix_depth` (default 6) — max prefix token length
- `min_prefix_length` (default 3) — minimum search term length for prefix queries
- `auto_index_encrypted_casts` (default true) — auto-index fields with `encrypted` cast

## Dependencies

Requires `ext-intl` (PHP intl extension). Supports Laravel 9-12, PHP 8.1-8.4.

## Code Standards

- All code in English (comments, variables, methods, docblocks)
- Follow Laravel naming conventions
- All public methods must have docblocks
- Models use `SoftDeletes` and `HasUlids` traits in consuming apps
