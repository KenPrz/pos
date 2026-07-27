'use client'

import { useEffect, useLayoutEffect, useRef, useState } from 'react'
import Keyboard from 'react-simple-keyboard'
import 'react-simple-keyboard/build/css/index.css'
import './screen-keyboard.css'

type Layout = 'numeric' | 'full'

const LAYOUTS: Record<Layout, { default: string[]; shift?: string[] }> = {
  // Digits, decimal point, backspace. A cash field that accepts letters is a validation
  // problem the till does not need.
  numeric: { default: ['1 2 3', '4 5 6', '7 8 9', '. 0 {bksp}'] },
  full: {
    default: ['1 2 3 4 5 6 7 8 9 0', 'q w e r t y u i o p', 'a s d f g h j k l', '{shift} z x c v b n m {bksp}', '{space}'],
    shift: ['1 2 3 4 5 6 7 8 9 0', 'Q W E R T Y U I O P', 'A S D F G H J K L', '{shift} Z X C V B N M {bksp}', '{space}'],
  },
}

/**
 * React installs its own value setter on each input instance and tracks the last value it
 * saw. Assigning `el.value` directly updates the DOM but leaves that tracker stale, so the
 * `input` event we dispatch afterwards is swallowed as a no-op and onChange never fires.
 * Going through the PROTOTYPE's setter is what makes React see a real change.
 */
function setNativeValue(el: HTMLInputElement, value: string): void {
  const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')?.set
  setter?.call(el, value)
  el.dispatchEvent(new Event('input', { bubbles: true }))
}

/**
 * One keyboard for the whole register app, raised by focus on any input that opts in with
 * `data-screen-keyboard="numeric|full"`.
 *
 * A host rather than per-field wiring: threading onKeyPress through every input would be
 * sixteen files touched, sixteen chances to miss one, and every input added later would
 * silently opt out.
 */
export function ScreenKeyboardHost({ enabled }: { enabled: boolean }) {
  const [target, setTarget] = useState<HTMLInputElement | null>(null)
  const [layout, setLayout] = useState<Layout>('numeric')
  const [shifted, setShifted] = useState(false)
  const targetRef = useRef<HTMLInputElement | null>(null)
  targetRef.current = target
  const containerRef = useRef<HTMLDivElement | null>(null)

  useEffect(() => {
    if (!enabled) return
    const onFocusIn = (e: FocusEvent) => {
      const el = e.target
      if (!(el instanceof HTMLInputElement)) return
      const want = el.dataset.screenKeyboard
      if (want !== 'numeric' && want !== 'full') return setTarget(null)
      setLayout(want)
      setShifted(false)
      setTarget(el)
    }
    document.addEventListener('focusin', onFocusIn)
    return () => document.removeEventListener('focusin', onFocusIn)
  }, [enabled])

  // The dock is `position: fixed` (see screen-keyboard.css) so it never reflows the cart —
  // but that means it would otherwise sit on top of whatever is already at the bottom of
  // the screen, including the field that raised it. Pad the document by the dock's real,
  // measured height (numeric and full layouts differ by a row, so a guessed constant would
  // drift) rather than a fixed number, and clear the padding the moment nothing is docked.
  useLayoutEffect(() => {
    const el = containerRef.current
    if (target === null || el === null) {
      document.body.style.paddingBottom = ''
      return
    }
    const apply = () => {
      document.body.style.paddingBottom = `${el.offsetHeight}px`
    }
    apply()
    const observer = new ResizeObserver(apply)
    observer.observe(el)
    return () => {
      observer.disconnect()
      document.body.style.paddingBottom = ''
    }
  }, [target, layout, shifted])

  if (!enabled || target === null) return null

  const press = (button: string) => {
    const el = targetRef.current
    if (el === null) return
    if (button === '{shift}') return setShifted((s) => !s)
    if (button === '{done}') return setTarget(null)
    if (button === '{bksp}') return setNativeValue(el, el.value.slice(0, -1))
    setNativeValue(el, el.value + (button === '{space}' ? ' ' : button))
  }

  return (
    <div ref={containerRef} className="screen-keyboard" role="group" aria-label="On-screen keyboard">
      <Keyboard
        layoutName={shifted ? 'shift' : 'default'}
        layout={{
          default: [...LAYOUTS[layout].default, '{done}'],
          shift: [...(LAYOUTS[layout].shift ?? LAYOUTS[layout].default), '{done}'],
        }}
        display={{ '{bksp}': '⌫', '{shift}': '⇧', '{space}': 'space', '{done}': 'Done' }}
        // react-simple-keyboard renders <div data-skbtn> by default, not real buttons — the
        // library's own CSS/theme is built around that. useButtonTag switches it to real
        // <button> elements instead: correct semantics and a real accessible name for a
        // touch UI, and it's what makes `data-screen-keyboard`'s consumers (Task 4) and
        // this file's own tests able to select keys by role rather than by CSS hook.
        useButtonTag={true}
        onKeyPress={press}
      />
    </div>
  )
}
