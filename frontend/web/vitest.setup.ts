// Radix `Checkbox` renders a hidden native "bubble input" whose size it tracks via
// `@radix-ui/react-use-size`, which calls `new ResizeObserver(...)` unconditionally on
// mount — jsdom has no ResizeObserver, so any test that mounts a `Checkbox` (first
// exercised for real by the catalog editors' FieldRow/Checkbox forms) throws
// `ReferenceError: ResizeObserver is not defined` before the render even settles.
// A no-op stand-in is enough: nothing in this suite asserts on observed dimensions.
if (typeof globalThis.ResizeObserver === 'undefined') {
  class ResizeObserverStub {
    observe() {}
    unobserve() {}
    disconnect() {}
  }
  globalThis.ResizeObserver = ResizeObserverStub as unknown as typeof ResizeObserver
}

// Radix `Select`'s open-content effect calls `scrollIntoView` on the highlighted item to
// keep it visible in the viewport — jsdom has no layout engine and doesn't implement it,
// so the first test to actually open a `Select` (Task 4's users/places editors) throws
// `TypeError: candidate?.scrollIntoView is not a function` from inside a passive effect.
// A no-op stand-in is enough: nothing in this suite asserts on scroll position. Guarded
// on `Element` existing at all — this setup file also runs for the plain-node suites
// (money/api/csv `.test.ts`, no `@vitest-environment jsdom` pragma), which have no DOM.
if (typeof Element !== 'undefined' && typeof Element.prototype.scrollIntoView === 'undefined') {
  Element.prototype.scrollIntoView = () => {}
}
