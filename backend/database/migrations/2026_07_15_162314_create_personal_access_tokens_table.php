<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sanctum tokens.
 *
 * One edit to the published stub, forced by our uuid primary keys: `uuidMorphs` instead
 * of `morphs`. The stub's tokenable_id is an unsignedBigInteger, but tokens here belong
 * to registers and users (docs/02-data-model.md) whose ids are uuidv7. Left alone,
 * enrolling a device fails at insert with an error that reads like a Sanctum bug rather
 * than a schema mismatch.
 *
 * `expires_at` is load-bearing here: device tokens are long-lived, staff tokens are not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->uuidMorphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
