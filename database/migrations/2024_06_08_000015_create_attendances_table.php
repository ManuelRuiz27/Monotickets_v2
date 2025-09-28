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
        Schema::create('attendances', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('event_id');
            $table->uuid('ticket_id');
            $table->uuid('guest_id');
            $table->uuid('checkpoint_id')->nullable();
            $table->ulid('hostess_user_id')->nullable();
            $table->enum('result', ['valid', 'duplicate', 'invalid', 'revoked', 'expired']);
            $table->timestamp('scanned_at');
            $table->string('device_id')->nullable();
            $table->boolean('offline')->default(false);
            $table->json('metadata_json')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['event_id', 'scanned_at']);
            $table->index(['ticket_id', 'scanned_at']);

            $table->foreign('event_id')->references('id')->on('events')->cascadeOnDelete();
            $table->foreign('ticket_id')->references('id')->on('tickets')->cascadeOnDelete();
            $table->foreign('guest_id')->references('id')->on('guests')->cascadeOnDelete();
            $table->foreign('checkpoint_id')->references('id')->on('checkpoints')->nullOnDelete();
            $table->foreign('hostess_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
