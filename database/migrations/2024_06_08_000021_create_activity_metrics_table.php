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
        Schema::create('activity_metrics', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('event_id');
            $table->timestamp('date_hour');
            $table->unsignedInteger('invites_sent')->default(0);
            $table->unsignedInteger('rsvp_confirmed')->default(0);
            $table->unsignedInteger('scans_valid')->default(0);
            $table->unsignedInteger('scans_duplicate')->default(0);
            $table->unsignedInteger('unique_guests_in')->default(0);
            $table->timestamps();

            $table->unique(['event_id', 'date_hour']);
            $table->foreign('event_id')->references('id')->on('events')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_metrics');
    }
};
