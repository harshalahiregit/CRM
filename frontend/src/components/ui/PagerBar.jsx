import { ChevronLeft, ChevronRight } from 'lucide-react'

/**
 * Pager for a Laravel paginator response.
 *
 * Pass the paginator object itself ({ current_page, last_page, from, to,
 * total }) rather than five loose props, so a caller cannot wire up half a
 * pager and ship something that looks navigable and is not.
 *
 * Renders nothing for a single page — a short list gets no chrome.
 *
 * Page numbers are windowed. The pattern this replaces rendered one button per
 * page, which is fine for three and unusable for two hundred; this shows first,
 * last, and a window around the current page with gaps marked.
 */

const windowed = (page, count, span = 1) => {
  const keep = new Set([1, count])
  for (let p = page - span; p <= page + span; p++) if (p >= 1 && p <= count) keep.add(p)
  const pages = [...keep].sort((a, b) => a - b)

  // Insert nulls where the sequence jumps, so the caller can draw an ellipsis.
  const out = []
  pages.forEach((p, i) => {
    if (i > 0 && p - pages[i - 1] > 1) out.push(null)
    out.push(p)
  })
  return out
}

export default function PagerBar({ meta, onPage, unit = 'entries', className = '' }) {
  if (!meta || !meta.last_page || meta.last_page <= 1) return null

  const page  = meta.current_page ?? 1
  const count = meta.last_page
  const go    = (p) => { if (p >= 1 && p <= count && p !== page) onPage(p) }

  const btn = (active) => ({
    minWidth: 30, height: 30, padding: '0 8px',
    display: 'flex', alignItems: 'center', justifyContent: 'center',
    borderRadius: 8, cursor: 'pointer', fontSize: 12, fontWeight: 700,
    fontVariantNumeric: 'tabular-nums',
    background: active ? 'linear-gradient(135deg,#7C3AED,#6d28d9)' : 'var(--bg-input)',
    color: active ? '#fff' : 'var(--text-muted)',
    border: '1px solid var(--border)',
  })

  return (
    <div className={`flex items-center justify-between gap-3 flex-wrap text-xs ${className}`}
      style={{ color: 'var(--text-muted)' }}>
      <span style={{ fontVariantNumeric: 'tabular-nums' }}>
        Showing {meta.from ?? 0}–{meta.to ?? 0} of {meta.total ?? 0} {unit}
      </span>

      <div className="flex items-center gap-1">
        <button type="button" onClick={() => go(page - 1)} disabled={page <= 1}
          aria-label="Previous page" title="Previous page"
          style={{ ...btn(false), opacity: page <= 1 ? 0.4 : 1 }}>
          <ChevronLeft size={14} />
        </button>

        {windowed(page, count).map((p, i) =>
          p === null
            ? <span key={`gap-${i}`} style={{ padding: '0 4px' }}>…</span>
            : <button key={p} type="button" onClick={() => go(p)}
                aria-label={`Page ${p}`} aria-current={p === page ? 'page' : undefined}
                style={btn(p === page)}>{p}</button>,
        )}

        <button type="button" onClick={() => go(page + 1)} disabled={page >= count}
          aria-label="Next page" title="Next page"
          style={{ ...btn(false), opacity: page >= count ? 0.4 : 1 }}>
          <ChevronRight size={14} />
        </button>
      </div>
    </div>
  )
}
