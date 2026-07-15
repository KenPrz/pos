<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Payments and refunds. See docs/02-data-model.md. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('order_id')->constrained('orders');

            // On the payment, not just the order: cash is counted per shift, and an order
            // spanning a shift boundary must attribute each tender to the shift that
            // physically received it. This is what makes drawer variance computable.
            $table->foreignUuid('shift_id')->constrained('shifts');

            $table->text('driver');                      // 'cash', 'external_card'
            $table->text('status');

            // Immutable once written. To correct a payment, void it and write a new row.
            $table->bigInteger('amount_cents');

            $table->bigInteger('tendered_cents')->nullable();   // cash only
            $table->bigInteger('change_cents')->nullable();     // cash only

            $table->text('reference')->nullable();       // external terminal reference
            $table->jsonb('driver_payload')->nullable();

            $table->foreignUuid('user_id')->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('captured_at')->nullable();

            $table->index('order_id');
        });

        DB::statement("alter table payments add constraint payments_status
            check (status in ('pending','authorized','captured','voided','failed'))");
        DB::statement('alter table payments add constraint payments_amount_positive
            check (amount_cents > 0)');

        // applied + change = tendered, enforced by the schema rather than by trust.
        // See App\Domain\Money\Tender.
        DB::statement('alter table payments add constraint payments_change_balances
            check (
                (tendered_cents is null and change_cents is null)
                or
                (tendered_cents is not null and change_cents is not null
                 and change_cents >= 0
                 and tendered_cents = amount_cents + change_cents)
            )');

        DB::statement("create index payments_shift on payments (shift_id) where status = 'captured'");

        /*
         * A refund is new rows, never a mutation. The original order is untouched and
         * stays `closed` forever; reporting reads orders - refunds, never a mutated order,
         * because there isn't one.
         */
        Schema::create('refunds', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('original_order_id')->constrained('orders');
            $table->foreignUuid('location_id')->constrained('locations');
            $table->foreignUuid('register_id')->constrained('registers');
            $table->foreignUuid('shift_id')->constrained('shifts');
            $table->date('business_date');
            $table->text('driver');
            $table->bigInteger('amount_cents');
            $table->text('reason');
            $table->foreignUuid('user_id')->constrained('users');
            $table->timestampTz('created_at')->useCurrent();

            $table->index('original_order_id');
            $table->index(['location_id', 'business_date']);
        });

        DB::statement('alter table refunds add constraint refunds_amount_positive
            check (amount_cents > 0)');

        Schema::create('refund_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('refund_id')->constrained('refunds')->cascadeOnDelete();
            $table->foreignUuid('original_order_line_id')->constrained('order_lines');
            $table->decimal('qty', 12, 3);
            $table->bigInteger('amount_cents');

            // Defaults true, but a returned melted ice cream goes in the bin, not back on
            // the shelf. When declined, no stock_movement is written.
            $table->boolean('restock')->default(true);

            $table->index('original_order_line_id');
        });

        DB::statement('alter table refund_lines add constraint refund_lines_qty_positive
            check (qty > 0)');
        DB::statement('alter table refund_lines add constraint refund_lines_amount_positive
            check (amount_cents > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_lines');
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('payments');
    }
};
