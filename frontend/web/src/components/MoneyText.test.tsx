// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest'
import { cleanup, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'
import { MoneyText } from './MoneyText'
import { cents, formatMoney } from '../lib/money'

afterEach(cleanup)

describe('MoneyText', () => {
  it('renders exactly what formatMoney produces — no money math of its own', () => {
    render(<MoneyText cents={123456} currency="USD" size="line" />)
    expect(screen.getByText(formatMoney(cents(123456), 'USD'))).toBeInTheDocument()
  })

  it('is tabular at both sizes; total is display-md, line is body-lg', () => {
    render(<MoneyText cents={999} currency="USD" size="total" />)
    const total = screen.getByText(formatMoney(cents(999), 'USD'))
    expect(total.className).toContain('type-money')
    expect(total.className).toContain('type-display-md')
    cleanup()

    render(<MoneyText cents={999} currency="USD" size="line" />)
    const line = screen.getByText(formatMoney(cents(999), 'USD'))
    expect(line.className).toContain('type-money')
    expect(line.className).toContain('type-body-lg')
  })
})
