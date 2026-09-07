import { useState, useEffect, useCallback } from 'react'
import { Trash2, RotateCcw, Loader2, Inbox, Search, X } from 'lucide-react'
import { settingsApi } from '@/services/settingsApi'

/**
 * The Recycle Bin — everything deleted across the workspace, and the way back.
 *
 * This listed five kinds of record (invoice, estimate, project, task, ticket)
 * because those were the only five the server could restore. It now covers the
 * whole CRM, which changes what the screen has to be: sixty-odd types is too
 * many to show as one flat row of filter chips, and too many to fetch in full
 * and filter in the browser.
 *
 * So filtering is the server's job — module, then type, then a search over the
 * item's own label — and only types that actually hold something are offered,
 * because a filter listing sixty empty options is a wall, not a filter.
 *
 * Admin-only; the route is gated.
 */

/** One hue per module, so the eye can group the list without reading it. */
const GROUP_TONE = {
  Sales: '#0891b2',
  Customers: '#8b5cf6',
  Accounts: '#16a34a',
  Purchase: '#d97706',
  TPV: '#0ea5e9',
  Inventory: '#e11d48',
  Work: '#7C3AED',
  HR: '#f97316',
  Compliance: '#64748b',
}
const toneFor = (group) => GROUP_TONE[group] || '#64748b'
const fmtDate = (d) => (d ? new Date(d).toLocaleString() : '—')

