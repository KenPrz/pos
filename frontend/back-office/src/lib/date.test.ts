import { describe, expect, it } from 'vitest'
import { displayDate, formatIsoDate, parseIsoDate } from './date'

describe('parseIsoDate', () => {
  it('parses YYYY-MM-DD as a local calendar day', () => {
    const d = parseIsoDate('2026-07-23')
    expect(d?.getFullYear()).toBe(2026)
    expect(d?.getMonth()).toBe(6)
    expect(d?.getDate()).toBe(23)
  })

  it('rejects malformed and rollover strings', () => {
    expect(parseIsoDate('')).toBeNull()
    expect(parseIsoDate('23/07/2026')).toBeNull()
    expect(parseIsoDate('2026-7-3')).toBeNull()
    expect(parseIsoDate('2026-13-01')).toBeNull()
    expect(parseIsoDate('2026-02-30')).toBeNull()
  })
})

describe('formatIsoDate', () => {
  it('formats the LOCAL calendar day, zero-padded', () => {
    expect(formatIsoDate(new Date(2026, 6, 3))).toBe('2026-07-03')
  })

  it('round-trips with parseIsoDate', () => {
    expect(formatIsoDate(parseIsoDate('2026-01-31') as Date)).toBe('2026-01-31')
  })
})

describe('displayDate', () => {
  it('renders a short human date', () => {
    expect(displayDate('2026-07-23')).toBe('Jul 23, 2026')
  })

  it('is empty for an unparseable value', () => {
    expect(displayDate('')).toBe('')
  })
})
