<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** See docs/02-data-model.md. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->text('name');
            $table->text('code')->unique();          // 'DT' — short, on receipts

            // Per-location, not global: two stores can straddle a timezone boundary, and
            // "today's sales" must mean the local day at that store.
            $table->text('timezone');

            // Per-location for the same reason a business can have a US and a UK store.
            $table->boolean('prices_include_tax')->default(false);

            $table->jsonb('address')->nullable();

            // Columns rather than config: marketing edits this copy and must not need a
            // deploy. The business name/address on the same receipt are config, because
            // they change roughly never. See docs/04-backend-conventions.md.
            $table->text('receipt_header')->nullable();
            $table->text('receipt_footer')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
