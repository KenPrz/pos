# Backend Conventions

Laravel 13 / PHP 8.5. These are rules, not suggestions — the value of this pattern is
entirely in its uniformity. One system action looks exactly like every other one, so
there is nothing to learn twice.

## The shape

**One system action = one route = one single-action controller = one Action class.**

```
Route  →  Controller (__invoke)  →  FormRequest   validate + authorize + map to Input
                                 →  Action        execute the work, return a domain object
                                 →  Resource      serialize to the standard envelope
```

The controller is the whole HTTP layer, and it is three lines:

```php
final class AddLineController
{
    public function __invoke(AddLineRequest $request, AddLineToOrder $action): OrderResource
    {
        return new OrderResource($action->execute($request->toInput()));
    }
}
```

```php
Route::post('/orders/{order}/lines', AddLineController::class)
    ->middleware(['device', 'staff', 'idempotent']);
```

If a controller ever grows a fourth line, the logic belongs in the action.

## The rules

1. **An Action never touches HTTP.** No `Request`, no `Response`, no `abort()`, no status
   codes. It takes an Input DTO and returns a domain object or `void`.
2. **An Action never returns a Resource.** Serialization is the controller's job.
3. **An Action owns its transaction boundary.** The action *is* the unit of business
   work, so it is also the unit of atomicity.
4. **An Action is named as an imperative verb phrase.** `AddLineToOrder`, `TakePayment`,
   `CloseShift`. Not `OrderService`, not `LineCreator`, not `OrderManager`.
5. **A FormRequest validates, authorizes, and maps.** Nothing else.
6. **Models stay thin.** Casts, relations, scopes. No business logic.

Rule 1 is the one that pays. Because an action has no HTTP dependency, the same
`TakePayment` is callable from a controller, an Artisan command, a seeder, a future queued
job, and a test — with no HTTP kernel booted and no route hit. That is also why actions
take an Input DTO rather than the FormRequest itself: passing the request in would drag
HTTP into the domain and quietly cost us every one of those call sites.

## Layering

| Layer | Lives in | Does | Example |
| --- | --- | --- | --- |
| **Action** | `app/Actions/` | Orchestrates one system action. Owns the transaction. | `AddLineToOrder` |
| **Service** | `app/Domain/` | Stateless collaborator shared by several actions. | `PriceResolver`, `StockLedger`, `OrderTotals` |
| **Value object** | `app/Domain/Money/` | Pure math, no I/O. | `Money`, `Quantity`, `TaxRate` |
| **Model** | `app/Models/` | Persistence. Casts, relations, scopes. | `Order` |

Deciding where code goes:

- Pure math with no I/O → **value object**. (All of M1 in `06-roadmap.md` is this.)
- Needed by two or more actions → **service**.
- Otherwise → keep it in the **action**.

**Actions orchestrate; they do not nest deeply.** Prefer an action calling services over
an action calling actions. Action→action is allowed only when the inner one is a genuine
standalone system action whose side effects (audit entry, events) you actually want.
Since `DB::transaction()` nests as a savepoint, a composed action correctly joins the
caller's transaction rather than opening a second one — but a graph of actions calling
actions is how this pattern rots into the service-layer tangle it exists to avoid.

## Input DTOs

```php
final readonly class AddLineInput
{
    public function __construct(
        public string $orderId,
        public string $variantId,
        public string $qty,            // numeric string; never float — see 03-api.md
        public array  $modifierIds,
        public int    $expectedVersion,
        public string $actorId,
    ) {}
}
```

Mapping lives on the FormRequest, next to the rules that guarantee it's safe:

