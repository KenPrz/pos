// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest'
import { cleanup, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'
import { StatCard } from './StatCard'

afterEach(cleanup)

describe('StatCard', () => {
  it('renders label, value, and meta', () => {
    render(<StatCard label="Net sales today" value="$1,204.50" meta="12 orders" />)

    expect(screen.getByText('Net sales today')).toBeInTheDocument()
    expect(screen.getByText('$1,204.50')).toBeInTheDocument()
    expect(screen.getByText('12 orders')).toBeInTheDocument()
  })

  it('renders without a meta line when none is given', () => {
    render(<StatCard label="Refunds today" value="$0.00" />)

    expect(screen.getByText('Refunds today')).toBeInTheDocument()
    expect(screen.getByText('$0.00')).toBeInTheDocument()
  })
})
