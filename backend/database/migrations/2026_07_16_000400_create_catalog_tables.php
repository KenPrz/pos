<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Catalog. See docs/02-data-model.md. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->text('name');
            $table->uuid('parent_id')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        // The self-reference is added after the table exists: inside Schema::create,
        // Laravel emits the foreign key before the primary key constraint it points at.
        Schema::table('categories', function (Blueprint $table): void {
            $table->foreign('parent_id')->references('id')->on('categories')->nullOnDelete();
            $table->index('parent_id');
        });

        Schema::create('tax_rates', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->text('name');                        // 'Standard VAT', 'NYC Combined'

            // Millionths. 8.875% -> 88750. Basis points would be the obvious choice and
            // are wrong: NYC needs sub-basis-point precision. See App\Domain\Money\TaxRate.
            $table->bigInteger('rate_micros');

            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::statement('alter table tax_rates add constraint tax_rates_non_negative
            check (rate_micros >= 0)');

        Schema::create('products', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->text('name');
            $table->text('description')->nullable();
            $table->foreignUuid('category_id')->nullable()->constrained('categories');
            $table->text('kind')->default('goods');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::statement("alter table products add constraint products_kind
            check (kind in ('goods','service'))");

        Schema::create('product_variants', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();

            // 'Blue / L'; 'Default' when the product has no options. Every product has at
            // least one variant even when trivial — the sale path always resolves to a
            // variant, so there is never an "if the product has options" branch.
            $table->text('name');

            $table->text('sku');
            $table->text('barcode')->nullable();
            $table->bigInteger('price_cents');
            $table->bigInteger('cost_cents')->nullable();
            $table->foreignUuid('tax_rate_id')->nullable()->constrained('tax_rates');

            // False for services and open-ended items (a coffee you don't count).
            // Only tracked variants take the stock lock on sale.
            $table->boolean('track_inventory')->default(true);

            $table->integer('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        DB::statement('alter table product_variants add constraint variants_price_non_negative
            check (price_cents >= 0)');
        DB::statement('alter table product_variants add constraint variants_cost_non_negative
            check (cost_cents is null or cost_cents >= 0)');

        // Partial on deleted_at so a retired SKU's number can be reissued without the old
        // row blocking it.
        DB::statement('create unique index variants_sku_unique
            on product_variants (sku) where deleted_at is null');
        DB::statement('create unique index variants_barcode_unique
            on product_variants (barcode) where barcode is not null and deleted_at is null');

        // Override table, not a required column: the airport store charges more, the
        // other nine need no rows at all.
        Schema::create('variant_location_prices', function (Blueprint $table): void {
            $table->foreignUuid('variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->foreignUuid('location_id')->constrained('locations')->cascadeOnDelete();
            $table->bigInteger('price_cents');

            $table->primary(['variant_id', 'location_id']);
        });

        DB::statement('alter table variant_location_prices add constraint variant_location_prices_non_negative
            check (price_cents >= 0)');

        Schema::create('modifier_groups', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->text('name');                        // 'Milk', 'Cook temp'
            $table->integer('min_select')->default(0);   // 1 makes the group required
            $table->integer('max_select')->nullable();   // null = unlimited
            $table->timestamps();
        });

        DB::statement('alter table modifier_groups add constraint modifier_groups_select_range
            check (max_select is null or max_select >= min_select)');
        DB::statement('alter table modifier_groups add constraint modifier_groups_min_non_negative
            check (min_select >= 0)');

        Schema::create('modifiers', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('group_id')->constrained('modifier_groups')->cascadeOnDelete();
            $table->text('name');                        // 'Oat milk'

            // Signed, unlike cash movements: a discount modifier ('no cheese, -50c') is a
            // real thing and the sign is the meaning, not a typo risk.
            $table->bigInteger('price_delta_cents')->default(0);

            $table->integer('position')->default(0);
            $table->boolean('is_active')->default(true);
        });

        Schema::create('product_modifier_groups', function (Blueprint $table): void {
            $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignUuid('group_id')->constrained('modifier_groups')->cascadeOnDelete();
            $table->integer('position')->default(0);

            $table->primary(['product_id', 'group_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_modifier_groups');
        Schema::dropIfExists('modifiers');
        Schema::dropIfExists('modifier_groups');
        Schema::dropIfExists('variant_location_prices');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
        Schema::dropIfExists('tax_rates');
        Schema::dropIfExists('categories');
    }
};
