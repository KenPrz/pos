<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Idempotency and audit. See docs/01-architecture.md and docs/02-data-model.md. */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Non-negotiable even though v1 is online-only. The failure it prevents: a
         * cashier taps "Charge $50", the response is lost to a flaky network, the client
         * retries, and the customer is charged twice.
         *
         * This table is also precisely the mechanism an offline write-queue would replay
         * through — which is why it is here now.
         */
        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->text('key')->primary();
            $table->text('request_hash');            // sha256(method + path + body)
            $table->integer('response_code');
            $table->jsonb('response_body');
            $table->timestampTz('created_at')->useCurrent();

            $table->index('created_at');             // pruning
        });

        /*
         * Never deleted, no cascade. Everything a supervisor role gates writes a row
         * here — that list of actions and this table are the same design viewed from two
         * sides. See docs/05-rbac.md.
         */
        Schema::create('audit_log', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('user_id')->nullable();
            $table->uuid('register_id')->nullable();
            $table->text('action');                  // 'order.void', 'discount.apply', ...
            $table->text('entity_type');
            $table->uuid('entity_id')->nullable();
            $table->jsonb('payload')->nullable();
            $table->ipAddress('ip')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['entity_type', 'entity_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log');
        Schema::dropIfExists('idempotency_keys');
    }
};
