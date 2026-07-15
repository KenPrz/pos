<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory: an immutable ledger, plus a cached level derived from it.
 *
 * Invariant: for every (variant_id, location_id),
 *     stock_levels.qty = sum(stock_movements.qty_delta)
 *
 * Both are written in the same transaction. The cache exists so the hot path (read a
 * level, lock it, sell) is one indexed row rather than an aggregate over a table that
 * grows forever. A nightly job re-derives and alarms on drift; if they disagree, the
 * ledger is right, always.
 *
 * This is why stock is a ledger and not a number: "why is my count wrong" is answerable
 * by selecting rows, and every movement names a reason, a user, and the order it came
 * from. See docs/02-data-model.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('variant_id')->constrained('product_variants');
            $table->foreignUuid('location_id')->constrained('locations');

            // numeric, not float: you can sell 0.5 kg of cheese.
            $table->decimal('qty_delta', 12, 3);

            $table->text('reason');
            $table->text('ref_type')->nullable();        // 'order_line', 'refund_line', ...
            $table->uuid('ref_id')->nullable();
            $table->foreignUuid('user_id')->nullable()->constrained('users');
            $table->text('note')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['variant_id', 'location_id', 'created_at']);
            $table->index(['ref_type', 'ref_id']);
        });

        // A movement of zero is not a movement.
        DB::statement('alter table stock_movements add constraint stock_movements_non_zero
            check (qty_delta <> 0)');

        DB::statement("alter table stock_movements add constraint stock_movements_reason
            check (reason in ('sale','refund','adjustment','receive','transfer_in','transfer_out','waste','count'))");

        Schema::create('stock_levels', function (Blueprint $table): void {
            $table->foreignUuid('variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->foreignUuid('location_id')->constrained('locations')->cascadeOnDelete();
            $table->decimal('qty', 12, 3)->default(0);
            $table->timestampTz('updated_at')->useCurrent();

            $table->primary(['variant_id', 'location_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_levels');
        Schema::dropIfExists('stock_movements');
    }
};
