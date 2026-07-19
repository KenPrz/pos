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
