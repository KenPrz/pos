<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keyed runtime settings. Config is what engineers deploy; this table is what admins
 * change at runtime — see docs/04-backend-conventions.md. Each row is one registry key
 * (App\Domain\Settings\Settings::REGISTRY) with its value as jsonb, so a string, bool,
 * or number can all live in the same column without a schema change per setting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->text('key')->primary();
            $table->jsonb('value');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
