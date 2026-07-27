<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "A refund's method is at the refund's location", in the schema rather than in trust.
 *
 * `payment_methods.group_id` already carries the same shape of guarantee — a composite FK
 * onto `(id, location_id)` — and `docs/02-data-model.md` uses it as the worked example of
 * pushing an invariant into Postgres. `refunds` could not do it when 000200 added the
 * columns, because `payment_method_id` was still nullable there and the writer did not
 * exist yet; it can now.
 *
 * Nothing is wrong today: `RefundOrder` resolves the method against the acting register's
 * location, so no row can violate this. That is exactly why it is safe to add, and why it
 * is defence-in-depth rather than a fix — it closes the path where some future writer
 * forgets that rule.
 *
 * `payments` gets no equivalent: it has no `location_id` column of its own (its location
 * is reached through `orders`), so there is no composite to build.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Postgres requires a unique constraint on exactly the referenced columns, and
        // 000100 only built one for payment_method_GROUPS (id, location_id) — the methods
        // table never needed its own until now. Trivially unique, since `id` is already
        // the primary key; it exists solely to hang the FK below off.
        DB::statement('create unique index payment_methods_id_location
            on payment_methods (id, location_id)');

        DB::statement('alter table refunds
            add constraint refunds_payment_method_same_location
            foreign key (payment_method_id, location_id)
            references payment_methods (id, location_id)');
    }

    public function down(): void
    {
        DB::statement('alter table refunds drop constraint refunds_payment_method_same_location');
        DB::statement('drop index payment_methods_id_location');
    }
};