```php
final class AddLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->staff()->can('order.line.add');
    }

    public function rules(): array
    {
        return [
            'variant_id'  => ['required', 'uuid'],
            'qty'         => ['required', 'string', 'regex:/^\d+(\.\d{1,3})?$/'],
            'modifiers'   => ['array'],
            'modifiers.*' => ['uuid'],
        ];
    }

    public function toInput(): AddLineInput
    {
        return new AddLineInput(
            orderId:         $this->route('order'),
            variantId:       $this->string('variant_id')->toString(),
            qty:             $this->string('qty')->toString(),
            modifierIds:     $this->collect('modifiers')->all(),
            expectedVersion: (int) $this->header('If-Match'),
            actorId:         $this->staff()->id,
        );
    }
}
```

`qty` is validated as a **string** matching a 3-decimal pattern, not `numeric`. Laravel's
`numeric` rule would let the value become a PHP float on the way to a `numeric(12,3)`
column, which is exactly the precision loss `03-api.md` sends quantities as strings to
avoid.

## Two subtleties this pattern must get right

These are the places where "put it in the FormRequest" is the obvious move and the
wrong one.

### The version check cannot live in the FormRequest

`03-api.md` requires `If-Match: <version>` on order mutations. It is tempting to validate
the version in `rules()` — and it would be a time-of-check-to-time-of-use race. The
FormRequest runs *before* the transaction opens, so between the check passing and the
write landing, another register can bump the version.

The FormRequest may only confirm the header **is present and well-formed**. The actual
compare happens inside the action, inside the transaction, after the row is locked.

### Idempotency middleware wraps the action's transaction

`01-architecture.md` requires that the idempotency key and the work it guards commit
**together**, or not at all. Middleware ordinarily runs outside the transaction, so the
middleware has to open it:

```php
final class EnsureIdempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');
        if ($key === null) {
            return $next($request);
        }

        $hash = hash('sha256', $request->method().$request->path().$request->getContent());

        return DB::transaction(function () use ($key, $hash, $request, $next) {
            $seen = IdempotencyKey::whereKey($key)->lockForUpdate()->first();

            if ($seen !== null) {
                if (! hash_equals($seen->request_hash, $hash)) {
                    throw new IdempotencyKeyReused($key);   // 409
                }
                return response()->json($seen->response_body, $seen->response_code);
            }

            $response = $next($request);      // the action's DB::transaction() nests here

            if ($response->isSuccessful()) {
                IdempotencyKey::create([
                    'key'           => $key,
                    'request_hash'  => $hash,
                    'response_code' => $response->getStatusCode(),
                    'response_body' => json_decode($response->getContent(), true),
                ]);
            }

            return $response;
        });
    }
}
```

The action's own `DB::transaction()` becomes a savepoint inside this one, which is the
behaviour we want: one commit covers both the key and the money.

Two requirements fall out, and both need tests rather than trust:

- The middleware must sit where **exceptions propagate through it**, so a failed action
  rolls back and leaves no key — a retry must be allowed to succeed.
- Only `2xx` stores a key. A `409 insufficient_stock` must stay retryable, because the
  stock might arrive.

This is the "a replayed idempotency key charges once" test named in `01-architecture.md`.

## Errors

Actions throw domain exceptions. They do not know HTTP status codes, because rule 1.

```php
abstract class DomainException extends \RuntimeException
{
    abstract public function errorCode(): string;   // 'insufficient_stock'
    abstract public function httpStatus(): int;     // 409
    public function details(): array { return []; }
}
```

```php
final class InsufficientStock extends DomainException
{
    public function __construct(
        private readonly string $variantId,
        private readonly string $requested,
        private readonly string $available,
    ) {
        parent::__construct("Only {$available} units remain.");
    }

    public function errorCode(): string { return 'insufficient_stock'; }
    public function httpStatus(): int   { return 409; }

    public function details(): array
    {
        return [
            'variant_id' => $this->variantId,
            'requested'  => $this->requested,
            'available'  => $this->available,
        ];
    }
}
```

A single handler renders every `DomainException` into the envelope from `03-api.md`. One
place, so the shape cannot drift:

