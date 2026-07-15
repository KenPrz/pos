<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * spatie/laravel-permission tables, with teams enabled and the team key mapped to
 * `location_id`. See docs/05-rbac.md.
 *
 * This is the published stub with four edits, all forced by our uuid primary keys. The
 * package assumes integer keys throughout; left unedited, each of these fails at insert
 * with an error that reads like a package bug rather than a schema mismatch:
 *
 *  1. roles.location_id                  unsignedBigInteger -> uuid  (locations.id is uuidv7)
 *  2. model_has_roles.location_id        unsignedBigInteger -> uuid
 *  3. model_has_permissions.location_id  unsignedBigInteger -> uuid
 *  4. model_has_*.model_id               unsignedBigInteger -> uuid  (users.id is uuidv7)
 *
 * Kept config-driven rather than hardcoded, because the package reads the same config at
 * runtime to build its queries — the two must not be able to drift.
 *
 * `roles` and `permissions` keep their bigint `id()`. Deliberate: they are seeded
 * reference data, never client-visible, never sorted by creation time. None of the
 * reasons we chose uuidv7 apply, and fighting the package would be cost with no benefit.
 *
 * The foreign keys are ours — the stub only creates indexes.
 */
return new class extends Migration
{
    public function up(): void
    {
        $teams = config('permission.teams');
        $tableNames = config('permission.table_names');
        $columnNames = config('permission.column_names');
        $pivotRole = $columnNames['role_pivot_key'] ?? 'role_id';
        $pivotPermission = $columnNames['permission_pivot_key'] ?? 'permission_id';

        throw_if(empty($tableNames), 'config/permission.php not loaded. Run [php artisan config:clear].');
        throw_if(! $teams, 'permission.teams must be true — roles are scoped per location. See docs/05-rbac.md.');
        throw_if(empty($columnNames['team_foreign_key'] ?? null), 'team_foreign_key not configured.');

        Schema::create($tableNames['permissions'], static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();

            $table->unique(['name', 'guard_name']);
        });

        Schema::create($tableNames['roles'], static function (Blueprint $table) use ($columnNames): void {
            $table->id();

            // EDIT 1: uuid, not unsignedBigInteger. Nullable — a null team means a global
            // role, which is how `admin` spans every location.
            $table->uuid($columnNames['team_foreign_key'])->nullable();
            $table->index($columnNames['team_foreign_key'], 'roles_team_foreign_key_index');

            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();

            $table->unique([$columnNames['team_foreign_key'], 'name', 'guard_name']);

            $table->foreign($columnNames['team_foreign_key'])
                ->references('id')->on('locations')->cascadeOnDelete();
        });

        Schema::create($tableNames['model_has_permissions'], static function (Blueprint $table) use ($tableNames, $columnNames, $pivotPermission): void {
            $table->unsignedBigInteger($pivotPermission);

            $table->string('model_type');
            $table->uuid($columnNames['model_morph_key']);   // EDIT 4
            $table->index([$columnNames['model_morph_key'], 'model_type'], 'model_has_permissions_model_id_model_type_index');

            $table->foreign($pivotPermission)
                ->references('id')->on($tableNames['permissions'])->cascadeOnDelete();

            $table->uuid($columnNames['team_foreign_key']);  // EDIT 3
            $table->index($columnNames['team_foreign_key'], 'model_has_permissions_team_foreign_key_index');

            $table->primary(
                [$columnNames['team_foreign_key'], $pivotPermission, $columnNames['model_morph_key'], 'model_type'],
                'model_has_permissions_permission_model_type_primary'
            );
        });

        Schema::create($tableNames['model_has_roles'], static function (Blueprint $table) use ($tableNames, $columnNames, $pivotRole): void {
            $table->unsignedBigInteger($pivotRole);

            $table->string('model_type');
            $table->uuid($columnNames['model_morph_key']);   // EDIT 4
            $table->index([$columnNames['model_morph_key'], 'model_type'], 'model_has_roles_model_id_model_type_index');

            $table->foreign($pivotRole)
                ->references('id')->on($tableNames['roles'])->cascadeOnDelete();

            $table->uuid($columnNames['team_foreign_key']);  // EDIT 2
            $table->index($columnNames['team_foreign_key'], 'model_has_roles_team_foreign_key_index');

            $table->primary(
                [$columnNames['team_foreign_key'], $pivotRole, $columnNames['model_morph_key'], 'model_type'],
                'model_has_roles_role_model_type_primary'
            );
        });

        Schema::create($tableNames['role_has_permissions'], static function (Blueprint $table) use ($tableNames, $pivotRole, $pivotPermission): void {
            $table->unsignedBigInteger($pivotPermission);
            $table->unsignedBigInteger($pivotRole);

            $table->foreign($pivotPermission)
                ->references('id')->on($tableNames['permissions'])->cascadeOnDelete();

            $table->foreign($pivotRole)
                ->references('id')->on($tableNames['roles'])->cascadeOnDelete();

            $table->primary([$pivotPermission, $pivotRole], 'role_has_permissions_permission_id_role_id_primary');
        });

        app('cache')
            ->store(config('permission.cache.store') !== 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
    }

    public function down(): void
    {
        $tableNames = config('permission.table_names');

        throw_if(empty($tableNames), 'config/permission.php not found.');

        Schema::dropIfExists($tableNames['role_has_permissions']);
        Schema::dropIfExists($tableNames['model_has_roles']);
        Schema::dropIfExists($tableNames['model_has_permissions']);
        Schema::dropIfExists($tableNames['roles']);
        Schema::dropIfExists($tableNames['permissions']);
    }
};
