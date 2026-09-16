// Type-to-search on every native <select> in the app, without touching them.
//
// The kit's <SelectInput> is a searchable popover, but 697 dropdowns across 209
// files are still plain <select> elements written by hand. Converting each one
// is a 209-file change with 209 chances to break a form; this adds the search
// behaviour to all of them from one place, and can be removed from one place.
//
// How it works: a capture-phase pointerdown listener spots a click on a long
// <select>, cancels the native dropdown, and opens a small panel built from that
// select's OWN <option> elements — so it always shows exactly what the select
// offers, including options React re-rendered a moment ago. Picking one writes
// the value back through the native setter and fires a bubbling `change`, which
// is what React's synthetic event system listens for, so controlled components
// update exactly as if the user had used the native control.
//
// Deliberately conservative:
//   • Short lists are left alone — a filter box over four options is worse than
//     the native control. Same threshold the popover Select uses.
//   • Keyboard users get the untouched native select: this only intercepts a
//     real pointer, so tabbing and typing still drive the element directly.
//   • Multiple, size>1, disabled and opted-out selects are skipped.
//   • Anything unexpected falls through to the native dropdown rather than
//     leaving a control that cannot be opened.

// The three below are exported for scripts/searchable-selects.check.mjs. They
// are the parts with real decisions in them — which selects to take over, what
// the option list is, and the value write-back React has to notice — and this
// sits on the click path of every dropdown in the app.

/** Match the popover Select — below this the native control is better. */
const MIN_OPTIONS = 8

/** Opt a single select out with <select data-no-search>. */
const OPT_OUT = 'data-no-search'

const PANEL_ID = 'app-select-search-panel'

let panel = null
let activeSelect = null

/* ── the value write-back ────────────────────────────────────────────────────
 *
 * Assigning `select.value = x` directly does update the DOM, but React tracks
 * the previous value on the node and will treat the change as already handled,
 * so a controlled component silently reverts on its next render. Going through
 * the prototype's setter defeats that tracker, which is the documented way to
 * drive a React-controlled input from outside React.
 */
export const nativeSetValue = (select, value) => {
  const setter = Object.getOwnPropertyDescriptor(window.HTMLSelectElement.prototype, 'value')?.set
  if (setter) setter.call(select, value)
  else select.value = value

  select.dispatchEvent(new Event('input', { bubbles: true }))
  select.dispatchEvent(new Event('change', { bubbles: true }))
}

export const optionsOf = (select) =>
  Array.from(select.options || [])
    .filter(o => !o.disabled)
    .map(o => ({ value: o.value, label: (o.textContent || '').trim(), group: o.parentElement?.label || '' }))

export const enhanceable = (select) => {
  if (!select || select.disabled || select.multiple) return false
  if (select.hasAttribute(OPT_OUT) || select.closest(`[${OPT_OUT}]`)) return false
  if (Number(select.size) > 1) return false
  return optionsOf(select).length >= MIN_OPTIONS
}

/* ── the panel ───────────────────────────────────────────────────────────── */

function close() {
  panel?.remove()
  panel = null
  activeSelect = null
  document.removeEventListener('pointerdown', onOutside, true)
  document.removeEventListener('keydown', onKey, true)
  window.removeEventListener('resize', close)
  window.removeEventListener('scroll', onScroll, true)
}

const onOutside = (e) => { if (panel && !panel.contains(e.target)) close() }
const onKey = (e) => { if (e.key === 'Escape') { e.stopPropagation(); close() } }

/**
 * Close when the PAGE scrolls, not when the list does.
 *
 * The panel is position:fixed against the field's coordinates, so a page scroll
 * leaves it stranded — hence closing on scroll. But the listener is registered
 * in the capture phase, which sees scroll events from every element including
 * the panel's own option list. So scrolling the list to reach an option shut the
 * panel on the first wheel notch, and a list long enough to need scrolling was
 * the only kind that ever opened one.
 */
const onScroll = (e) => {
  if (panel && e.target instanceof Node && panel.contains(e.target)) {
    return
  }
  close()
}

