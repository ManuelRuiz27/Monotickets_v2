<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE report_snapshots MODIFY COLUMN type ENUM('overview','attendance_by_hour','rsvp_funnel','checkpoint_totals','guests_by_list') NOT NULL");
        try {
            DB::statement("ALTER TABLE report_snapshots ADD COLUMN params_hash VARCHAR(64) AFTER params_json");
        } catch (\Throwable $exception) {
            // Column already exists.
        }

        try {
            DB::statement("ALTER TABLE report_snapshots DROP INDEX report_snapshots_tenant_id_event_id_type_index");
        } catch (\Throwable $exception) {
            // Index already dropped.
        }

        try {
            DB::statement("ALTER TABLE report_snapshots ADD INDEX report_snapshots_tenant_event_type_hash_index (tenant_id, event_id, type, params_hash)");
        } catch (\Throwable $exception) {
            // Index already exists.
        }
    }

    public function down(): void
    {
        try {
            DB::statement("ALTER TABLE report_snapshots DROP INDEX report_snapshots_tenant_event_type_hash_index");
        } catch (\Throwable $exception) {
            // Index already removed.
        }

        try {
            DB::statement("ALTER TABLE report_snapshots DROP COLUMN params_hash");
        } catch (\Throwable $exception) {
            // Column already removed.
        }

        DB::statement("ALTER TABLE report_snapshots MODIFY COLUMN type ENUM('attendance_by_hour','rsvp_funnel','checkpoint_totals','guests_by_list') NOT NULL");
        try {
            DB::statement("ALTER TABLE report_snapshots ADD INDEX report_snapshots_tenant_id_event_id_type_index (tenant_id, event_id, type)");
        } catch (\Throwable $exception) {
            // Index already exists.
        }
    }
};
