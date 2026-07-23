<?php
// backend/database/migrations/2026_07_23_000400_create_business_days_table.php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            create table business_days (
              id                  uuid primary key default uuidv7(),
              location_id         uuid not null references locations(id),
              business_date       date not null,
              closed_by           uuid not null references users(id),
              closed_at           timestamptz not null default now(),

              gross_sales_cents   bigint not null,
              refunds_cents       bigint not null,
              net_sales_cents     bigint not null,
              tax_cents           bigint not null,
              expected_cash_cents bigint not null,
              counted_cash_cents  bigint not null,
              variance_cents      bigint not null,
              shift_count         int    not null,

              deposit_cents       bigint not null default 0 check (deposit_cents >= 0),
              checklist           jsonb  not null,
              note                text,

              reopened_at         timestamptz,
              reopened_by         uuid references users(id),

              check ((reopened_at is null) = (reopened_by is null)),
              unique (location_id, business_date)
            );
        SQL);
    }

    public function down(): void
    {
        DB::statement('drop table if exists business_days');
    }
};