```php
$exceptions->render(function (DomainException $e) {
    return response()->json([
        'error' => [
            'code'    => $e->errorCode(),
            'message' => $e->getMessage(),
            'details' => $e->details(),
        ],
    ], $e->httpStatus());
});
```

Every `code` in the `03-api.md` error table is one class. The table and the
`app/Exceptions/Domain/` directory should be diffable against each other.

## Resources

Success is always `{"data": ...}`; errors are always `{"error": ...}`. Never both, never
a bare array.

```php
final class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'number'         => $this->number,
            'status'         => $this->status,
            'table_ref'      => $this->table_ref,
            'subtotal_cents' => $this->subtotal_cents,   // int, always
            'discount_cents' => $this->discount_cents,
            'tax_cents'      => $this->tax_cents,
            'total_cents'    => $this->total_cents,
            'paid_cents'     => $this->paid_cents,
            'version'        => $this->version,
            'lines'          => OrderLineResource::collection($this->whenLoaded('lines')),
        ];
    }
}
```

The casts that keep the wire format honest:

```php
protected function casts(): array
{
    return [
        'total_cents' => 'integer',   // bigint -> int; JSON number
        'qty'         => 'string',    // numeric(12,3) -> string; never float
    ];
}
```

**Every mutating order action returns `OrderResource`**, not just the changed line. Per
`03-api.md`, that's what makes client-side totals incapable of drifting.

Guard against N+1 by loading relations in the action's return (`$order->fresh([...])`) and
using `whenLoaded()` in the resource — never a lazy load inside serialization.

## Worked example

`AddLineToOrder` — the most demanding action in the system. It has to resolve a
location price, snapshot it, validate modifiers, lock and decrement stock, recompute
totals, and bump the version, all atomically.

```php
final class AddLineToOrder
{
    public function __construct(
        private readonly PriceResolver     $prices,
        private readonly ModifierValidator $modifiers,
        private readonly StockLedger       $stock,
        private readonly OrderTotals       $totals,
        private readonly AuditLogger       $audit,
    ) {}

    public function execute(AddLineInput $in): Order
    {
        return DB::transaction(function () use ($in) {
            // Teams scope permission checks, not record fetches — another location's
            // order must still be excluded by hand, or it's a 404 that leaked into a 200.
            $locationId = Register::findOrFail($in->registerId)->location_id;
            $order = Order::whereKey($in->orderId)
                ->where('location_id', $locationId)
                ->lockForUpdate()->firstOrFail();

            if ($order->status !== OrderStatus::Open) {
                throw new OrderClosed($order->id);
            }

            // Inside the transaction, after the lock. See "Two subtleties" above.
            if ($order->version !== $in->expectedVersion) {
                throw new OrderVersionConflict($order->id, $in->expectedVersion, $order->version);
            }

            $variant = ProductVariant::active()->whereKey($in->variantId)->firstOrFail();
            $price   = $this->prices->for($variant, $order->location_id);
            $mods    = $this->modifiers->resolve($variant, $in->modifierIds);  // throws on min/max

            $line = $order->lines()->create([
                'variant_id'            => $variant->id,
                'name_snapshot'         => $variant->displayName(),
                'sku_snapshot'          => $variant->sku,
                'unit_price_cents'      => $price->cents(),
                'tax_rate_micros'       => $variant->taxRate->rate_micros,
                'qty'                   => $in->qty,
                'modifiers_total_cents' => $mods->totalCents(),
            ]);

            $line->modifiers()->createMany($mods->toSnapshots());

            if ($variant->track_inventory) {
                $this->stock->sell($variant, $order->location_id, $in->qty, ref: $line);
            }

            $this->totals->recalculate($order);       // writes subtotal/tax/total
            $order->increment('version');

            $this->audit->record('order.line.add', $line, $in->actorId);

            return $order->fresh(['lines.modifiers']);
        });
    }
}
```

Why it's shaped this way:

