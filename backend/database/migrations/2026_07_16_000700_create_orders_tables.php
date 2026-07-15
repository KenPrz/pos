<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Orders — the unit of sale, for retail and food service alike. See docs/02-data-model.md. */
return new class extends Migration
{
    public function up(): void
    {
        // Human-facing numbering, per location per day. A Postgres sequence won't do:
        // sequences don't reset per day per location, and they leak gaps on rollback.
        // Gaps matter — an auditor reading a receipt book with holes in it asks
        // questions, and "the database rolled back" is not an enjoyable answer.
        Schema::create('order_counters', function (Blueprint $table): void {
            $table->foreignUuid('location_id')->constrained('locations');
            $table->date('business_date');
            $table->integer('next_val')->default(1);

            $table->primary(['location_id', 'business_date']);
        });

        Schema::create('orders', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->text('number');                      // 'DT-20260715-0042'
            $table->foreignUuid('location_id')->constrained('locations');
            $table->foreignUuid('register_id')->constrained('registers');
            $table->foreignUuid('shift_id')->constrained('shifts');

            // The local calendar day at the location. Stored rather than derived because
            // every report groups by it, and re-deriving a timezone conversion in every
            // query is both slow and a bug farm.
            $table->date('business_date');

            $table->foreignUuid('opened_by')->constrained('users');
            $table->foreignUuid('closed_by')->nullable()->constrained('users');
            $table->foreignUuid('customer_id')->nullable()->constrained('customers');

            // This nullable column *is* the entire retail/food-service split in the
            // schema. Everything else is shared. See docs/00-overview.md.
            $table->text('table_ref')->nullable();

            $table->text('status')->default('open');

            // Snapshotted at open, not read from `locations` at close: an admin flipping
            // that setting mid-shift must not retroactively change the arithmetic of
            // orders already in flight.
            $table->boolean('prices_include_tax');

            $table->bigInteger('subtotal_cents')->default(0);
            $table->bigInteger('discount_cents')->default(0);
            $table->bigInteger('tax_cents')->default(0);
            $table->bigInteger('total_cents')->default(0);
            $table->bigInteger('paid_cents')->default(0);

            // Optimistic lock. Rejects a stale client; the row lock handles concurrent
            // writers. Both are needed — see docs/04-backend-conventions.md.
            $table->integer('version')->default(0);

            $table->timestampTz('opened_at')->useCurrent();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampTz('voided_at')->nullable();
            $table->text('void_reason')->nullable();

            $table->unique(['location_id', 'number']);
            $table->index(['location_id', 'business_date']);
        });

        DB::statement("alter table orders add constraint orders_status
            check (status in ('open','closed','voided'))");

        // The tab list / floor view: the hottest query in food service.
        DB::statement("create index orders_open on orders (location_id, status) where status = 'open'");

        Schema::create('order_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignUuid('variant_id')->constrained('product_variants');

            /*
             * Frozen at add time. The most important thing on this table: rename a
             * product, reprice it, or change a tax rate, and last week's receipt must
             * still reprint byte-identical. The FK to variant_id stays for reporting
             * ("how many of this SKU did we sell") but is never the source of display or
             * price data.
             */
            $table->text('name_snapshot');
            $table->text('sku_snapshot');
            $table->bigInteger('unit_price_cents');
            $table->bigInteger('tax_rate_micros');

            $table->decimal('qty', 12, 3);
            $table->bigInteger('modifiers_total_cents')->default(0);
            $table->bigInteger('discount_cents')->default(0);
            $table->bigInteger('tax_cents')->default(0);
            $table->bigInteger('line_total_cents');

            // The KDS seam: nullable, unused in v1. One column now versus a migration
            // over a live orders table later.
            $table->text('prep_state')->nullable();

            $table->integer('position')->default(0);

            // Voided, never deleted: "what did the cashier remove from this order, and
            // when" is a fraud question.
            $table->timestampTz('voided_at')->nullable();
            $table->foreignUuid('voided_by')->nullable()->constrained('users');
            $table->timestampTz('created_at')->useCurrent();

            $table->index('order_id');
            $table->index('variant_id');
        });

        DB::statement('alter table order_lines add constraint order_lines_qty_positive
            check (qty > 0)');
        DB::statement("alter table order_lines add constraint order_lines_prep_state
            check (prep_state is null or prep_state in ('pending','in_progress','ready'))");

        Schema::create('order_line_modifiers', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('order_line_id')->constrained('order_lines')->cascadeOnDelete();
            $table->foreignUuid('modifier_id')->constrained('modifiers');
            $table->text('name_snapshot');
            $table->bigInteger('price_delta_cents');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_line_modifiers');
        Schema::dropIfExists('order_lines');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('order_counters');
    }
};
