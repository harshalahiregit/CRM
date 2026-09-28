import { useEffect, useRef, useState } from 'react'
import { ExternalLink, Inbox, Plus } from 'lucide-react'
import LoadError from '@/components/ui/LoadError'
import { useVendorWorkspace } from './vendorWorkspaceContext'

/**
 * The chrome every vendor-workspace tab is built from.
 *
 * Extracted from vendorDetailTabs so the Workforce tabs can use it too. It was
 * either this or an import cycle between the two tab files — and a cycle that
 * happens to work today because of hoisting is a trap for whoever adds the next
 * const to it.
 */

export const card = { padding: 18 }

export const th = {
  textAlign: 'left', padding: '9px 12px', fontSize: 11, textTransform: 'uppercase',
  letterSpacing: '.04em', color: 'var(--text-muted)', fontWeight: 700,
}

export const td = { padding: '9px 12px', color: 'var(--text-muted)', fontSize: 13 }

export const primaryBtn = {
  display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 16px', borderRadius: 8,
  background: '#7C3AED', color: '#fff', border: 'none', cursor: 'pointer', fontSize: 13, fontWeight: 700,
}

export const linkBtn = {
  display: 'inline-flex', alignItems: 'center', gap: 5, padding: '6px 10px', borderRadius: 7,
  background: 'transparent', border: '1px solid var(--border)', color: 'var(--text-muted)',
  cursor: 'pointer', fontSize: 12, fontWeight: 700,
}

export function Badge({ cfg }) {
  const c = cfg || { label: '—', color: '#6b7280', bg: 'rgba(107,114,128,0.15)' }

  return (
    <span style={{ fontSize: 11, fontWeight: 700, color: c.color, background: c.bg, padding: '2px 9px', borderRadius: 999 }}>
      {c.label}
    </span>
  )
}

export function Empty({ text }) {
  return (
    <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 8, padding: '32px 0', color: 'var(--text-muted)' }}>
      <Inbox size={26} style={{ opacity: 0.6 }} />
      <span style={{ fontSize: 13 }}>{text}</span>
    </div>
  )
}

export function TabHead({ title, count, actionLabel, onAction, addLabel, onAdd }) {
  return (
    <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 12, gap: 10 }}>
      <h2 style={{ fontSize: 15, fontWeight: 800, color: 'var(--text-h)', margin: 0 }}>
        {title}
        {typeof count === 'number' && <span style={{ color: 'var(--text-muted)', fontWeight: 600 }}> · {count}</span>}
      </h2>
      <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
        {actionLabel && <button onClick={onAction} style={linkBtn}><ExternalLink size={13} /> {actionLabel}</button>}
        {addLabel && <button onClick={onAdd} style={primaryBtn}><Plus size={14} /> {addLabel}</button>}
      </div>
    </div>
  )
}

/**
 * Reusable vendor-scoped list — fetches THIS vendor's records from a Purchase
 * API and renders them inside the workspace. Nothing here navigates away.
 *
 * `AddModal`, when given, is the module's OWN create form. It is rendered with
 * `presetVendorId` so the vendor is already chosen, and the list refreshes on
 * save. Callers pass the real form (NewRfqModal, NewOrderModal, …) — never a
 * reduced copy, so validations and catalog pickers cannot drift.
 */
export function VendorScopedList({
  title, fetcher, columns, statusCfg, onRowClick,
  AddModal, addLabel, addAsList = false, emptyText, onAdd,
}) {
  const { vendor } = useVendorWorkspace()
  const [rows, setRows] = useState(null)
  const [loadError, setLoadError] = useState(null)
  const [adding, setAdding] = useState(false)
  const [nonce, setNonce] = useState(0)
  const fetchRef = useRef(fetcher)
  fetchRef.current = fetcher

  useEffect(() => {
    let alive = true
    setRows(null)
    setLoadError(null)
    fetchRef.current(vendor.id)
      .then((r) => { if (alive) setRows(Array.isArray(r) ? r : (r?.data ?? [])) })
      .catch((e) => { if (alive) { setRows([]); setLoadError(e) } })
    return () => { alive = false }
  }, [vendor.id, nonce])

  return (
    <div className="card-3d" style={card}>
      {adding && AddModal && (
        <AddModal
          {...(addAsList ? { presetVendorIds: [vendor.id] } : { presetVendorId: vendor.id })}
          onClose={() => setAdding(false)}
          onDone={() => { setAdding(false); setNonce(n => n + 1) }}
          onSaved={() => { setAdding(false); setNonce(n => n + 1) }}
        />
      )}
      {/* onAdd takes precedence: a couple of modules expose a full-page create
          screen rather than a modal, and navigating there beats a second form. */}
      <TabHead title={title} count={rows?.length}
        addLabel={(AddModal || onAdd) ? addLabel : null}
        onAdd={onAdd || (() => setAdding(true))} />
      {loadError ? <LoadError error={loadError} onRetry={() => setNonce(n => n + 1)} />
        : rows === null ? <div style={{ color: 'var(--text-muted)' }}>Loading…</div>
          : rows.length === 0 ? <Empty text={emptyText || `No ${title.toLowerCase()} for this vendor`} />
            : (
              <div style={{ overflowX: 'auto' }}>
                <table style={{ width: '100%', borderCollapse: 'collapse' }}>
                  <thead><tr style={{ background: 'var(--bg-input)' }}>
                    {columns.map((c) => <th key={c.header} style={th}>{c.header}</th>)}
                    {statusCfg && <th style={th}>Status</th>}
                  </tr></thead>
                  <tbody>
                    {rows.map((row) => (
                      <tr key={row.id}
                        onClick={onRowClick ? () => onRowClick(row) : undefined}
                        style={{ borderTop: '1px solid var(--border)', cursor: onRowClick ? 'pointer' : 'default' }}>
                        {columns.map((c) => (
                          <td key={c.header} style={c.strong ? { ...td, color: 'var(--text-h)', fontWeight: 700 } : td}>
                            {c.cell(row)}
                          </td>
                        ))}
                        {statusCfg && <td style={td}><Badge cfg={statusCfg(row.status, row)} /></td>}
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
    </div>
  )
}