export default function RecycleBinSettings() {
  const [rows, setRows] = useState(null)
  const [types, setTypes] = useState([])
  const [groups, setGroups] = useState([])
  const [total, setTotal] = useState(0)
  const [error, setError] = useState('')
  const [busyId, setBusyId] = useState('')
  const [flash, setFlash] = useState('')

  const [group, setGroup] = useState('')
  const [type, setType] = useState('')
  const [query, setQuery] = useState('')
  const [search, setSearch] = useState('')

  // Debounced, because each keystroke is a real query across every type.
  useEffect(() => {
    const id = setTimeout(() => setSearch(query.trim()), 300)
    return () => clearTimeout(id)
  }, [query])

  const load = useCallback(() => {
    setError('')
    settingsApi.recycleBin.list({
      ...(group ? { group } : {}),
      ...(type ? { type } : {}),
      ...(search ? { q: search } : {}),
    })
      .then(d => {
        setRows(d?.data ?? [])
        setTypes(d?.types ?? [])
        setGroups(d?.groups ?? [])
        setTotal(d?.total ?? 0)
      })
      .catch(e => { setRows([]); setError(e?.message || 'Could not load the recycle bin.') })
  }, [group, type, search])

  useEffect(() => { load() }, [load])

  const restore = async (row) => {
    setBusyId(`${row.type}:${row.id}`)
    setError(''); setFlash('')
    try {
      await settingsApi.recycleBin.restore(row.type, row.id)
      setFlash(`Restored “${row.label}”.`)
      load()
    } catch (e) {
      setError(e?.message || 'Could not restore that item.')
    } finally {
      setBusyId('')
    }
  }

  // Choosing a module clears a type belonging to a different one.
  const pickGroup = (g) => {
    setGroup(g)
    setType(t => (t && !types.some(x => x.value === t && x.group === g) ? '' : t))
  }

  const typesInGroup = group ? types.filter(t => t.group === group) : types
  const isEmpty = rows !== null && rows.length === 0
  const filtered = !!(group || type || search)

  return (
    <div className="card-3d" style={{ padding: 20, maxWidth: 980 }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 4, flexWrap: 'wrap' }}>
        <Trash2 size={18} style={{ color: '#a78bfa' }} />
        <h2 style={{ fontSize: 16, fontWeight: 800, color: 'var(--text-h)', margin: 0 }}>Recycle Bin</h2>
        {total > 0 && (
          <span style={{ fontSize: 11, fontWeight: 800, padding: '2px 9px', borderRadius: 999, background: 'rgba(124,58,237,0.14)', color: '#a78bfa' }}>
            {total} item{total === 1 ? '' : 's'}
          </span>
        )}
      </div>
      <p style={{ fontSize: 12.5, color: 'var(--text-muted)', margin: '0 0 16px' }}>
        Deleted records from across the workspace. Restore anything removed by mistake — it returns exactly where it was.
      </p>

      {error && <Banner tone="#ef4444">{error}</Banner>}
      {flash && <Banner tone="#10b981">{flash}</Banner>}

      {/* Search stays available even when the current filter finds nothing —
          otherwise the only way out of an empty result is a page reload. */}
      {(total > 0 || filtered) && (
        <>
          <div style={{ position: 'relative', marginBottom: 12, maxWidth: 340 }}>
            <Search size={14} style={{ position: 'absolute', left: 11, top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)' }} />
            <input
              value={query}
              onChange={e => setQuery(e.target.value)}
              placeholder="Search deleted items…"
              style={{
                width: '100%', padding: '8px 28px 8px 32px', borderRadius: 9,
                border: '1px solid var(--border)', background: 'var(--bg-input)',
                color: 'var(--text-h)', fontSize: 12.5, outline: 'none',
              }}
            />
            {query && (
              <button onClick={() => setQuery('')} aria-label="Clear search"
                style={{ position: 'absolute', right: 8, top: '50%', transform: 'translateY(-50%)', border: 'none', background: 'none', cursor: 'pointer', color: 'var(--text-muted)', display: 'flex' }}>
                <X size={13} />
              </button>
            )}
          </div>

          {groups.length > 1 && (
            <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', marginBottom: 8 }}>
              <Chip active={!group} onClick={() => { setGroup(''); setType('') }}>All modules</Chip>
              {groups.map(g => (
                <Chip key={g} active={group === g} tone={toneFor(g)} onClick={() => pickGroup(g)}>{g}</Chip>
              ))}
            </div>
          )}

          {typesInGroup.length > 1 && (
            <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', marginBottom: 14 }}>
              <Chip active={!type} onClick={() => setType('')}>All types</Chip>
              {typesInGroup.map(t => (
                <Chip key={t.value} active={type === t.value} tone={toneFor(t.group)} onClick={() => setType(t.value)}>
                  {t.label} <span style={{ opacity: 0.7 }}>{t.count}</span>
                </Chip>
              ))}
            </div>
          )}
        </>
      )}

      {rows === null ? (
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, color: 'var(--text-muted)', padding: '18px 0' }}>
          <Loader2 size={15} className="rfq-spin" /> Loading…
        </div>
      ) : isEmpty ? (
        <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 8, padding: '38px 0', color: 'var(--text-muted)' }}>
          <Inbox size={26} style={{ opacity: 0.6 }} />
          <span style={{ fontSize: 13 }}>
            {filtered ? 'Nothing deleted matches that filter.' : 'The recycle bin is empty.'}
          </span>
          {filtered && (
            <button onClick={() => { setGroup(''); setType(''); setQuery('') }}
              style={{ border: 'none', background: 'none', color: '#a78bfa', fontWeight: 700, fontSize: 12, cursor: 'pointer', textDecoration: 'underline' }}>
              Clear filters
            </button>
          )}
        </div>
      ) : (
        <div style={{ overflowX: 'auto' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
            <thead>
              <tr style={{ textAlign: 'left', color: 'var(--text-muted)', fontSize: 10.5, textTransform: 'uppercase', letterSpacing: '0.04em' }}>
                <th style={th}>Type</th>
                <th style={th}>Item</th>
                <th style={th}>Deleted</th>
                <th style={{ ...th, textAlign: 'right' }}>Action</th>
              </tr>
            </thead>
            <tbody>
              {rows.map(row => {
                const busy = busyId === `${row.type}:${row.id}`
                const tone = toneFor(row.group)
                return (
                  <tr key={`${row.type}:${row.id}`} style={{ borderTop: '1px solid var(--border)' }}>
                    <td style={td}>
                      <span style={{ fontSize: 11, fontWeight: 700, padding: '2px 9px', borderRadius: 999, background: `${tone}1f`, color: tone, whiteSpace: 'nowrap' }}>
                        {row.type_label}
                      </span>
                    </td>
                    <td style={{ ...td, color: 'var(--text-h)', fontWeight: 600 }}>{row.label}</td>
                    <td style={{ ...td, color: 'var(--text-muted)', whiteSpace: 'nowrap' }}>{fmtDate(row.deleted_at)}</td>
                    <td style={{ ...td, textAlign: 'right' }}>
                      <button onClick={() => restore(row)} disabled={busy}
                        style={{ display: 'inline-flex', alignItems: 'center', gap: 5, padding: '6px 12px', borderRadius: 8, border: '1px solid var(--border)', background: 'var(--bg-input)', color: 'var(--text-h)', fontWeight: 700, fontSize: 12, cursor: busy ? 'wait' : 'pointer', opacity: busy ? 0.6 : 1 }}>
                        <RotateCcw size={13} /> {busy ? 'Restoring…' : 'Restore'}
                      </button>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}

function Chip({ active, tone = '#7C3AED', onClick, children }) {
  return (
    <button
      onClick={onClick}
      style={{
        padding: '5px 11px', borderRadius: 8, fontSize: 11.5, fontWeight: 700, cursor: 'pointer',
        border: `1px solid ${active ? 'transparent' : 'var(--border)'}`,
        background: active ? tone : 'transparent',
        color: active ? '#fff' : 'var(--text-muted)',
      }}
    >
      {children}
    </button>
  )
}

const Banner = ({ tone, children }) => (
  <p style={{
    fontSize: 12.5, color: 'var(--text-h)', marginBottom: 12, padding: '9px 12px',
    borderRadius: 9, background: `${tone}14`, border: `1px solid ${tone}55`,
  }}>{children}</p>
)

const th = { padding: '8px 10px' }
const td = { padding: '9px 10px' }
