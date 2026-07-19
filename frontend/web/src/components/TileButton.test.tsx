// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest'
import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { TileButton } from './TileButton'

afterEach(cleanup)

describe('TileButton', () => {
  it('holds the 96px tile floor and fires onClick', () => {
    const onClick = vi.fn()
    render(<TileButton title="Cheeseburger" meta="$9.50" onClick={onClick} />)

    const tile = screen.getByRole('button', { name: /Cheeseburger/ })
    expect(tile.className).toContain('min-h-[96px]')
    fireEvent.click(tile)
    expect(onClick).toHaveBeenCalledTimes(1)
  })

  it('carries the semantic left edge only when a tone is given', () => {
    render(<TileButton title="Table 4" meta="Due $12.00" edge="warning" />)
    const edged = screen.getByRole('button', { name: /Table 4/ })
    expect(edged.className).toContain('border-l-4')
    expect(edged.className).toContain('border-l-warning')
    cleanup()

    render(<TileButton title="Table 5" meta="Due $8.00" />)
    const plain = screen.getByRole('button', { name: /Table 5/ })
    expect(plain.className).not.toContain('border-l-4')
  })

  it('disables the whole tile', () => {
    const onClick = vi.fn()
    render(<TileButton title="86d item" disabled onClick={onClick} />)
    expect(screen.getByRole('button', { name: /86d item/ })).toBeDisabled()
  })
})
