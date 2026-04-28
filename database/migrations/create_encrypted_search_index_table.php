<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CreateEncryptedSearchIndexTable
 *
 * This migration defines the `encrypted_search_index` table, which stores
 * normalized and hashed tokens to enable secure searching over encrypted data.
 *
 * Each record in this table represents a single searchable token for a given
 * model instance and field. For example, if a "Client" model has an encrypted
 * `last_name`, its normalized and tokenized forms (exact or prefix) will be
 * stored here. These tokens can be matched without revealing the original value.
 *
 * The combination of `model_type`, `model_id`, `field`, and `type` uniquely
 * identifies all searchable tokens for a specific model field.
 *
 * Indexing strategy:
 * - `esi_lookup` provides efficient token-based searches.
 * - `esi_row` accelerates lookup and cleanup operations per model instance.
 *
 * Typical usage:
 * - Generated automatically by the HasEncryptedSearchIndex trait.
 * - Used for both exact and prefix token searches.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the `encrypted_search_index` table with tokenized fields for
     * secure search functionality. Tokens are generated as deterministic
     * hashes (e.g., SHA-256) and are not reversible, ensuring no sensitive
     * data is exposed while maintaining fast lookups.
     */
    public function up(): void
    {
        Schema::create('encrypted_search_index', function (Blueprint $table) {
            $table->id();

            // The fully qualified model class name (e.g. App\Models\Client)
            $table->string('model_type');

            // The primary key of the model this token belongs to (string to support ULIDs/UUIDs)
            $table->string('model_id', 36);

            // The model field (e.g. "last_names") from which this token was derived
            $table->string('field');

            // Token type: 'exact' or 'prefix'
            $table->string('type', 16);

            // The actual hashed token (e.g. SHA-256 or truncated prefix token)
            $table->string('token', 80);

            // Record timestamps for auditing and debugging
            $table->timestamps();

            // Composite indexes for optimized lookups
            $table->index(['model_type', 'field', 'type', 'token'], 'esi_lookup');
            $table->index(['model_type', 'model_id'], 'esi_row');
        });
    }

    /**
     * Reverse the migrations.
     *
     * Drops the `encrypted_search_index` table if it exists.
     */
    public function down(): void
    {
        Schema::dropIfExists('encrypted_search_index');
    }
};
