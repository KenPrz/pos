/**
 * The server's ISO-4217 currency code (`config('pos.currency')` — see
 * `ReceiptResource`/`CatalogResource`). The register never hardcodes one: prices differ
 * by deployment (a Manila store prices in PHP, not USD), and the server is the only
 * thing that knows which.
 *
 * `'USD'` here is a pre-load placeholder only, for the brief window between first paint
 * and the catalog response landing — `api.catalog()` calls `setCurrency` as soon as that
 * response arrives (see `lib/api.ts`), and every screen reads `getCurrency()` at render
 * time rather than closing over a value captured at import time.
 */
let currency = 'USD'

export function setCurrency(next: string): void {
  currency = next
}

export function getCurrency(): string {
  return currency
}
