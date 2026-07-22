<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table locations add column variance_approval_threshold_cents integer');
        DB::statement('alter table locations add column low_stock_threshold numeric(12,3)');
        DB::statement('alter table locations add constraint locations_variance_threshold_positive check (variance_approval_threshold_cents is null or variance_approval_threshold_cents >= 0)');
        DB::statement('alter table locations add constraint locations_low_stock_threshold_positive check (low_stock_threshold is null or low_stock_threshold >= 0)');
    }

    public function down(): void
    {
        DB::statement('alter table locations drop constraint locations_low_stock_threshold_positive');
        DB::statement('alter table locations drop constraint locations_variance_threshold_positive');
        DB::statement('alter table locations drop column low_stock_threshold');
        DB::statement('alter table locations drop column variance_approval_threshold_cents');
    }
};
