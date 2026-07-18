/**
 * Client-side CSV export (Task 11). No library — RFC 4180 quoting is three rules, and a
 * dependency for three rules is not worth carrying.
 */

/**
 * Quote a field if it contains a comma, a double quote, or a newline, doubling any
 * inner quotes first — the two rules that make a CSV round-trip through Excel/Sheets
 * without corrupting a cell.
 */
function escapeField(value: string | number): string {
  const s = String(value)
  return /[",\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s
}

/**
 * Build a CSV document from a header row and data rows. `\r\n` line endings per RFC
 * 4180 — Excel in particular is fussy about a bare `\n`.
 */
export function toCsv(headers: string[], rows: Array<Array<string | number>>): string {
  return [headers, ...rows].map((row) => row.map(escapeField).join(',')).join('\r\n')
}

/**
 * Money leaving the app as a decimal string, for a spreadsheet cell — the one place
 * display-formatting is allowed to leave the app. This is presentation, not arithmetic:
 * nothing reads the string back into a computation.
 */
export function centsToDecimalString(amountCents: number): string {
  return (amountCents / 100).toFixed(2)
}

/** Trigger a browser download of `csv` named `filename` via a Blob + throwaway anchor. */
export function downloadCsv(filename: string, csv: string): void {
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' })
  const url = URL.createObjectURL(blob)
  const anchor = document.createElement('a')
  anchor.href = url
  anchor.download = filename
  document.body.appendChild(anchor)
  anchor.click()
  document.body.removeChild(anchor)
  URL.revokeObjectURL(url)
}
