<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Staff. See docs/02-data-model.md.
 *
 * Replaces Laravel's stock users table wholesale rather than migrating away from it:
 * this is the shape from day one, and the framework default (bigint id, required unique
 * email, no PIN) is wrong in every dimension for a POS.
 *
 * Notably absent: a `role` column. Roles live in spatie/laravel-permission and are scoped
 * per location — see docs/05-rbac.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->text('name');

            // Nullable: a weekend cashier may never touch the back office. The check
            // below guarantees every user can still authenticate somehow.
            $table->text('email')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->text('password_hash')->nullable();

            // bcrypt. The authority: nothing authenticates without a Hash::check against
            // this. Never logged. Rate-limited per register — the keyspace is small, and
            // this is only acceptable because it comes from an enrolled device.
            $table->text('pin_hash')->nullable();

            /*
             * HMAC-SHA256(pin, APP_KEY). An *index*, not a credential.
             *
             * Why it exists: bcrypt is salted, so a PIN cannot be looked up — login would
             * have to Hash::check every candidate at the location. Measured at cost=12
             * that is 225ms each, so twenty staff means a 4.5-second login. Unshippable.
             * With this, login is one indexed query and a single bcrypt verify.
             *
             * Why it is safe: it is keyed. A database-only leak reveals nothing without
             * APP_KEY. And bcrypt alone was never the protection here — a 4-digit PIN is
             * 10,000 guesses, which is ~40 minutes offline at cost=12. The real defences
             * are device enrolment and rate limiting. pin_hash remains the authority, so
             * this is defence in depth rather than a replacement.
             *
             * Not unique: uniqueness is "no two staff sharing a location", which no
             * simple index can express. The check is in SetStaffPin — but it is now one
             * exact query rather than a bcrypt scan.
             */
            $table->text('pin_lookup')->nullable();

            /*
             * The one capability that is genuinely global, and therefore the one that
             * cannot be a spatie role.
             *
             * spatie's teams scope *assignments* per team: `roles.location_id = null`
             * makes a role DEFINITION shared across teams, but HasRoles::roles() still
             * does `wherePivot(location_id, currentTeam)`, so every assignment pins to
             * exactly one location. There is no assignment that spans locations.
             *
             * Forcing admin into that model means either one assignment per location —
             * where opening a store silently locks admins out until provisioned — or a
             * pivot row claiming "admin @ Downtown" that actually means "admin
             * everywhere", which is a lie in the data.
             *
             * So admin is a flag, granted via Gate::before (spatie's own documented
             * super-admin pattern). This is not the `role` column we deliberately don't
             * have: call sites still ask can('order.void'), and this only short-circuits
             * the gate. See docs/05-rbac.md.
             */
            $table->boolean('is_admin')->default(false);

            $table->boolean('is_active')->default(true);
            $table->rememberToken();
            $table->timestamps();

            $table->index('pin_lookup');
        });

        DB::statement('alter table users add constraint users_can_authenticate
            check (email is not null or pin_hash is not null)');

        // Case-insensitive, and only where an email exists.
        DB::statement('create unique index users_email_unique on users (lower(email))
            where email is not null');

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->text('email')->primary();
            $table->text('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
