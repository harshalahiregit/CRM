// Checks for src/lib/searchableSelects.js — run with `npm run check:selects`.
//
// This sits on the click path of EVERY dropdown in the app: get it wrong and
// 697 selects either stop opening or stop saving. The frontend has no test
// runner, so the decisions are checked here against DOM stubs — which selects
// it takes over, what option list it reads, and the value write-back React has
// to notice.

/* ── minimal DOM ─────────────────────────────────────────────────────────── */

class FakeEvent {
  constructor(type, opts = {}) { this.type = type; this.bubbles = !!opts.bubbles }
}
globalThis.Event = FakeEvent

// The prototype setter is the whole point of nativeSetValue: React tracks the
// last value on the node, so a plain assignment is ignored on the next render.
const setterCalls = []
globalThis.window = {
  HTMLSelectElement: {
    prototype: {
      set value(v) { setterCalls.push(v); this._viaSetter = v },
      get value() { return this._viaSetter },
    },
  },
}

const makeSelect = ({ options = [], disabled = false, multiple = false, size = 0, optOut = false, ancestorOptOut = false } = {}) => {
  const el = {
    disabled, multiple, size, value: '', _viaSetter: undefined,
    options: options.map(o => ({
      value: o.value, textContent: o.label, disabled: !!o.disabled,
      parentElement: o.group ? { label: o.group } : null,
    })),
    hasAttribute: (a) => optOut && a === 'data-no-search',
    closest: () => (ancestorOptOut ? {} : null),
    dispatched: [],
    dispatchEvent(e) { this.dispatched.push(e.type); return true },
  }
  return el
}

const many = (n, prefix = 'Item') =>
  Array.from({ length: n }, (_, i) => ({ value: String(i), label: `${prefix} ${i}` }))

const { enhanceable, optionsOf, nativeSetValue } = await import('../src/lib/searchableSelects.js')

let failures = 0
const check = (name, cond, extra = '') => {
  if (!cond) failures++
  console.log(`${cond ? 'ok  ' : 'FAIL'}  ${name}${extra ? '  — ' + extra : ''}`)
}
const group = (n) => console.log(`\n── ${n}`)

/* ── which selects get taken over ────────────────────────────────────────── */

group('when to intervene')
check('a long list is enhanced', enhanceable(makeSelect({ options: many(20) })))
check('exactly the threshold is enhanced', enhanceable(makeSelect({ options: many(8) })))
check('a short list is left native', !enhanceable(makeSelect({ options: many(7) })),
  'a filter box over 7 options is worse than the native control')
check('an empty select is left native', !enhanceable(makeSelect({ options: [] })))
check('a disabled select is left alone', !enhanceable(makeSelect({ options: many(20), disabled: true })))
check('a multi-select is left alone', !enhanceable(makeSelect({ options: many(20), multiple: true })),
  'the panel picks one value; multiple needs the native control')
check('a list box (size > 1) is left alone', !enhanceable(makeSelect({ options: many(20), size: 6 })))
check('data-no-search opts one out', !enhanceable(makeSelect({ options: many(20), optOut: true })))
check('an opted-out ancestor opts its selects out', !enhanceable(makeSelect({ options: many(20), ancestorOptOut: true })))
check('null is handled', !enhanceable(null))

/* ── what the panel lists ────────────────────────────────────────────────── */

group('the option list')
const withDisabled = makeSelect({ options: [
  { value: 'a', label: 'Alpha' },
  { value: 'b', label: 'Beta', disabled: true },
  { value: 'c', label: '  Gamma  ' },
] })
const listed = optionsOf(withDisabled)
check('disabled options are dropped', listed.length === 2, listed.map(o => o.label).join(', '))
check('labels are trimmed', listed[1].label === 'Gamma', JSON.stringify(listed[1].label))
check('values are preserved', listed[0].value === 'a')

const grouped = optionsOf(makeSelect({ options: [{ value: 'x', label: 'Bolt', group: 'Fasteners' }] }))
check('optgroup labels are carried', grouped[0].group === 'Fasteners')

const threshold = makeSelect({ options: [...many(7), { value: 'd', label: 'Disabled', disabled: true }] })
check('a disabled option does not push a short list over the threshold',
  !enhanceable(threshold), '7 usable + 1 disabled must stay native')

/* ── the write-back React has to notice ──────────────────────────────────── */

group('value write-back')
setterCalls.length = 0
const target = makeSelect({ options: many(20) })
nativeSetValue(target, '12')

check('written through the prototype setter', setterCalls.includes('12'),
  'a plain assignment is swallowed by React’s value tracker')
check('an input event is fired', target.dispatched.includes('input'))
check('a change event is fired', target.dispatched.includes('change'),
  'change is what React’s onChange actually listens for')
check('both events bubble', target.dispatched.length === 2)

console.log(failures ? `\n${failures} FAILED` : '\nall passed')
process.exit(failures ? 1 : 0)
