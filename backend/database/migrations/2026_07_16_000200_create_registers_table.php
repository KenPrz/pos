<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Terminals. A physical station, not a person. See docs/02-data-model.md.
 *
 * A register's binding to a location is what makes per-location RBAC free: the device
 * token identifies the register, so the team context is never client-supplied.
 * See docs/05-rbac.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registers', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('location_id')->constrained('locations');
            $table->text('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['location_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registers');
    }
};
