'use client'

/**
 * A labeled money input shared by VariantEditor (price, cost) and DiscountEditor
 * (amount). Cents in, cents out at the edges — this component only ever holds the raw
 * string a human is typing; the caller parses it with `parseCentsOrNull` (never a float)
 * on submit and is responsible for blocking save while it's null.
 */
export function MoneyField({
  id,
  label,
  value,
  onChange,
  invalid,
}: {
  id: string
  label: string
  value: string
  onChange: (raw: string) => void
  invalid?: boolean
}) {
  return (
    <label htmlFor={id}>
      {label}
      <input
        id={id}
        type="text"
        inputMode="decimal"
        placeholder="0.00"
        value={value}
        aria-invalid={invalid || undefined}
        onChange={(e) => onChange(e.target.value)}
      />
    </label>
  )
}
