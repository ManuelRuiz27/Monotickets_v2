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
        Schema::create('imports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->ulid('tenant_id');
            $table->uuid('event_id');
            $table->enum('source', ['csv', 'xlsx', 'api']);
            $table->enum('status', ['uploaded', 'processing', 'completed', 'failed'])->default('uploaded');
            $table->unsignedInteger('rows_total')->default(0);
            $table->unsignedInteger('rows_ok')->default(0);
            $table->unsignedInteger('rows_failed')->default(0);
            $table->string('report_file_url')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id');
            $table->index('event_id');

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('event_id')->references('id')->on('events')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('imports');
    }
};
