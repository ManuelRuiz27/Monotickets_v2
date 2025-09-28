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
        Schema::create('guests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('event_id');
            $table->uuid('guest_list_id')->nullable();
            $table->string('full_name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('organization')->nullable();
            $table->enum('rsvp_status', ['none', 'invited', 'confirmed', 'declined'])->default('none');
            $table->timestamp('rsvp_at')->nullable();
            $table->boolean('allow_plus_ones')->default(false);
            $table->unsignedInteger('plus_ones_limit')->default(0);
            $table->json('custom_fields_json')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['event_id', 'email']);
            $table->index('guest_list_id');

            $table->foreign('event_id')->references('id')->on('events')->cascadeOnDelete();
            $table->foreign('guest_list_id')->references('id')->on('guest_lists')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('guests');
    }
};
