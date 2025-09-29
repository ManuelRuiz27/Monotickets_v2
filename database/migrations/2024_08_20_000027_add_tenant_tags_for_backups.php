<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            if (! Schema::hasColumn('tickets', 'tenant_id')) {
                $table->ulid('tenant_id')->nullable()->after('event_id');
                $table->index('tenant_id');
            }
        });

        Schema::table('guests', function (Blueprint $table): void {
            if (! Schema::hasColumn('guests', 'tenant_id')) {
                $table->ulid('tenant_id')->nullable()->after('event_id');
                $table->index('tenant_id');
            }
        });

        Schema::table('attendances', function (Blueprint $table): void {
            if (! Schema::hasColumn('attendances', 'tenant_id')) {
                $table->ulid('tenant_id')->nullable()->after('event_id');
                $table->index('tenant_id');
            }
        });

        Schema::table('import_rows', function (Blueprint $table): void {
            if (! Schema::hasColumn('import_rows', 'tenant_id')) {
                $table->ulid('tenant_id')->nullable()->after('import_id');
                $table->index('tenant_id');
            }
        });

        DB::table('tickets as t')
            ->join('events as e', 't.event_id', '=', 'e.id')
            ->update(['t.tenant_id' => DB::raw('e.tenant_id')]);

        DB::table('guests as g')
            ->join('events as e', 'g.event_id', '=', 'e.id')
            ->update(['g.tenant_id' => DB::raw('e.tenant_id')]);

        DB::table('attendances as a')
            ->join('events as e', 'a.event_id', '=', 'e.id')
            ->update(['a.tenant_id' => DB::raw('e.tenant_id')]);

        DB::table('import_rows as r')
            ->join('imports as i', 'r.import_id', '=', 'i.id')
            ->update(['r.tenant_id' => DB::raw('i.tenant_id')]);

        Schema::table('tickets', function (Blueprint $table): void {
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        Schema::table('guests', function (Blueprint $table): void {
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        Schema::table('attendances', function (Blueprint $table): void {
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        Schema::table('import_rows', function (Blueprint $table): void {
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('import_rows', function (Blueprint $table): void {
            if (Schema::hasColumn('import_rows', 'tenant_id')) {
                $table->dropForeign(['tenant_id']);
                $table->dropColumn('tenant_id');
            }
        });

        Schema::table('attendances', function (Blueprint $table): void {
            if (Schema::hasColumn('attendances', 'tenant_id')) {
                $table->dropForeign(['tenant_id']);
                $table->dropColumn('tenant_id');
            }
        });

        Schema::table('guests', function (Blueprint $table): void {
            if (Schema::hasColumn('guests', 'tenant_id')) {
                $table->dropForeign(['tenant_id']);
                $table->dropColumn('tenant_id');
            }
        });

        Schema::table('tickets', function (Blueprint $table): void {
            if (Schema::hasColumn('tickets', 'tenant_id')) {
                $table->dropForeign(['tenant_id']);
                $table->dropColumn('tenant_id');
            }
        });
    }
};
