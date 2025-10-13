# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a Laravel package that enables privacy-preserving encrypted search functionality for Eloquent models. It allows searching encrypted data using deterministic hashed tokens without exposing plaintext values.

**Core Concept**: When sensitive data is encrypted (e.g., using Laravel's `encrypted` cast), it becomes unsearchable. This package solves that by maintaining a separate search index of SHA-256 hashed tokens that can be queried without compromising security.

## Architecture

### Token Generation Flow

1. **Normalization** (`src/Support/Normalizer.php`): Text is normalized (lowercased, diacritics removed)
2. **Token Generation** (`src/Support/Tokens.php`): SHA-256 hashes are created with a secret pepper
   - **Exact tokens**: Hash of full normalized value (for exact match queries)
   - **Prefix tokens**: Multiple hashes for progressive prefixes (for "starts with" queries)
3. **Storage**: Tokens stored in either:
   - Database table `encrypted_search_index` (default)
   - Elasticsearch index (when enabled)

### Key Components

**Trait: `HasEncryptedSearchIndex`** (`src/Traits/HasEncryptedSearchIndex.php`)
- Applied to Eloquent models to enable encrypted search
- Hooks into model lifecycle events (created, updated, deleted, restored)
- Provides query scopes: `encryptedExact()` and `encryptedPrefix()`
- Configuration resolution priority: 1) `$encryptedSearch` property, 2) PHP attributes, 3) auto-detected casts

**Service Provider** (`src/EncryptedSearchServiceProvider.php`)
- Registers package configuration and migrations
- Registers Artisan command `encryption:index-rebuild`
- Attaches global observer for Eloquent events

**Elasticsearch Integration** (`src/Services/ElasticsearchService.php`)
- Lightweight HTTP-based wrapper around Elasticsearch REST API
- Used when `ENCRYPTED_SEARCH_ELASTIC_ENABLED=true`
- Stores tokens in ES instead of database for horizontal scalability

**Model Configuration**
Models can specify searchable fields via:
```php
// Method 1: Property array
protected array $encryptedSearch = [
    'first_names' => ['exact' => true, 'prefix' => true],
    'last_names'  => ['exact' => true, 'prefix' => true],
];

// Method 2: PHP Attributes (overrides property)
#[EncryptedSearch(exact: true, prefix: true)]
public string $last_names;

// Method 3: Auto-detection (enabled by default)
// Any field with 'encrypted' cast is automatically indexed for exact search
```

### Database Structure

The `encrypted_search_index` table stores:
- `model_type`: Fully qualified model class name
- `model_id`: Primary key of the model
- `field`: Name of the encrypted field
- `type`: Either 'exact' or 'prefix'
- `token`: SHA-256 hash (64-char hex string)

## Common Commands

### Testing
```bash
# Run all tests
vendor/bin/phpunit

# Run with detailed output
vendor/bin/phpunit --testdox --colors=always

# Run single test
vendor/bin/phpunit --filter EncryptedSearchIntegrationTest
```

### Development Setup
```bash
# Install dependencies
composer install

# Run tests for specific Laravel version
composer require "illuminate/support:^11.0" "orchestra/testbench:^9.0" --no-update
composer update
```

### Index Management
```bash
# Rebuild index for a model
php artisan encryption:index-rebuild "App\\Models\\Client"

# Short form (auto-resolves to App\Models namespace)
php artisan encryption:index-rebuild Client

# Process in smaller chunks (default is 100)
php artisan encryption:index-rebuild Client --chunk=50
```

## Configuration

Located in `config/encrypted-search.php`:

- `search_pepper`: Secret value added to all hashes (CRITICAL: must be in `.env`)
- `max_prefix_depth`: Maximum prefix length for prefix tokens (default: 6)
- `auto_index_encrypted_casts`: Auto-detect and index fields with `encrypted` cast (default: true)
- `elasticsearch.enabled`: Use Elasticsearch instead of database (default: false)
- `elasticsearch.host`: ES connection URL
- `elasticsearch.index`: ES index name for tokens

## Testing Strategy

Tests use Orchestra Testbench to simulate a full Laravel environment with in-memory SQLite database. The test suite covers:
- Token generation (exact and prefix)
- Model lifecycle events (create, update, delete, restore)
- Query scopes (`encryptedExact`, `encryptedPrefix`)
- Configuration resolution (attributes, properties, auto-detection)

Test environment variables set in `phpunit.xml.dist`:
- `SEARCH_PEPPER=test-pepper-secret`
- `DB_CONNECTION=sqlite`
- `DB_DATABASE=:memory:`

## Multi-Version Compatibility

The package supports Laravel 9-12 and PHP 8.1-8.4. The CI matrix (`.github/workflows/tests.yml`) tests all combinations:
- Laravel 9 + PHP 8.1
- Laravel 10 + PHP 8.2
- Laravel 11 + PHP 8.3
- Laravel 12 + PHP 8.4

When making changes, ensure compatibility across all versions. The package uses only features available in Laravel 9+.

## Security Model

- **Tokens are deterministic**: Same input always produces same hash (required for searching)
- **Pepper prevents rainbow tables**: Even with token dump, plaintext cannot be recovered without pepper
- **Detached index**: Search tokens stored separately from encrypted data
- **No blind indexes**: Primary tables contain no searchable metadata
- **One-way hashing**: SHA-256 is cryptographically secure and irreversible

## Important Implementation Notes

1. **Elasticsearch Mode**: When enabled, database writes to `encrypted_search_index` are skipped entirely. The trait automatically routes to `ElasticsearchService` instead.

2. **Index Rebuild Command**: The command (`RebuildIndex`) supports short model names and auto-resolves them under `App\Models` namespace if not fully qualified.

3. **SoftDeletes Support**: The trait checks for `SoftDeletes` and hooks into `restored` and `forceDeleted` events appropriately.

4. **Query Scopes**: Both `encryptedExact()` and `encryptedPrefix()` use subqueries with `whereIn()` for efficient database-level filtering. When Elasticsearch is enabled, these need to be modified to query ES instead (currently database-only).

5. **Normalization**: All text is normalized before hashing (see `Normalizer::normalize()`). This ensures consistent matching regardless of case or diacritics.

## Package Publishing

When publishing this package, ensure:
- Configuration published: `--tag=config`
- Migration published: `--tag=migrations`
- Migration filename includes timestamp for proper ordering

Installation flow:
```bash
composer require ginkelsoft/laravel-encrypted-search-index
php artisan vendor:publish --provider="Ginkelsoft\EncryptedSearch\EncryptedSearchServiceProvider" --tag=config
php artisan vendor:publish --provider="Ginkelsoft\EncryptedSearch\EncryptedSearchServiceProvider" --tag=migrations
php artisan migrate
```
