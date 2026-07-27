<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The other half of 2026_07_26_000200. That migration added these three columns to
 * `refunds` and backfilled them but left them nullable, because RefundOrder could not yet
 * write them. It can now, so the invariant becomes the schema's.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table refunds alter column payment_method_id set not null');
        DB::statement('alter table refunds alter column payment_method_code set not null');
        DB::statement('alter table refunds alter column payment_method_name set not null');
    }

    public function down(): void
    {
        DB::statement('alter table refunds alter column payment_method_id drop not null');
        DB::statement('alter table refunds alter column payment_method_code drop not null');
        DB::statement('alter table refunds alter column payment_method_name drop not null');
    }
};
