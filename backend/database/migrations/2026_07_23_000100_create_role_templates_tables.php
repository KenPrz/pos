<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Role templates: the runtime definition of a role. Spatie's per-location role rows
 * are materialized copies kept in sync by RoleProvisioner — a template is global,
 * assignment stays per-location. Client-visible, so uuid pk (spatie's own roles
 * table keeps its bigint id; it is reference data, never exposed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->text('name');
            $table->boolean('is_system')->default(false);
            $table->timestampsTz();
            $table->unique('name');
        });

        Schema::create('role_template_permissions', function (Blueprint $table): void {
            $table->uuid('role_template_id');
            $table->unsignedBigInteger('permission_id');
            $table->primary(['role_template_id', 'permission_id']);
            $table->foreign('role_template_id')->references('id')->on('role_templates')->cascadeOnDelete();
            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_template_permissions');
        Schema::dropIfExists('role_templates');
    }
};
