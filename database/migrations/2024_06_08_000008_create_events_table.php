<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('organizer_user_id');
            $table->string('code');
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamp('start_at');
            $table->timestamp('end_at');
            $table->string('timezone');
            $table->enum('status', ['draft', 'published', 'archived'])->default('draft');
            $table->unsignedInteger('capacity')->nullable();
            $table->enum('checkin_policy', ['single', 'multiple'])->default('single');
            $table->json('settings_json')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'start_at']);

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('organizer_user_id')->references('id')->on('users')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE `events` ADD CONSTRAINT `events_start_before_end_check` CHECK (start_at < end_at)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('events')) {
            try {
                DB::statement('ALTER TABLE `events` DROP CHECK `events_start_before_end_check`');
            } catch (\Throwable $e) {
                // Constraint may not exist (older MySQL versions), ignore.
            }
        }
        Schema::dropIfExists('events');
    }
};
