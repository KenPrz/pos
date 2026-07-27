<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-location tender taxonomy. The GROUP carries the driver — it is the behavioural
 * bucket — and its methods are admin-named variants that behave identically.
 * See docs/02-data-model.md (payment methods).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_method_groups', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('location_id')->constrained('locations');
            $table->text('code');                        // 'CASH','CARD','EWALLET' — immutable
            $table->text('name');                        // display copy — editable
            $table->text('driver');                      // the code seam: cash | external_card
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        DB::statement('create unique index payment_method_groups_code
            on payment_method_groups (location_id, code)');

        // Exists only so payment_methods can carry a composite FK onto (id, location_id).
        DB::statement('create unique index payment_method_groups_id_location
            on payment_method_groups (id, location_id)');

        DB::statement("alter table payment_method_groups add constraint payment_method_groups_driver
            check (driver in ('cash','external_card'))");

        Schema::create('payment_methods', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('location_id')->constrained('locations');
            $table->uuid('group_id');
            $table->text('code');                        // 'CASH','VISA','GCASH' — immutable
            $table->text('name');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->index('group_id');
        });

        // "A method's group is at the method's location", in the schema rather than in
        // trust — location_id is duplicated onto this table because the uniqueness rule
        // is per-location, and this is what keeps the duplicate honest.
        DB::statement('alter table payment_methods
            add constraint payment_methods_group_same_location
            foreign key (group_id, location_id)
            references payment_method_groups (id, location_id)');

        DB::statement('create unique index payment_methods_code
            on payment_methods (location_id, code)');

        // Every existing location gets the default set. Inlined as SQL on purpose: a
        // migration must not depend on a domain class that may change under it.
        // Mirrors App\Domain\Payments\PaymentMethodProvisioner::DEFAULTS.
        $defaults = [
            ['CASH', 'Cash', 'cash', 0, 'CASH', 'Cash'],
            ['CARD', 'Cards', 'external_card', 1, 'CARD', 'Card'],
        ];

        foreach (DB::table('locations')->pluck('id') as $locationId) {
            foreach ($defaults as [$groupCode, $groupName, $driver, $sort, $methodCode, $methodName]) {
                $groupId = DB::table('payment_method_groups')->insertGetId([
                    'location_id' => $locationId,
                    'code' => $groupCode,
                    'name' => $groupName,
                    'driver' => $driver,
                    'sort_order' => $sort,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ], 'id');

                DB::table('payment_methods')->insert([
                    'location_id' => $locationId,
                    'group_id' => $groupId,
                    'code' => $methodCode,
                    'name' => $methodName,
                    'sort_order' => 0,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('payment_method_groups');
    }
};
