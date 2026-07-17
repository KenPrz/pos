<?php
// backend/tests/Feature/Http/IdempotencyScopeTest.php
declare(strict_types=1);

use App\Actions\Orders\AddLineInput;
use App\Actions\Orders\AddLineToOrder;
use App\Domain\Rbac\Roles;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ProductVariant;
use Illuminate\Support\Str;

/*
| The idempotency key hash folds in the request path (EnsureIdempotency), so a key
| minted once and reused across two different orders must execute twice, not collide —
| while replaying the *same* key against the *same* order must still short-circuit.
| M4 triage item: these two invariants were previously untested together.
*/

beforeEach(function (): void {
    $this->location = provisionedLocation();
    $this->register = registerAt($this->location);
    $this->cashier = staffWithRole($this->location, Roles::CASHIER);

    // create() never hydrates the `version` column's DB default (see CLAUDE.md) — refresh
    // before reading it below.
    $this->orderA = Order::factory()->forRegister($this->register)->create(['opened_by' => $this->cashier->id])->refresh();
    $this->orderB = Order::factory()->forRegister($this->register)->create(['opened_by' => $this->cashier->id])->refresh();

    foreach ([$this->orderA, $this->orderB] as $order) {
        $variant = ProductVariant::factory()->untracked()->create(['price_cents' => 500, 'tax_rate_id' => null]);
        app(AddLineToOrder::class)->execute(new AddLineInput(
            orderId: $order->id, registerId: $this->register->id, variantId: $variant->id,
            qty: '1', expectedVersion: $order->version, actorId: $this->cashier->id,
        ));
    }

    $this->orderA->refresh();
    $this->orderB->refresh();
});

it('the same key text on two different orders collides and is rejected, never silently reused', function (): void {
    // docs/01-architecture.md ("Idempotency", handling case 3): idempotency_keys.key is
    // the sole primary key — global, not scoped by path. A key seen once with a request
    // whose hash differs (a different order → a different path → a different hash) is a
    // 409, by design: "the client has a bug, and guessing which one they meant is worse
    // than telling them." So reusing the same key text across order A and order B must
    // NOT execute twice and must NOT replay order A's response against order B — it
    // 409s, proving there is no cross-order collision hazard either way.
    $key = (string) Illuminate\Support\Str::uuid();
    $body = ['driver' => 'cash', 'amount_cents' => 500, 'tendered_cents' => 500];

    $this->postJson("/api/v1/orders/{$this->orderA->id}/payments", $body,
        staffHeaders($this->register, $this->cashier) + ['If-Match' => (string) $this->orderA->version, 'Idempotency-Key' => $key],
    )->assertCreated();

    $this->postJson("/api/v1/orders/{$this->orderB->id}/payments", $body,
        staffHeaders($this->register, $this->cashier) + ['If-Match' => (string) $this->orderB->version, 'Idempotency-Key' => $key],
    )->assertStatus(409)->assertJsonPath('error.code', 'idempotency_key_reused');

    expect(App\Models\Payment::count())->toBe(1);
});

it('replaying one of them still replays, not re-executes', function (): void {
    $key = (string) Str::uuid();
    $headers = staffHeaders($this->register, $this->cashier)
        + ['If-Match' => (string) $this->orderA->version, 'Idempotency-Key' => $key];
    $body = ['driver' => 'cash', 'amount_cents' => 500, 'tendered_cents' => 500];

    $first = $this->postJson("/api/v1/orders/{$this->orderA->id}/payments", $body, $headers);
    $first->assertCreated();

    $second = $this->postJson("/api/v1/orders/{$this->orderA->id}/payments", $body, $headers);
    $second->assertStatus(201);

    expect($second->json())->toEqual($first->json())
        ->and(Payment::count())->toBe(1);
});
