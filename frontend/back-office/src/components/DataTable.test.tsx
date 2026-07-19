// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest'
import { cleanup, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'
import { DataTable } from './DataTable'

afterEach(cleanup)

type Row = { id: string; name: string }

const COLUMNS = [{ key: 'name', header: 'Name' }]
const ROWS: Row[] = [
  { id: 'a', name: 'Latte' },
  { id: 'b', name: 'Cortado' },
]

describe('DataTable', () => {
  it('renders one row per item with its column values', () => {
    render(<DataTable columns={COLUMNS} rows={ROWS} />)

    expect(screen.getByText('Latte')).toBeInTheDocument()
    expect(screen.getByText('Cortado')).toBeInTheDocument()
  })

  it('renders the empty state when there are no rows', () => {
    render(<DataTable columns={COLUMNS} rows={[]} empty={{ title: 'No products yet' }} />)

    expect(screen.getByText('No products yet')).toBeInTheDocument()
  })

  it('uses rowKey to identify rows when provided', () => {
    render(<DataTable columns={COLUMNS} rows={ROWS} rowKey={(row) => row.id} />)

    const rows = screen.getAllByRole('row')
    // header row + 2 body rows
    expect(rows).toHaveLength(3)
  })

  it('zebra-stripes by default and omits the stripe class when zebra is false', () => {
    const { rerender } = render(<DataTable columns={COLUMNS} rows={ROWS} />)
    const bodyRows = () => screen.getAllByRole('row').slice(1)

    expect(bodyRows()[0]?.className).toContain('odd:bg-surface-1')

    rerender(<DataTable columns={COLUMNS} rows={ROWS} zebra={false} />)

    expect(bodyRows()[0]?.className).not.toContain('odd:bg-surface-1')
  })

  it('dims rows the inactive predicate returns true for', () => {
    render(<DataTable columns={COLUMNS} rows={ROWS} rowKey={(row) => row.id} inactive={(row) => row.id === 'b'} />)

    expect(screen.getByText('Latte').closest('tr')).not.toHaveClass('opacity-55')
    expect(screen.getByText('Cortado').closest('tr')).toHaveClass('opacity-55')
  })
})