- **`lockForUpdate()` on the order and a version check.** They solve different problems
  and we need both. The row lock serializes concurrent writers. The version check rejects
  a *stale client* — one that read v7 and is acting on information that v8 invalidated.
  With only the lock, two registers editing the same tab would both succeed and the
  second would silently clobber the first.
- **`StockLedger::sell()` is a service, not an action.** It's shared by `AddLineToOrder`,
  `RefundOrder`, `VoidOrder`, and `AdjustStock`. It owns the `SELECT … FOR UPDATE` from
  `02-data-model.md` and writes both the movement and the level.
- **Snapshots are written here, in the action.** `02-data-model.md` demands receipts be
  reproducible forever; this is the only moment the live catalog is consulted.
- **The transaction wraps everything**, so a modifier validation failure cannot leave
  stock decremented for a line that doesn't exist.

## Layout

```
app/
  Actions/
    Orders/      OpenOrder, AddLineToOrder, UpdateLineQty, VoidLine,
                 ApplyDiscount, VoidOrder, ReopenOrder
    Payments/    TakePayment, VoidPayment
    Refunds/     RefundOrder
    Shifts/      OpenShift, CloseShift, RecordCashMovement, ApproveVariance
    Catalog/     UpsertProduct, UpsertVariant, ...
    Auth/        EnrollRegister, StaffLogin, StaffLogout
  Domain/
    Money/       Money, Quantity, TaxRate, Allocator
    Pricing/     PriceResolver, OrderTotals, DiscountResolver
    Stock/       StockLedger
    Payments/    PaymentDriver, DriverRegistry, CashDriver, ExternalCardDriver
    Audit/       AuditLogger
  Exceptions/
    Domain/      DomainException + one class per code in 03-api.md
  Http/
    Controllers/ one __invoke class per action
    Requests/    one per action
    Resources/   OrderResource, OrderLineResource, ShiftResource, ...
    Middleware/  EnsureDeviceToken, EnsureStaffSession, EnsureIdempotency
  Models/
```

`app/Actions/` mirrors `03-api.md` almost line for line. That's intentional: the endpoint
list and the action list are the same list, so an endpoint with no action (or vice versa)
is a visible bug.

Payment drivers are **services injected into `TakePayment`**, resolved from
`DriverRegistry` by `code`. A driver is not an action — it's a collaborator that
`TakePayment` orchestrates. Adding Stripe Terminal later therefore adds a class to
`Domain/Payments/` and touches no action, no controller, and no route.

## Configuration

### The rule

**Config is what engineers change and deploy. The database is what admins change at
runtime.**

Ask one question: *does someone need to change this without a deploy?* If yes, it's a
row. If no, it's config. Nothing goes in both — a setting with two homes has two values,
and the one you're reading is the wrong one.

The trap this avoids is the settings table that grows to hold engineering knobs, at which
point tuning a rate limit means a production `UPDATE` with no code review, no history, and
no way to test the change before it's live.

### Where each setting lives

| Setting | Home | Why |
| --- | --- | --- |
| Currency | `config/pos.php` | Fixed at setup. Changing it isn't a setting change, it's a data migration. |
| Business name/address on receipts | `config/pos.php` | Changes roughly never; a deploy is fine. |
| Staff session TTL, PIN attempts, lockout | `config/pos.php` | Security knobs. Should go through review. |
| Idempotency key TTL | `config/pos.php` | Engineering detail; no admin has an opinion. |
| Variance approval threshold | `config/pos.php` | Policy, engineer-tuned in v1. See below. |
| Order number format | `config/pos.php` | Structural. |
| Rate limits | `config/pos.php` | Engineering. |
| Timezone | `locations` | Per-location; set when a store opens. |
| `prices_include_tax` | `locations` | Per-location; a US and a UK store differ. |
| Receipt header/footer copy | `locations` | Marketing edits it on a Tuesday. |
| Tax rates | `tax_rates` | Admin-editable, FK'd from variants, has history. |
| Discounts | `discounts` | Admin-editable. |
| Roles → permissions | Seeder | Code. See `05-rbac.md`. |

