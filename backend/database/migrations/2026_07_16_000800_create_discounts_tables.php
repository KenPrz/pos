<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Discounts. See docs/02-data-model.md. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discounts', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->text('name');
            $table->text('kind');
            $table->bigInteger('percent_micros')->nullable();   // kind='percent'; 10% -> 100000
            $table->bigInteger('amount_cents')->nullable();      // kind='fixed'
            $table->text('scope');

            // Defaults true because discounts are how money leaves a till with a smile.
            $table->boolean('requires_supervisor')->default(true);

            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::statement("alter table discounts add constraint discounts_kind
            check (kind in ('percent','fixed'))");
        DB::statement("alter table discounts add constraint discounts_scope
            check (scope in ('order','line'))");

        // Makes a percent discount carrying a cash amount unrepresentable, rather than
        // merely discouraged.
        DB::statement("alter table discounts add constraint discounts_kind_matches_value
            check (
                (kind = 'percent' and percent_micros is not null and amount_cents is null)
                or
                (kind = 'fixed' and amount_cents is not null and percent_micros is null)
            )");
        DB::statement('alter table discounts add constraint discounts_values_non_negative
            check ((percent_micros is null or percent_micros >= 0)
               and (amount_cents is null or amount_cents >= 0))');

        Schema::create('order_discounts', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('order_id')->constrained('orders')->cascadeOnDelete();

            // null = order-level
            $table->foreignUuid('order_line_id')->nullable()->constrained('order_lines')->cascadeOnDelete();

            // null = ad-hoc
            $table->foreignUuid('discount_id')->nullable()->constrained('discounts');

            $table->text('name_snapshot');

            // The *resolved* cents, not the percentage. A 10% discount on an order that
            // later gains a line does not silently re-scale; it is recalculated and
            // rewritten explicitly, by code we can test.
            $table->bigInteger('amount_cents');

            $table->foreignUuid('applied_by')->constrained('users');
            $table->text('reason')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('order_id');
        });

        DB::statement('alter table order_discounts add constraint order_discounts_non_negative
            check (amount_cents >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('order_discounts');
        Schema::dropIfExists('discounts');
    }
};
