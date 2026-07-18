import { describe, expect, it } from 'vitest'
import { centsToDecimalString, toCsv } from './csv'

describe('toCsv', () => {
  it('quotes a field containing a comma and doubles inner quotes', () => {
    expect(toCsv(['h'], [['a,"b"']])).toBe('h\r\n"a,""b"""')
  })

  it('leaves plain fields unquoted', () => {
    expect(toCsv(['a', 'b'], [['plain', 42]])).toBe('a,b\r\nplain,42')
  })

  it('quotes a field containing a newline', () => {
    expect(toCsv(['h'], [['line1\nline2']])).toBe('h\r\n"line1\nline2"')
  })

  it('quotes a field containing a bare carriage return', () => {
    expect(toCsv(['h'], [['a\rb']])).toBe('h\r\n"a\rb"')
  })

  it('joins the header and every row with CRLF, never a bare newline', () => {
    const csv = toCsv(['x', 'y'], [
      ['1', '2'],
      ['3', '4'],
    ])
    expect(csv).toBe('x,y\r\n1,2\r\n3,4')
    expect(csv).not.toMatch(/[^\r]\n/)
  })
})

describe('centsToDecimalString', () => {
  it('renders whole and fractional cent amounts as two-decimal strings', () => {
    expect(centsToDecimalString(12345)).toBe('123.45')
    expect(centsToDecimalString(100)).toBe('1.00')
    expect(centsToDecimalString(5)).toBe('0.05')
  })

  it('renders negative amounts (refunds) with the sign preserved', () => {
    expect(centsToDecimalString(-50)).toBe('-0.50')
  })
})
