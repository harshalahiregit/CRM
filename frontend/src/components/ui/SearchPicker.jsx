import { useState, useEffect, useMemo, useRef } from 'react'
import { Search, X, Check } from 'lucide-react'

/**
 * Searchable pop-up picker — the replacement for window.prompt('…enter an id').
 * Agents pick a real name from a filtered list instead of typing a raw id they'd
 * have to look up. Filtering is client-side over `items`.
 *
 * items: [{ id, label, sublabel?, dot? }]
 */
export default function SearchPicker({
  open,
  onClose,
  onPick,
  items = [],
  title = 'Select',
  subtitle,
  placeholder = 'Search…',
  emptyText = 'Nothing to choose from.',
  loading = false,
  allowClear = false,
  clearLabel = 'Clear selection',
  accent = 'var(--color-support-500)',
}) {
  const [q, setQ] = useState('')
  const [active, setActive] = useState(0)
  const inputRef = useRef(null)
  const listRef = useRef(null)

  useEffect(() => { if (open) { setQ(''); setActive(0); setTimeout(() => inputRef.current?.focus(), 30) } }, [open])

  const filtered = useMemo(() => {
    const s = q.trim().toLowerCase()
    if (!s) return items
    return items.filter(i =>
      String(i.label).toLowerCase().includes(s) ||
      String(i.sublabel ?? '').toLowerCase().includes(s) ||
      String(i.id).includes(s)
    )
  }, [items, q])

  useEffect(() => { setActive(0) }, [q])
  useEffect(() => {
    if (!open) return
    listRef.current?.children[active]?.scrollIntoView({ block: 'nearest' })
  }, [active, open])

  if (!open) return null

  const onKeyDown = (e) => {
    if (e.key === 'Escape') { e.preventDefault(); onClose?.() }
    else if (e.key === 'ArrowDown') { e.preventDefault(); setActive(i => Math.min(filtered.length - 1, i + 1)) }
    else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(i => Math.max(0, i - 1)) }
    else if (e.key === 'Enter') { e.preventDefault(); if (filtered[active]) { onPick?.(filtered[active]); onClose?.() } }
  }

  return (
    <div className="fixed inset-0 z-[60] flex items-start justify-center p-4 pt-[12vh] bg-black/50" onClick={onClose}>
      <div
        className="w-full max-w-lg rounded-2xl overflow-hidden"
        style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card-3d)' }}
        onClick={e => e.stopPropagation()}
        onKeyDown={onKeyDown}
      >
        {/* Header */}
        <div className="flex items-start justify-between gap-3 px-5 pt-4 pb-3">
          <div>
            <h2 className="font-bold" style={{ color: 'var(--text-h)', fontSize: 15 }}>{title}</h2>
            {subtitle && <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{subtitle}</p>}
          </div>
          <button onClick={onClose} className="hover:opacity-60" aria-label="Close"><X size={17} style={{ color: 'var(--text-muted)' }} /></button>
        </div>

        {/* Search */}
        <div className="px-5 pb-3">
          <div className="relative">
            <Search size={15} style={{ position: 'absolute', left: 12, top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)' }} />
            <input
              ref={inputRef}
              value={q}
              onChange={e => setQ(e.target.value)}
              placeholder={placeholder}
              className="w-full rounded-xl outline-none"
              style={{ padding: '10px 12px 10px 36px', fontSize: 13.5, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }}
            />
          </div>
        </div>

        {/* List */}
        <ul ref={listRef} className="overflow-auto" style={{ maxHeight: '46vh', borderTop: '1px solid var(--border)' }}>
          {loading && <li style={{ padding: '18px 20px', fontSize: 13, color: 'var(--text-muted)' }}>Loading…</li>}
          {!loading && filtered.length === 0 && (
            <li style={{ padding: '18px 20px', fontSize: 13, color: 'var(--text-muted)', textAlign: 'center' }}>
              {q.trim() ? `No matches for “${q.trim()}”` : emptyText}
            </li>
          )}
          {!loading && filtered.map((it, i) => (
            <li key={it.id}>
              <button
                type="button"
                onMouseEnter={() => setActive(i)}
                onClick={() => { onPick?.(it); onClose?.() }}
                className="w-full flex items-center gap-3 text-left transition-colors"
                style={{
                  padding: '11px 20px',
                  background: active === i ? `color-mix(in srgb, ${accent} 10%, transparent)` : 'transparent',
                  borderBottom: '1px solid var(--border)',
                }}
              >
                {it.dot && <span style={{ width: 8, height: 8, borderRadius: '50%', background: it.dot, flexShrink: 0 }} />}
                <span className="flex-1 min-w-0">
                  <span className="block truncate" style={{ fontSize: 13.5, fontWeight: 600, color: 'var(--text-h)' }}>{it.label}</span>
                  {it.sublabel && <span className="block truncate" style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>{it.sublabel}</span>}
                </span>
                <span style={{ fontSize: 11, color: 'var(--text-muted)', fontFamily: 'monospace', flexShrink: 0 }}>#{it.id}</span>
              </button>
            </li>
          ))}
        </ul>

        {/* Footer */}
        <div className="flex items-center justify-between px-5 py-3" style={{ background: 'var(--bg-input)', borderTop: '1px solid var(--border)' }}>
          <span className="text-[11px]" style={{ color: 'var(--text-muted)', opacity: 0.8 }}>↑↓ navigate · ↵ select · esc close</span>
          {allowClear && (
            <button
              onClick={() => { onPick?.(null); onClose?.() }}
              className="text-xs font-bold px-3 py-1.5 rounded-lg"
              style={{ color: 'var(--color-danger-500)', border: '1px solid var(--border)' }}
            >
              {clearLabel}
            </button>
          )}
        </div>
      </div>
    </div>
  )
}

