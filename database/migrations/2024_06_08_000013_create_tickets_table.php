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
        Schema::create('tickets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('event_id');
            $table->uuid('guest_id');
            $table->enum('type', ['general', 'vip', 'staff'])->default('general');
            $table->integer('price_cents')->default(0);
            $table->enum('status', ['issued', 'revoked', 'used', 'expired'])->default('issued');
            $table->string('seat_section')->nullable();
            $table->string('seat_row')->nullable();
            $table->string('seat_code')->nullable();
            $table->timestamp('issued_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['event_id', 'guest_id']);
            $table->unique(['event_id', 'seat_section', 'seat_row', 'seat_code']);

            $table->foreign('event_id')->references('id')->on('events')->cascadeOnDelete();
            $table->foreign('guest_id')->references('id')->on('guests')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
