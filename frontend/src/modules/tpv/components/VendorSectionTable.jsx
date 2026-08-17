import { useState, useMemo } from 'react'
import { Loader2, Plus } from 'lucide-react'
import { applyListFilter } from '@/components/ui/ListFilter'

/**
 * The shared shell every vendor-detail section renders into: header → search →
 * loading / error / empty / table. Extracted here so a new section is a column
 * list rather than another copy of this markup.
 *
 * Search is client-side via applyListFilter because these lists are per-vendor
 * and small; the server-paginated toolbar is for the tenant-wide screens.
 */
export function SectionTable({
  icon: Icon, title, hint, columns, rows, loading, error, searchFields, onRowClick, onAdd, addLabel = 'Add',
}) {
  const [q, setQ] = useState('')

  const filtered = useMemo(
    () => (q.trim() ? applyListFilter(rows, { search: q, fields: searchFields }) : rows),
    [rows, q, searchFields]
  )

  // The columns render even with nothing in them: an empty section should show
  // WHAT it holds, not just say it is empty. A bare "no records" line leaves the
  // reader guessing whether the section is broken or simply unused.
  const body = loading ? (
    <tr><td colSpan={columns.length} style={{ padding: '14px 10px', color: 'var(--text-muted)' }}>
      <span style={{ display: 'inline-flex', alignItems: 'center', gap: 8 }}>
        <Loader2 size={14} className="rfq-spin" /> Loading…
      </span>
    </td></tr>
  ) : error ? (
    <tr><td colSpan={columns.length} style={{ padding: '14px 10px', color: '#ef4444' }}>{error}</td></tr>
  ) : filtered.length === 0 ? (
    <tr><td colSpan={columns.length} style={{ padding: '14px 10px', color: 'var(--text-muted)' }}>
      {rows.length === 0 ? `No ${title.toLowerCase()} yet for this vendor.` : 'Nothing matches that search.'}
    </td></tr>
  ) : (
    filtered.map((row, i) => (
      <tr key={row.id ?? i}
        onClick={onRowClick ? () => onRowClick(row) : undefined}
        style={{ borderTop: '1px solid var(--border)', cursor: onRowClick ? 'pointer' : 'default' }}>
        {columns.map(c => (
          <td key={c.key} style={{ padding: '8px 10px', color: 'var(--text-h)' }}>
            {c.render ? c.render(row) : (row[c.key] ?? '—')}
          </td>
        ))}
      </tr>
    ))
  )

  return (
    <div className="pr-glass" style={{ padding: 20, borderRadius: 16 }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 4 }}>
        <Icon size={16} style={{ color: '#a78bfa' }} />
        <span style={{ fontSize: 13, fontWeight: 800, color: 'var(--text-h)' }}>{title}</span>
        {!loading && !error && (
          <span style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>
            {filtered.length}{filtered.length !== rows.length ? ` of ${rows.length}` : ''}
          </span>
        )}
        {/* Add sits with the section it fills, and only when the caller supplies
            a handler — a section whose module has no create path shows none. */}
        {onAdd && (
          <button onClick={onAdd}
            style={{
              marginLeft: 'auto', display: 'inline-flex', alignItems: 'center', gap: 5,
              padding: '6px 12px', borderRadius: 9, border: 'none', cursor: 'pointer',
              fontSize: 12, fontWeight: 700, color: '#fff',
              background: 'linear-gradient(135deg,#7C3AED,#7C3AEDcc)',
            }}>
            <Plus size={13} /> {addLabel}
          </button>
        )}
      </div>
      {hint && <p style={{ margin: '0 0 12px', fontSize: 11.5, color: 'var(--text-muted)' }}>{hint}</p>}

      {rows.length > 0 && (
        <input value={q} onChange={e => setQ(e.target.value)} placeholder="Search…"
          style={{
            width: 'min(260px, 100%)', marginBottom: 12, padding: '7px 10px', borderRadius: 9,
            fontSize: 12.5, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)',
          }} />
      )}

      <div style={{ overflowX: 'auto' }}>
        <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12.5, minWidth: 520 }}>
          <thead>
            <tr style={{ textAlign: 'left', color: 'var(--text-muted)' }}>
              {columns.map(c => (
                <th key={c.key} style={{ padding: '8px 10px', fontWeight: 700, fontSize: 11.5, whiteSpace: 'nowrap', borderBottom: '1px solid var(--border)' }}>{c.label}</th>
              ))}
            </tr>
          </thead>
          <tbody>{body}</tbody>
        </table>
      </div>
    </div>
  )
}

export const Pill = ({ value, good = [], bad = [] }) => {
  const v = String(value ?? '').toLowerCase()
  if (!v) return '—'
  const c = good.some(g => v.includes(g)) ? '#10b981' : bad.some(b => v.includes(b)) ? '#ef4444' : '#6b7280'
  return (
    <span style={{ display: 'inline-flex', padding: '2px 8px', borderRadius: 20, fontSize: 11, fontWeight: 700, color: c, background: `${c}1a` }}>
      {value}
    </span>
  )
}