/** Themed yes/no modal — replaces window.confirm's "localhost says…" dialog. */
export function ConfirmModal({ open, onClose, onConfirm, title, message, confirmLabel = 'Confirm', danger = false, accent = 'var(--color-support-500)' }) {
  if (!open) return null
  const tone = danger ? 'var(--color-danger-500)' : accent
  return (
    <div className="fixed inset-0 z-[70] flex items-start justify-center p-4 pt-[18vh] bg-black/50" onClick={onClose}>
      <div
        className="w-full max-w-sm rounded-2xl overflow-hidden"
        style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card-3d)' }}
        onClick={e => e.stopPropagation()}
      >
        <div className="px-5 pt-4 pb-3">
          <h2 className="font-bold mb-1" style={{ color: 'var(--text-h)', fontSize: 15 }}>{title}</h2>
          <p className="text-xs leading-relaxed" style={{ color: 'var(--text-muted)' }}>{message}</p>
        </div>
        <div className="flex items-center justify-end gap-2 px-5 py-3" style={{ background: 'var(--bg-input)', borderTop: '1px solid var(--border)' }}>
          <button onClick={onClose} className="text-xs font-semibold px-4 py-2 rounded-xl" style={{ color: 'var(--text-muted)', border: '1px solid var(--border)' }}>Cancel</button>
          <button onClick={() => { onConfirm?.(); onClose?.() }} className="text-xs font-bold px-4 py-2 rounded-xl" style={{ background: tone, color: '#fff' }}>{confirmLabel}</button>
        </div>
      </div>
    </div>
  )
}

/** Simple text-entry modal — replaces window.prompt('New category name'). */
export function InputModal({ open, onClose, onSubmit, title, subtitle, placeholder = '', initial = '', submitLabel = 'Add', accent = 'var(--color-support-500)' }) {
  const [v, setV] = useState(initial)
  const ref = useRef(null)
  useEffect(() => { if (open) { setV(initial); setTimeout(() => ref.current?.focus(), 30) } }, [open, initial])
  if (!open) return null

  const submit = (e) => { e?.preventDefault?.(); const t = v.trim(); if (t) { onSubmit?.(t); onClose?.() } }

  return (
    <div className="fixed inset-0 z-[60] flex items-start justify-center p-4 pt-[16vh] bg-black/50" onClick={onClose}>
      <form
        onSubmit={submit}
        className="w-full max-w-md rounded-2xl overflow-hidden"
        style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card-3d)' }}
        onClick={e => e.stopPropagation()}
        onKeyDown={e => { if (e.key === 'Escape') onClose?.() }}
      >
        <div className="flex items-start justify-between gap-3 px-5 pt-4 pb-1">
          <div>
            <h2 className="font-bold" style={{ color: 'var(--text-h)', fontSize: 15 }}>{title}</h2>
            {subtitle && <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{subtitle}</p>}
          </div>
          <button type="button" onClick={onClose} className="hover:opacity-60" aria-label="Close"><X size={17} style={{ color: 'var(--text-muted)' }} /></button>
        </div>
        <div className="px-5 py-4">
          <input
            ref={ref}
            value={v}
            onChange={e => setV(e.target.value)}
            placeholder={placeholder}
            className="w-full rounded-xl outline-none"
            style={{ padding: '10px 12px', fontSize: 13.5, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }}
          />
        </div>
        <div className="flex items-center justify-end gap-2 px-5 py-3" style={{ background: 'var(--bg-input)', borderTop: '1px solid var(--border)' }}>
          <button type="button" onClick={onClose} className="text-xs font-semibold px-4 py-2 rounded-xl" style={{ color: 'var(--text-muted)', border: '1px solid var(--border)' }}>Cancel</button>
          <button type="submit" disabled={!v.trim()} className="flex items-center gap-1.5 text-xs font-bold px-4 py-2 rounded-xl disabled:opacity-40" style={{ background: accent, color: '#fff' }}>
            <Check size={13} /> {submitLabel}
          </button>
        </div>
      </form>
    </div>
  )
}
