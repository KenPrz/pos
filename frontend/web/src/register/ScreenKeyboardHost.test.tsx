// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest'
import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { useState } from 'react'
import { ScreenKeyboardHost } from './ScreenKeyboardHost'

afterEach(cleanup)

function Harness({ enabled, layout }: { enabled: boolean; layout: 'numeric' | 'full' }) {
  const [value, setValue] = useState('')
  const [changeCount, setChangeCount] = useState(0)
  return (
    <>
      <label>
        Amount
        <input
          data-screen-keyboard={layout}
          value={value}
          onChange={(e) => {
            setValue(e.target.value)
            setChangeCount((c) => c + 1)
          }}
        />
      </label>
      {/* Rendered straight from React state, deliberately NOT read off the input node — a
          setNativeValue that assigns el.value directly (rather than through
          HTMLInputElement.prototype's setter) still ends up with the right-looking DOM
          value, because React's own instance-level value setter keeps its change tracker
          in lockstep with a direct assignment. The subsequent `input` event then looks
          unchanged to React, onChange never fires, and neither of these two elements would
          ever move off their initial contents — input.value alone cannot tell the two
          implementations apart, which is exactly why this file's guard checks these
          instead. */}
      <p data-testid="typed">{value}</p>
      <p data-testid="change-count">{changeCount}</p>
      <ScreenKeyboardHost enabled={enabled} />
    </>
  )
}

// react-simple-keyboard renders real <button> elements here (useButtonTag: true — see the
// host), so accessible names come straight from each key's label. It listens for
// pointerdown/pointerup rather than click when PointerEvent exists on the global (jsdom
// defines one), so presses in these tests go through fireEvent.pointerDown, not
// fireEvent.click — a plain click never reaches the library's handler and would make a
// broken wiring pass by accident.
describe('ScreenKeyboardHost', () => {
  it('renders nothing when the register has the keyboard off', () => {
    render(<Harness enabled={false} layout="numeric" />)
    fireEvent.focusIn(screen.getByLabelText('Amount'))
    expect(screen.queryByRole('button', { name: '7' })).not.toBeInTheDocument()
  })

  it('raises a numeric keyboard on focus and types into the field', () => {
    render(<Harness enabled layout="numeric" />)
    const input = screen.getByLabelText('Amount') as HTMLInputElement
    fireEvent.focusIn(input)

    fireEvent.pointerDown(screen.getByRole('button', { name: '5' }))
    fireEvent.pointerDown(screen.getByRole('button', { name: '0' }))

    // The guard that catches a broken setNativeValue. See the comment on <p data-testid
    //="typed"> above for why input.value on its own cannot distinguish a working
    // implementation from a broken one — these two assertions read state that can only
    // move if onChange genuinely fired twice.
    expect(screen.getByTestId('typed')).toHaveTextContent('50')
    expect(screen.getByTestId('change-count')).toHaveTextContent('2')
    // Also true in both the working and (as it happens) the broken case here, but worth
    // asserting since it's what the till visually shows.
    expect(input.value).toBe('50')
  })

  it('shows letters only for a full-layout field', () => {
    render(<Harness enabled layout="full" />)
    fireEvent.focusIn(screen.getByLabelText('Amount'))
    expect(screen.getByRole('button', { name: 'q' })).toBeInTheDocument()
  })

  it('dismisses on Done', () => {
    render(<Harness enabled layout="numeric" />)
    fireEvent.focusIn(screen.getByLabelText('Amount'))
    fireEvent.pointerDown(screen.getByRole('button', { name: /done/i }))
    expect(screen.queryByRole('button', { name: '7' })).not.toBeInTheDocument()
  })

  it('dismisses on a physical Enter key in the focused field', () => {
    render(<Harness enabled layout="numeric" />)
    const input = screen.getByLabelText('Amount')
    fireEvent.focusIn(input)
    expect(screen.getByRole('button', { name: '7' })).toBeInTheDocument()

    fireEvent.keyDown(input, { key: 'Enter' })

    expect(screen.queryByRole('button', { name: '7' })).not.toBeInTheDocument()
  })

  it('dismisses when focus moves to something that is not a screen-keyboard input', () => {
    render(
      <>
        <Harness enabled layout="numeric" />
        <button type="button">Elsewhere</button>
      </>,
    )
    fireEvent.focusIn(screen.getByLabelText('Amount'))
    expect(screen.getByRole('button', { name: '7' })).toBeInTheDocument()

    fireEvent.focusIn(screen.getByRole('button', { name: 'Elsewhere' }))

    expect(screen.queryByRole('button', { name: '7' })).not.toBeInTheDocument()
  })

  // jsdom has no layout engine — `offsetHeight` is always 0 and the ResizeObserver
  // polyfill (vitest.setup.ts) is a no-op — so this cannot prove the dock never visually
  // covers a field. What it CAN prove: the padding lands on the actual scrolling ancestor
  // (an inline overflow-y: auto div standing in for SaleScreen's internal panes / the
  // shell's fixed <main>), not unconditionally on document.body, and that the focused
  // field is asked to scroll into view.
  it('pads the nearest scrolling ancestor, not the document body, and scrolls the field into view', () => {
    render(
      <div data-testid="scroll-parent" style={{ overflowY: 'auto' }}>
        <Harness enabled layout="numeric" />
      </div>,
    )
    const input = screen.getByLabelText('Amount') as HTMLInputElement
    const scrollIntoView = vi.spyOn(input, 'scrollIntoView').mockImplementation(() => {})

    fireEvent.focusIn(input)

    expect(screen.getByTestId('scroll-parent').style.paddingBottom).not.toBe('')
    expect(document.body.style.paddingBottom).toBe('')
    expect(scrollIntoView).toHaveBeenCalledWith({ block: 'nearest' })
  })

  it('falls back to padding document.body when no scrolling ancestor exists', () => {
    render(<Harness enabled layout="numeric" />)
    fireEvent.focusIn(screen.getByLabelText('Amount'))

    expect(document.body.style.paddingBottom).not.toBe('')
  })
})
