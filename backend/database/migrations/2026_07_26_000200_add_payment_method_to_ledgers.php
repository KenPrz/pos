<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every tender records the method it was taken on. Code and name are SNAPSHOTS — the
 * reason order lines snapshot price: renaming 'GCash' must not change what a receipt
 * printed last year says.
 *
 * `driver` stays on both tables as a DERIVED column, written from the resolved group.
 * That is what keeps ShiftTotals (which filters driver = 'cash' on payments AND refunds)
 * and the payments_change_balances check constraint untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['payments', 'refunds'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->uuid('payment_method_id')->nullable();
                $blueprint->text('payment_method_code')->nullable();
                $blueprint->text('payment_method_name')->nullable();
            });
        }

        // Deterministic backfill: at this point in the migration sequence the only
        // methods that exist are the CASH/CARD defaults 000100 provisioned, so mapping
        // the old driver onto its default code is exact, not a best guess.
        DB::statement("
            update payments p
               set payment_method_id   = pm.id,
                   payment_method_code = pm.code,
                   payment_method_name = pm.name
              from orders o, payment_methods pm
             where p.order_id = o.id
               and pm.location_id = o.location_id
               and pm.code = case p.driver when 'cash' then 'CASH' else 'CARD' end
        ");

        DB::statement("
            update refunds r
               set payment_method_id   = pm.id,
                   payment_method_code = pm.code,
                   payment_method_name = pm.name
              from payment_methods pm
             where pm.location_id = r.location_id
               and pm.code = case r.driver when 'cash' then 'CASH' else 'CARD' end
        ");

        foreach (['payments', 'refunds'] as $table) {
            DB::statement("alter table {$table} alter column payment_method_id set not null");
            DB::statement("alter table {$table} alter column payment_method_code set not null");
            DB::statement("alter table {$table} alter column payment_method_name set not null");
            DB::statement("alter table {$table}
                add constraint {$table}_payment_method_fk
                foreign key (payment_method_id) references payment_methods (id)");
            DB::statement("create index {$table}_payment_method on {$table} (payment_method_id)");
        }
    }

    public function down(): void
    {
        foreach (['payments', 'refunds'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn(['payment_method_id', 'payment_method_code', 'payment_method_name']);
            });
        }
    }
};
