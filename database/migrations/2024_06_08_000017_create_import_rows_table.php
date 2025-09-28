<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('import_rows', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('import_id');
            $table->unsignedInteger('row_num');
            $table->json('data_json');
            $table->enum('status', ['ok', 'failed'])->default('ok');
            $table->text('error_msg')->nullable();
            $table->uuid('entity_id_created')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('import_id');

            $table->foreign('import_id')->references('id')->on('imports')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('import_rows');
    }
};
