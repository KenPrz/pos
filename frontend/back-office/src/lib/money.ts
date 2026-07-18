/**
 * Money on the client.
 *
 * The rule from docs/01-architecture.md holds here too: an amount is an integer number of
 * minor units. The frontend's job is to *display* money, never to compute it — the server
 * owns every total, tax, discount and change calculation, and the register renders what
 * it is told.
 *
 * The one thing this file must get right is never letting a plain number be mistaken for
 * an amount of money.
 */

/**
 * An integer number of minor units (cents).
 *
 * The brand is what stops `total_cents` being passed where a quantity was meant, or a
 * dollar amount being handed to something expecting cents. It costs nothing at runtime —
 * `Cents` is a `number` — and it is erased entirely at build time.
 */
export type Cents = number & { readonly __brand: 'Cents' }

/**
 * Tag a number as cents. Throws on anything that isn't a safe integer, because a
 * fractional cent arriving from the wire means the server broke its own contract and we
 * would rather find out here than on a receipt.
 */
export function cents(value: number): Cents {
  if (!Number.isSafeInteger(value)) {
    throw new TypeError(`Not an integer amount of cents: ${value}`)
  }
  return value as Cents
}

export const ZERO = cents(0)

export function add(a: Cents, b: Cents): Cents {
  return cents(a + b)
}

export function subtract(a: Cents, b: Cents): Cents {
  return cents(a - b)
}

export function isZero(amount: Cents): boolean {
  return amount === 0
}

export function isNegative(amount: Cents): boolean {
  return amount < 0
}

/**
 * Format for display, at the very last moment.
 *
 * The division by 100 is the only place a float appears anywhere in this system, and it
 * is safe here for two reasons: it happens once, at the edge, on a value that is never
 * read back; and Intl rounds to the currency's minor units, so the IEEE-754 residue is
 * gone before a human sees it. Never parse the result back into a number — take the
 * integer from the server again instead.
 */
export function formatMoney(amount: Cents, currency: string, locale?: string): string {
  return new Intl.NumberFormat(locale, {
    style: 'currency',
    currency,
  }).format(amount / 100)
}

/**
 * Parse what a human typed into cents, with string arithmetic rather than parseFloat.
 *
 * `Math.round(parseFloat('1.15') * 100)` is 115, but `parseFloat('8.075') * 1000` is
 * 8074.999999999999 — the class of bug that makes a till short by a cent a week. Only
 * needed where staff type an amount (cash tendered, back-office prices).
 */
export function parseCents(input: string): Cents {
  const match = /^(-)?(\d+)(?:\.(\d{1,2}))?$/.exec(input.trim())

  if (!match) {
    throw new TypeError(`Not a well-formed amount: "${input}"`)
  }

  const sign = match[1] === '-' ? -1 : 1
  const whole = Number.parseInt(match[2], 10)
  const fraction = Number.parseInt((match[3] ?? '').padEnd(2, '0') || '0', 10)

  return cents(sign * (whole * 100 + fraction))
}

/**
 * A quantity, as the string the API sends (docs/03-api.md).
 *
 * Kept as a string on purpose: `numeric(12,3)` does not survive a round-trip through a JS
 * number, so parsing it to display it would be the one operation guaranteed to corrupt it.
 */
/**
 * parseCents for register inputs: null instead of a throw, and negatives rejected —
 * no drawer field ("tendered", "counted", "float") means anything below zero.
 */
export function parseCentsOrNull(input: string): Cents | null {
  try {
    const value = parseCents(input)
    return value < 0 ? null : value
  } catch {
    return null
  }
}

/**
 * Split into `parts` amounts that sum exactly back to `amount` — mirrors the backend's
 * `Money::allocate` (docs/01-architecture.md): the earliest part absorbs the remainder.
 * 1000 split 3 ways is 334, 333, 333, never 333.33 rounded three independent ways, which
 * would invent or destroy a penny. Frontend use is display-only (the SPLIT stepper's
 * even-split preview) — the server is what actually splits the order.
 */
export function allocate(amount: Cents, parts: number): Cents[] {
  if (!Number.isInteger(parts) || parts < 1) {
    throw new TypeError(`Cannot split money into ${parts} parts.`)
  }

  const base = Math.trunc(amount / parts)
  const shares = new Array<number>(parts).fill(base)
  const remainder = amount - base * parts
  const step = remainder >= 0 ? 1 : -1

  for (let i = 0; i < Math.abs(remainder); i++) {
    shares[i % parts] += step
  }

  return shares.map((s) => cents(s))
}

export type QuantityString = string & { readonly __brand: 'Quantity' }

export function quantity(value: string): QuantityString {
  if (!/^-?\d+(\.\d{1,3})?$/.test(value)) {
    throw new TypeError(`Not a well-formed quantity: "${value}"`)
  }
  return value as QuantityString
}

/** Render '0.500' as '0.5' — display only; the value itself is never rewritten. */
export function formatQuantity(value: QuantityString): string {
  return value.includes('.') ? value.replace(/\.?0+$/, '') : value
}
