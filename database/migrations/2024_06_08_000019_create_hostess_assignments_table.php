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
        Schema::create('hostess_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('hostess_user_id');
            $table->uuid('event_id');
            $table->uuid('venue_id')->nullable();
            $table->uuid('checkpoint_id')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('hostess_user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('event_id')->references('id')->on('events')->cascadeOnDelete();
            $table->foreign('venue_id')->references('id')->on('venues')->cascadeOnDelete();
            $table->foreign('checkpoint_id')->references('id')->on('checkpoints')->cascadeOnDelete();

            $table->index(['tenant_id', 'event_id']);
            $table->index(['hostess_user_id', 'event_id', 'checkpoint_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hostess_assignments');
    }
};
