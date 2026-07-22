/**
 * The server's ISO-4217 currency code (`config('pos.currency')` — see
 * `AdminSessionResource`). Twin of `frontend/web/src/lib/currency.ts`; kept as close to
 * that copy as the two apps' boot sequences allow (not part of the byte-identical shared
 * set — this is app glue, not `components/ui/*`).
 *
 * The register learns it from every catalog fetch, so a stale value never survives past
 * boot. The back office has no equivalent standing request — `POST /admin/login` is the
 * only response that carries it — so a restored session (a token already in
 * localStorage, no fresh login this visit) has nothing to re-derive it from. Persisting
 * it to localStorage under `pos.currency` closes that gap: `setCurrency` writes through,
 * and `initCurrencyFromStorage` reads it back at boot, called from AdminApp's mount
 * effect right alongside the token/user restore (`adminToken.get()`/`adminToken.user()`).
 */
const STORAGE_KEY = 'pos.currency'

// `'USD'` is a pre-load placeholder only, good for the instant between first paint and
// AdminApp's mount effect resolving either a fresh login or a restored one.
let currency = 'USD'

export function setCurrency(next: string): void {
  currency = next
  localStorage.setItem(STORAGE_KEY, next)
}

export function getCurrency(): string {
  return currency
}

/** Restores a persisted currency ahead of any fresh login. Call once, at boot. */
export function initCurrencyFromStorage(): void {
  const stored = localStorage.getItem(STORAGE_KEY)
  if (stored) currency = stored
}
