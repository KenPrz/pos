<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Cash accountability. See docs/02-data-model.md. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('register_id')->constrained('registers');
            $table->foreignUuid('opened_by')->constrained('users');
            $table->timestampTz('opened_at')->useCurrent();
            $table->bigInteger('opening_float_cents');

            $table->foreignUuid('closed_by')->nullable()->constrained('users');
            $table->timestampTz('closed_at')->nullable();
            $table->bigInteger('counted_cash_cents')->nullable();
            $table->bigInteger('expected_cash_cents')->nullable();
            $table->bigInteger('variance_cents')->nullable();
            $table->text('close_note')->nullable();
            $table->timestampTz('variance_approved_at')->nullable();
            $table->foreignUuid('variance_approved_by')->nullable()->constrained('users');
        });

        DB::statement('alter table shifts add constraint shifts_float_non_negative
            check (opening_float_cents >= 0)');

        // "Closed" and "counted" are inseparable at the schema level: you cannot close a
        // drawer without counting it.
        DB::statement('alter table shifts add constraint shifts_closed_implies_counted
            check ((closed_at is null) = (counted_cash_cents is null))');

        // The entire concurrency story for shifts. Two cashiers racing to open the same
        // register produce one winner and one constraint violation — no application
        // check, no lock. Prefer this shape wherever an invariant can be structural.
        DB::statement('create unique index one_open_shift_per_register
            on shifts (register_id) where closed_at is null');

        Schema::create('cash_movements', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('shift_id')->constrained('shifts');
            $table->text('kind');
            $table->bigInteger('amount_cents');

            // Mandatory: an unexplained drawer movement is the most common vector for
            // internal theft.
            $table->text('reason');
            $table->foreignUuid('user_id')->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement("alter table cash_movements add constraint cash_movements_kind
            check (kind in ('payout','paid_in','drop'))");

        // Always positive; `kind` carries the sign. A signed amount would let a typo turn
        // a payout into a paid-in.
        DB::statement('alter table cash_movements add constraint cash_movements_positive
            check (amount_cents > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('shifts');
    }
};
