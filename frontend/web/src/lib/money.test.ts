import { describe, expect, it } from 'vitest'
import {
  add,
  cents,
  formatMoney,
  formatQuantity,
  isNegative,
  isZero,
  parseCents,
  quantity,
  subtract,
} from './money'

describe('cents', () => {
  it('tags integers', () => {
    expect(cents(1234)).toBe(1234)
    expect(cents(0)).toBe(0)
    expect(cents(-500)).toBe(-500)
  })

  it('rejects a fractional cent', () => {
    // A non-integer arriving from the wire means the server broke its own contract.
    // Better to find out here than on a receipt.
    expect(() => cents(12.34)).toThrow(TypeError)
    expect(() => cents(0.1 + 0.2)).toThrow(TypeError)
  })

  it('rejects values outside safe integer range', () => {
    expect(() => cents(Number.NaN)).toThrow(TypeError)
    expect(() => cents(Number.POSITIVE_INFINITY)).toThrow(TypeError)
    expect(() => cents(Number.MAX_SAFE_INTEGER + 1)).toThrow(TypeError)
  })
})

describe('arithmetic', () => {
  it('adds and subtracts exactly', () => {
    expect(add(cents(1000), cents(234))).toBe(1234)
    expect(subtract(cents(1000), cents(1))).toBe(999)
  })

  it('does not drift when adding repeatedly', () => {
    // The float version of this loop does not land on 100.00.
    let total = cents(0)
    for (let i = 0; i < 1000; i++) {
      total = add(total, cents(10))
    }
    expect(total).toBe(10_000)
  })

  it('knows its sign', () => {
    expect(isZero(cents(0))).toBe(true)
    expect(isNegative(cents(-1))).toBe(true)
    expect(isNegative(cents(0))).toBe(false)
  })
})

describe('parseCents', () => {
  it.each([
    ['0', 0],
    ['12.34', 1234],
    ['12.3', 1230],
    ['12', 1200],
    ['-4.05', -405],
    // parseFloat-based parsing is where the classic off-by-one-cent bugs live.
    ['1.15', 115],
    ['0.07', 7],
    ['1000000.99', 100_000_099],
  ])('parses %s to %d cents', (input, expected) => {
    expect(parseCents(input)).toBe(expected)
  })

  it.each([['1.234'], ['abc'], [''], ['1.2.3'], ['$5.00'], ['1,234'], ['.5'], ['1e3']])(
    'rejects %s',
    (input) => {
      expect(() => parseCents(input)).toThrow(TypeError)
    },
  )

  it('agrees with the backend parser on the whole cent range', () => {
    // Same rule as Money::parse in the backend. If these two ever disagree, a price typed
    // in the back office is not the price rung up at the till.
    for (let value = 0; value <= 500; value++) {
      const text = `${Math.floor(value / 100)}.${String(value % 100).padStart(2, '0')}`
      expect(parseCents(text)).toBe(value)
    }
  })
})

describe('formatMoney', () => {
  it('formats cents as currency', () => {
    expect(formatMoney(cents(1234), 'USD', 'en-US')).toBe('$12.34')
    expect(formatMoney(cents(0), 'USD', 'en-US')).toBe('$0.00')
    expect(formatMoney(cents(-500), 'USD', 'en-US')).toBe('-$5.00')
    expect(formatMoney(cents(100_000_099), 'USD', 'en-US')).toBe('$1,000,000.99')
  })

  it('respects the currency, not a hardcoded symbol', () => {
    expect(formatMoney(cents(1234), 'GBP', 'en-GB')).toBe('£12.34')
    expect(formatMoney(cents(1234), 'JPY', 'en-US')).toContain('12')
  })

  it('renders every cent value in a range without a rounding artefact', () => {
    // The one division by 100 in the codebase. Prove it cannot show 12.339999.
    for (let value = 0; value <= 2000; value++) {
      const formatted = formatMoney(cents(value), 'USD', 'en-US')
      const expected = `$${Math.floor(value / 100)}.${String(value % 100).padStart(2, '0')}`
      expect(formatted).toBe(expected)
    }
  })
})

describe('quantity', () => {
  it('keeps the wire string intact', () => {
    // numeric(12,3) does not survive a JS number, so it is never parsed into one.
    expect(quantity('0.500')).toBe('0.500')
    expect(quantity('3')).toBe('3')
    expect(quantity('1.234')).toBe('1.234')
  })

  it('rejects malformed quantities', () => {
    expect(() => quantity('1.2345')).toThrow(TypeError)
    expect(() => quantity('abc')).toThrow(TypeError)
    expect(() => quantity('.5')).toThrow(TypeError)
  })

  it('trims trailing zeros for display only', () => {
    expect(formatQuantity(quantity('0.500'))).toBe('0.5')
    expect(formatQuantity(quantity('3.000'))).toBe('3')
    expect(formatQuantity(quantity('1.234'))).toBe('1.234')
    expect(formatQuantity(quantity('3'))).toBe('3')
  })
})
