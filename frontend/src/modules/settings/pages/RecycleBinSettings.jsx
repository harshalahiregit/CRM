import { useState, useEffect, useCallback } from 'react'
import { Trash2, RotateCcw, Loader2, Inbox } from 'lucide-react'
import { settingsApi } from '@/services/settingsApi'

/**
 * ST2 — Recycle Bin. Lists soft-deleted records across modules (invoice, estimate/
 * PI, project, task, ticket) and restores one. Admin-only (the route is gated).
 */
const TONE = {
  invoice: '#0891b2', estimate: '#7C3AED', project: '#0ea5e9', task: '#16a34a', ticket: '#d97706',
}
const fmtDate = d => (d ? new Date(d).toLocaleString() : '—')

export default function RecycleBinSettings() {
  const [rows, setRows] = useState(null)
  const [error, setError] = useState('')
  const [busyId, setBusyId] = useState('')
  const [filter, setFilter] = useState('all')

  const load = useCallback(() => {
    setError('')
    settingsApi.recycleBin.list()
      .then(d => setRows(d?.data ?? []))
      .catch(e => setError(e?.message || 'Could not load the recycle bin.'))
  }, [])
  useEffect(() => { load() }, [load])

  const restore = async (row) => {
    setBusyId(`${row.type}:${row.id}`)
    try { await settingsApi.recycleBin.restore(row.type, row.id); load() }
    catch (e) { setError(e?.message || 'Could not restore that item.') }
    finally { setBusyId('') }
  }

  const types = rows ? [...new Set(rows.map(r => r.type))] : []
  const shown = rows ? (filter === 'all' ? rows : rows.filter(r => r.type === filter)) : []

  return (
    <div className="card-3d" style={{ padding: 20, maxWidth: 900 }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 4 }}>
        <Trash2 size={18} style={{ color: '#a78bfa' }} />
        <h2 style={{ fontSize: 16, fontWeight: 800, color: 'var(--text-h)', margin: 0 }}>Recycle Bin</h2>
      </div>
      <p style={{ fontSize: 12.5, color: 'var(--text-muted)', margin: '0 0 16px' }}>
        Recently deleted items across the workspace. Restore anything deleted by mistake.
      </p>

      {error && <p style={{ color: '#ef4444', fontSize: 12.5, marginBottom: 12 }}>{error}</p>}

      {rows === null ? (
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, color: 'var(--text-muted)', padding: '18px 0' }}>
          <Loader2 size={15} className="rfq-spin" /> Loading…
        </div>
      ) : rows.length === 0 ? (
        <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 8, padding: '38px 0', color: 'var(--text-muted)' }}>
          <Inbox size={26} style={{ opacity: 0.6 }} />
          <span style={{ fontSize: 13 }}>The recycle bin is empty.</span>
        </div>
      ) : (
        <>
          {/* Type filter */}
          <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', marginBottom: 12 }}>
            {['all', ...types].map(t => (
              <button key={t} onClick={() => setFilter(t)}
                style={{
                  padding: '5px 11px', borderRadius: 8, fontSize: 11.5, fontWeight: 700, cursor: 'pointer',
                  border: '1px solid var(--border)',
                  background: filter === t ? '#7C3AED' : 'transparent',
                  color: filter === t ? '#fff' : 'var(--text-muted)', textTransform: 'capitalize',
                }}>{t}</button>
            ))}
          </div>

          <div style={{ overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
              <thead>
                <tr style={{ textAlign: 'left', color: 'var(--text-muted)', fontSize: 10.5, textTransform: 'uppercase', letterSpacing: '0.04em' }}>
                  <th style={{ padding: '8px 10px' }}>Type</th>
                  <th style={{ padding: '8px 10px' }}>Item</th>
                  <th style={{ padding: '8px 10px' }}>Deleted</th>
                  <th style={{ padding: '8px 10px', textAlign: 'right' }}>Action</th>
                </tr>
              </thead>
              <tbody>
                {shown.map(row => {
                  const busy = busyId === `${row.type}:${row.id}`
                  return (
                    <tr key={`${row.type}:${row.id}`} style={{ borderTop: '1px solid var(--border)' }}>
                      <td style={{ padding: '9px 10px' }}>
                        <span style={{ fontSize: 11, fontWeight: 700, padding: '2px 9px', borderRadius: 999, background: `${TONE[row.type] || '#64748b'}1f`, color: TONE[row.type] || '#64748b' }}>{row.type_label}</span>
                      </td>
                      <td style={{ padding: '9px 10px', color: 'var(--text-h)', fontWeight: 600 }}>{row.label}</td>
                      <td style={{ padding: '9px 10px', color: 'var(--text-muted)' }}>{fmtDate(row.deleted_at)}</td>
                      <td style={{ padding: '9px 10px', textAlign: 'right' }}>
                        <button onClick={() => restore(row)} disabled={busy}
                          style={{ display: 'inline-flex', alignItems: 'center', gap: 5, padding: '6px 12px', borderRadius: 8, border: '1px solid var(--border)', background: 'var(--bg-input)', color: 'var(--text-h)', fontWeight: 700, fontSize: 12, cursor: 'pointer', opacity: busy ? 0.6 : 1 }}>
                          <RotateCcw size={13} /> {busy ? 'Restoring…' : 'Restore'}
                        </button>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        </>
      )}
    </div>
  )
}
