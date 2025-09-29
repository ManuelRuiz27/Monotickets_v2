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
        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->unsignedBigInteger('subtotal_cents');
            $table->unsignedBigInteger('tax_cents');
            $table->unsignedBigInteger('total_cents');
            $table->enum('status', ['pending', 'paid', 'void'])->default('pending');
            $table->timestamp('issued_at');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->json('line_items_json');
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'period_start', 'period_end']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