function open(select) {
  close()
  activeSelect = select

  const rect = select.getBoundingClientRect()
  const items = optionsOf(select)

  panel = document.createElement('div')
  panel.id = PANEL_ID
  panel.setAttribute('role', 'listbox')
  Object.assign(panel.style, {
    position: 'fixed',
    // Below the field, unless there is more room above it.
    left: `${Math.max(8, Math.min(rect.left, window.innerWidth - rect.width - 8))}px`,
    top: `${rect.bottom + 4}px`,
    width: `${Math.max(rect.width, 220)}px`,
    maxHeight: '320px',
    zIndex: '2147483000',          // above modals, which sit at 1000–2000
    display: 'flex',
    flexDirection: 'column',
    borderRadius: '10px',
    border: '1px solid var(--border, #2a2f3a)',
    background: 'var(--bg-card, #171923)',
    boxShadow: '0 18px 44px rgba(0,0,0,0.42)',
    overflow: 'hidden',
    font: '13px system-ui, -apple-system, Segoe UI, sans-serif',
  })

  const search = document.createElement('input')
  search.type = 'text'
  search.placeholder = 'Type to search…'
  search.setAttribute('aria-label', 'Search options')
  Object.assign(search.style, {
    margin: '8px', padding: '7px 10px', borderRadius: '8px',
    border: '1px solid var(--border, #2a2f3a)',
    background: 'var(--bg-input, #0f1117)',
    color: 'var(--text-h, #fff)', outline: 'none', font: 'inherit',
  })

  const list = document.createElement('div')
  // overscrollBehavior: reaching the end of the list must not hand the wheel to
  // whatever is behind the panel. Without it the modal underneath scrolls, the
  // field moves out from under a panel pinned to its old coordinates, and the
  // page-scroll guard then closes it.
  Object.assign(list.style, {
    overflowY: 'auto',
    overscrollBehavior: 'contain',
    padding: '0 6px 8px',
  })

  const render = (query) => {
    const q = query.trim().toLowerCase()
    const matches = q ? items.filter(o => o.label.toLowerCase().includes(q)
                                        || o.group.toLowerCase().includes(q)) : items
    list.replaceChildren()

    if (!matches.length) {
      const none = document.createElement('div')
      none.textContent = `Nothing matches “${query}”`
      Object.assign(none.style, { padding: '14px 10px', color: 'var(--text-muted, #9ca3af)', textAlign: 'center' })
      list.append(none)
      return
    }

    for (const opt of matches) {
      const row = document.createElement('button')
      row.type = 'button'          // never submit the form this select lives in
      row.setAttribute('role', 'option')
      row.textContent = opt.group ? `${opt.group} · ${opt.label}` : opt.label
      const selected = opt.value === activeSelect?.value
      Object.assign(row.style, {
        display: 'block', width: '100%', textAlign: 'left',
        padding: '8px 10px', borderRadius: '7px', border: 'none', cursor: 'pointer',
        background: selected ? 'rgba(124,58,237,0.18)' : 'transparent',
        color: selected ? '#a78bfa' : 'var(--text-h, #fff)',
        font: 'inherit', fontWeight: selected ? '700' : '400',
      })
      row.onmouseenter = () => { if (!selected) row.style.background = 'rgba(148,163,184,0.14)' }
      row.onmouseleave = () => { if (!selected) row.style.background = 'transparent' }
      row.onclick = () => {
        const target = activeSelect
        close()
        if (target) nativeSetValue(target, opt.value)
      }
      list.append(row)
    }
  }

  search.addEventListener('input', () => render(search.value))
  search.addEventListener('keydown', (e) => {
    if (e.key !== 'Enter') return
    e.preventDefault()
    list.querySelector('button')?.click()   // Enter takes the first match
  })

  render('')
  panel.append(search, list)
  document.body.append(panel)

  /**
   * Put it wherever there is more room, and never taller than that room.
   *
   * The old rule flipped above only when the panel would overflow the bottom AND
   * fitted entirely above. A field low on a tall modal satisfied neither: it
   * stayed below, ran past the viewport, and the last option was sliced in half
   * with no way to reach it — the panel is fixed-position, so scrolling the page
   * to see the rest only closed it.
   *
   * Measuring the space first means the panel is always whole: shorter when it
   * has to be, on the roomier side, and its own list scrolls for the rest.
   */
  const GAP = 4
  const EDGE = 8
  const below = window.innerHeight - rect.bottom - GAP - EDGE
  const above = rect.top - GAP - EDGE
  const useAbove = below < 200 && above > below

  const room = Math.max(140, Math.min(320, useAbove ? above : below))
  panel.style.maxHeight = `${room}px`

  if (useAbove) {
    const height = Math.min(panel.getBoundingClientRect().height, room)
    panel.style.top = `${Math.max(EDGE, rect.top - GAP - height)}px`
  }

  search.focus()

  document.addEventListener('pointerdown', onOutside, true)
  document.addEventListener('keydown', onKey, true)
  window.addEventListener('resize', close)
  window.addEventListener('scroll', onScroll, true)
}

/* ── wiring ──────────────────────────────────────────────────────────────── */

function onPointerDown(e) {
  try {
    // Only a real pointer. A keyboard user opening the select with the keyboard
    // keeps the native control, which is already fully accessible.
    if (e.button !== 0 || (e.pointerType && e.pointerType !== 'mouse' && e.pointerType !== 'pen')) return

    const select = e.target instanceof Element ? e.target.closest('select') : null
    if (!select || !enhanceable(select)) return

    e.preventDefault()   // stop the native dropdown
    e.stopPropagation()
    open(select)
  } catch {
    // Never leave a select that cannot be opened: fall through to native.
  }
}

/** Call once at start-up. Returns a teardown for hot reload. */
export function installSearchableSelects() {
  if (typeof document === 'undefined' || window.__appSelectSearchInstalled) return () => {}
  window.__appSelectSearchInstalled = true

  document.addEventListener('pointerdown', onPointerDown, true)

  return () => {
    document.removeEventListener('pointerdown', onPointerDown, true)
    close()
    window.__appSelectSearchInstalled = false
  }
}
