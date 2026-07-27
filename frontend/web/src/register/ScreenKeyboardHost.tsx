'use client'

import { useEffect, useRef, useState } from 'react'
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
 * The nearest ancestor that actually clips/scrolls `el` — the register's layouts nest
 * several independent scrollers (SaleScreen's cart/context panes are fixed-height
 * `overflow-y-auto` boxes sized off the viewport; the Tauri shell pins `<main>` itself to
 * `position: fixed; overflow-y: auto` — see print.css's `.app-viewport-shell-fixed`), so
 * padding `document.body` is frequently a no-op: the box that needs the extra room at its
 * bottom is often several levels short of the body. Falls back to `document.body` for the
 * plain-browser case, where `<main>` is normal flow and the body itself is what scrolls.
 */
function findScrollParent(el: HTMLElement): HTMLElement {
  let node = el.parentElement
  while (node && node !== document.body) {
    const overflowY = getComputedStyle(node).overflowY
    if (overflowY === 'auto' || overflowY === 'scroll') return node
    node = node.parentElement
  }
  return document.body
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
  // `press` (below) is a plain function recreated every render, but react-simple-keyboard
  // diffs its `onKeyPress` option with `changedOptions`' JSON.stringify comparison — which
  // always reports two functions as equal — so a re-render's fresh `onKeyPress` closure may
  // never actually reach the library's internal instance. Reading the live target through a
  // ref sidesteps that: `press` itself can stay a stable-enough closure while still always
  // acting on whichever input is currently focused.
  const targetRef = useRef<HTMLInputElement | null>(null)
  targetRef.current = target
  const containerRef = useRef<HTMLDivElement | null>(null)

  useEffect(() => {
    if (!enabled) return
    const onFocusIn = (e: FocusEvent) => {
      const el = e.target
      const want = el instanceof HTMLElement ? el.dataset.screenKeyboard : undefined
      // Any focus change that doesn't land on a matching opted-in input dismisses the
      // keyboard — not just "focus left the DOM" but *any* other element, including a
      // plain <Button> elsewhere on the page. Without this, tapping something that isn't a
      // screen-keyboard input while the dock is open leaves it stuck open over content it
      // no longer applies to. (The keyboard's own keys never trigger this: they're real
      // <button>s that COULD steal focus on tap, but preventMouseDownDefault below stops
      // the browser's default focus-follows-pointerdown behavior, so pressing a key never
      // fires a focusin for the key itself.)
      if (el instanceof HTMLInputElement && (want === 'numeric' || want === 'full')) {
        setLayout(want)
        setShifted(false)
        setTarget(el)
        return
      }
      setTarget(null)
    }
    document.addEventListener('focusin', onFocusIn)
    return () => document.removeEventListener('focusin', onFocusIn)
  }, [enabled])

  // The design spec's dismissal contract is "Enter or Done" — Done is a key on the virtual
  // layout (handled in `press` below); Enter is a physical key, so it needs its own
  // listener on whatever is currently focused.
  useEffect(() => {
    if (target === null) return
    const onKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Enter') setTarget(null)
    }
    target.addEventListener('keydown', onKeyDown)
    return () => target.removeEventListener('keydown', onKeyDown)
  }, [target])

  // The dock is `position: fixed` (see screen-keyboard.css) so it never reflows the cart —
  // but that means it would otherwise sit on top of whatever is already at the bottom of
  // the screen, including the field that raised it. Pad the real scroll container (not
  // always the body — see findScrollParent) by the dock's measured height (numeric and
  // full layouts differ by a row, so a guessed constant would drift), and bring the
  // focused field back into view inside that container the moment the dock appears, since
  // it may already be scrolled out of view when it was focused.
  useEffect(() => {
    const dock = containerRef.current
    if (target === null || dock === null) return
    const scrollParent = findScrollParent(target)
    const apply = () => {
      scrollParent.style.paddingBottom = `${dock.offsetHeight}px`
    }
    apply()
    target.scrollIntoView({ block: 'nearest' })
    const observer = new ResizeObserver(apply)
    observer.observe(dock)
    return () => {
      observer.disconnect()
      scrollParent.style.paddingBottom = ''
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
        // A real <button> would otherwise take focus on pointerdown by default browser
        // behavior — stealing it from the field the keyboard is meant to be typing into,
        // and (with the focusin handler above) dismissing the keyboard on every keypress.
        // This tells the library to preventDefault() on that pointerdown/mousedown, so
        // pressing a key never moves focus off the actual input.
        preventMouseDownDefault={true}
        onKeyPress={press}
      />
    </div>
  )
}
