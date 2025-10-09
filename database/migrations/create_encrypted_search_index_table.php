<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('encrypted_search_index', function (Blueprint $table) {
            $table->id();
            $table->string('model_type');         // FQCN
            $table->unsignedBigInteger('model_id');
            $table->string('field');              // bv. 'last_names'
            $table->string('type', 16);           // 'exact' | 'prefix'
            $table->string('token', 80);          // sha256 hex (64) of korter
            $table->timestamps();

            $table->index(['model_type', 'field', 'type', 'token'], 'esi_lookup');
            $table->index(['model_type', 'model_id'], 'esi_row');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('encrypted_search_index');
    }
};
