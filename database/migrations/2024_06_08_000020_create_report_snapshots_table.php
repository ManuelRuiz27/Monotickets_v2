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
        Schema::create('report_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->ulid('tenant_id');
            $table->uuid('event_id');
            $table->enum('type', [
                'overview',
                'attendance_by_hour',
                'rsvp_funnel',
                'checkpoint_totals',
                'guests_by_list',
            ]);
            $table->json('params_json')->nullable();
            $table->string('params_hash', 64);
            $table->json('result_json')->nullable();
            $table->timestamp('computed_at')->nullable();
            $table->unsignedInteger('ttl_seconds')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'event_id', 'type', 'params_hash']);

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('event_id')->references('id')->on('events')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('report_snapshots');
    }
};
