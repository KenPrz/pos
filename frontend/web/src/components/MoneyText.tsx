import { cn } from '@/lib/utils'
import { cents, formatMoney } from '@/lib/money'

export interface MoneyTextProps {
  cents: number
  currency: string
  size: 'line' | 'total'
}

// Money on screen, register-sized. Formatting goes through the existing
// `formatMoney` — this component adds NO money math, only typography:
// `total` is display-md (42px weight-300) and `line` is body-lg (18px),
// both tabular so stacked amounts align digit-for-digit.
export function MoneyText({ cents: amountCents, currency, size }: MoneyTextProps) {
  return (
    <span className={cn('type-money', size === 'total' ? 'type-display-md' : 'type-body-lg')}>
      {formatMoney(cents(amountCents), currency)}
    </span>
  )
}
