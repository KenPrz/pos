<?php

// backend/database/migrations/2026_07_17_000100_add_m5_columns.php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Raw SQL, matching the M2 migrations: the check constraint is the point.
        DB::statement("alter table registers add column mode text not null default 'retail'");
        DB::statement("alter table registers add constraint registers_mode_check check (mode in ('retail','food'))");

        // shifts.variance_approved_by/variance_approved_at were already forward-declared
        // as nullable columns in 2026_07_16_000300_create_shifts_tables — only the pairing
        // invariant is missing. Approval implies someone approved and when; half an
        // approval is unrepresentable.
        DB::statement('alter table shifts add constraint shifts_variance_approval_paired check ((variance_approved_by is null) = (variance_approved_at is null))');
    }

    public function down(): void
    {
        DB::statement('alter table shifts drop constraint shifts_variance_approval_paired');
        DB::statement('alter table registers drop constraint registers_mode_check');
        DB::statement('alter table registers drop column mode');
    }
};
