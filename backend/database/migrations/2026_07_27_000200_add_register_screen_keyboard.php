<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Per-register on-screen keyboard, for sealed terminals and tablets with no physical
 * keyboard. Per REGISTER rather than per location because one store commonly mixes
 * hardware — a sealed counter terminal beside a back-office PC enrolled as a second till.
 *
 * Defaults false: a terminal with a keyboard is the common case, and defaulting true
 * would silently put a keyboard on every till already in service at migrate time.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table registers
            add column screen_keyboard_enabled boolean not null default false');
    }

    public function down(): void
    {
        DB::statement('alter table registers drop column screen_keyboard_enabled');
    }
};
