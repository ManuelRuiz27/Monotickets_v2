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
        Schema::create('qrs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('ticket_id')->unique();
            $table->string('code')->unique();
            $table->unsignedInteger('version');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('ticket_id')->references('id')->on('tickets')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('qrs');
    }
};