The variance threshold is the interesting one, because it's genuinely borderline — it's a
business policy, and a district manager might reasonably want to tune it. It's config in
v1 because there is exactly one number and nobody has asked. **The trigger to promote it to
a `locations` column is the first request to differ it per store.** Until then, a column
would be a settings framework serving one integer.

### `config/pos.php`

```php
return [
    'currency' => env('POS_CURRENCY', 'USD'),   // ISO-4217; minor units assumed 2

    'business' => [
        'name'    => env('POS_BUSINESS_NAME'),
        'address' => env('POS_BUSINESS_ADDRESS'),
        'tax_id'  => env('POS_BUSINESS_TAX_ID'),
    ],

    'staff' => [
        'session_ttl_minutes' => 480,   // 8h; ends at shift close regardless
        'pin_max_attempts'    => 5,
        'pin_lockout_seconds' => 60,
    ],

    'idempotency' => [
        'ttl_hours' => 24,              // pruning window; see 01-architecture.md
    ],

    'shifts' => [
        'variance_approval_threshold_cents' => 500,
    ],

    'orders' => [
        'number_format' => '{location}-{date}-{seq}',   // DT-20260715-0042
    ],

    'rate_limits' => [
        'pin_per_minute'      => 5,
        'catalog_per_minute'  => 10,
        'default_per_minute'  => 300,
    ],
];
```

### Rules

- **Never call `env()` outside a config file.** `php artisan config:cache` in production
  makes every `env()` call elsewhere return `null` — silently. A `null` currency or a
  `null` variance threshold fails in ways that look like data corruption, not
  misconfiguration. This is the single most common Laravel production footgun and it costs
  nothing to avoid.
- **Config is read at the edge, not in the domain.** A value object takes the threshold as
  a constructor argument; it does not call `config()`. Otherwise every unit test needs a
  booted container to test integer arithmetic, and M1 in `06-roadmap.md` stops being pure.
- **Money in config is `_cents`, integers**, same as everywhere else (`01-architecture.md`).
- **Fail fast on boot.** A missing `POS_CURRENCY` should stop the app starting, not
  surface as a wrong receipt at lunchtime.

### Environment

```dotenv
APP_ENV=production
APP_KEY=
APP_URL=https://pos.example.com

DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=pos
DB_USERNAME=pos
DB_PASSWORD=

POS_CURRENCY=USD
POS_BUSINESS_NAME="Example Trading Co"
POS_BUSINESS_ADDRESS="1 Example St"
POS_BUSINESS_TAX_ID=
```

Secrets live only in the environment. `infra/` ships a `.env.example` with every key
present and every secret blank, so a missing variable is a diff rather than a discovery.

### No global settings table

There is deliberately no `settings` key-value table in v1. It's the natural home for
business-level values, and it's also how config discipline dies — untyped, untested,
unversioned, and edited in production.

**Trigger to add one:** an admin needs to change a business-level value (not a
per-location one — that's a `locations` column) without a deploy. Realistically that's
receipt branding. Until then, `config/pos.php` is typed, diffable, reviewable, and
deployed with the code that reads it.

## Testing

The pattern's real payoff:

- **Actions are tested directly.** Construct the input, call `execute()`, assert on the
  database. No HTTP, no routes, no serialization. This is where the majority of tests
  live, and every invariant in `01-architecture.md` is an action test.
- **Controllers get one thin smoke test each** — right status, right envelope. There is
  no logic in them to test.
- **Value objects are tested exhaustively** and cost nothing to run.
- **Real Postgres**, never SQLite (`01-architecture.md`) — `lockForUpdate()` and the
  partial indexes are the whole point, and SQLite silently ignores both.

Concurrency tests need two real connections to prove the locking works. A single-process
test will pass whether or not `lockForUpdate()` is even there, which makes it worse than
no test — it's a green check mark asserting nothing.
