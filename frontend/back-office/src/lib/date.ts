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
