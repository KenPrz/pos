// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest'
import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'
import { useState } from 'react'
import { ScreenKeyboardHost } from './ScreenKeyboardHost'

afterEach(cleanup)

function Harness({ enabled, layout }: { enabled: boolean; layout: 'numeric' | 'full' }) {
  const [value, setValue] = useState('')
  return (
    <>
      <label>
        Amount
        <input data-screen-keyboard={layout} value={value} onChange={(e) => setValue(e.target.value)} />
      </label>
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

    // The assertion that catches a broken setNativeValue: React's own state must have
    // advanced, not just the DOM node's value. React re-renders the controlled <input>
    // from its own state on every keystroke, so if setNativeValue only patched the DOM and
    // the dispatched `input` event were swallowed, onChange would never fire, state would
    // stay '', and the very next render would snap the DOM value back to '' too — this
    // assertion reads the live DOM value specifically because a broken wiring would fail it.
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
})
