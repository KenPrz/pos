/**
 * Date formatting shared across the admin app. One place, so "what counts as today's
 * date on the wire" never drifts between call sites (SalesReportView's default range,
 * the audit log's default range, and anything else that needs a `'YYYY-MM-DD'` string
 * for the reports/audit query params).
 */

/** `'YYYY-MM-DD'` for a Date — the wire format `docs/03-api.md` expects. */
export function isoDate(d: Date): string {
  return d.toISOString().slice(0, 10)
}

/**
 * Parse `'YYYY-MM-DD'` into a LOCAL-midnight Date, or null if malformed. Deliberately
 * not `new Date(s)` — that parses as UTC midnight, which renders as the previous
 * calendar day anywhere west of Greenwich. The round-trip check rejects rollover
 * ('2026-02-30' must not become March 2nd).
 */
export function parseIsoDate(s: string): Date | null {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s)
  if (!m) return null
  const [y, mo, d] = [Number(m[1]), Number(m[2]), Number(m[3])]
  const date = new Date(y, mo - 1, d)
  return date.getFullYear() === y && date.getMonth() === mo - 1 && date.getDate() === d ? date : null
}

/** `'YYYY-MM-DD'` from a Date's LOCAL calendar day — the counterpart of parseIsoDate. */
export function formatIsoDate(d: Date): string {
  const mm = String(d.getMonth() + 1).padStart(2, '0')
  const dd = String(d.getDate()).padStart(2, '0')
  return `${d.getFullYear()}-${mm}-${dd}`
}

const DISPLAY_FORMAT = new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric', year: 'numeric' })

/** `'Jul 23, 2026'` for a wire date, `''` if it doesn't parse — picker trigger labels. */
export function displayDate(s: string): string {
  const d = parseIsoDate(s)
  return d ? DISPLAY_FORMAT.format(d) : ''
}
