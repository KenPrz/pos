<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // The activation code is stored only as a keyed HMAC (see ActivationCodes.php);
        // the plaintext is shown to the admin exactly once and never persisted.
        DB::statement('alter table registers add column activation_code_lookup text');
        DB::statement('alter table registers add column activation_code_expires_at timestamptz');
        DB::statement('alter table registers add column activation_code_redeemed_at timestamptz');
        DB::statement('alter table registers add constraint registers_activation_code_lookup_unique unique (activation_code_lookup)');
    }

    public function down(): void
    {
        DB::statement('alter table registers drop constraint registers_activation_code_lookup_unique');
        DB::statement('alter table registers drop column activation_code_redeemed_at');
        DB::statement('alter table registers drop column activation_code_expires_at');
        DB::statement('alter table registers drop column activation_code_lookup');
    }
};
