import { useState, useEffect, useMemo } from 'react'
import { useNavigate } from 'react-router-dom'
import { Boxes, ExternalLink, X, Wrench, ShieldCheck, CalendarClock } from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { inventoryApi } from '@/services/inventoryApi'

/**
 * An employee's assets, read from the Inventory register.
 *
 * There is no HRMS asset store behind this — every row, count and status comes
 * from `/hr/employees/{id}/assets`, which is a thin read-through to Inventory's
 * own AssetService. Nothing here creates, edits or reconciles an asset; changes
 * are made in Inventory and show up on the next load.
 */

// Inventory owns the status; these are only its colours.
const STATE_TONE = {
  assigned:    '#0ca30c',
  reserved:    '#2a78d6',
  maintenance: '#fab219',
  damaged:     '#ec835a',
  lost:        '#d03b3b',
  returned:    '#8b8b8b',
}

const FILTERS = [
  { key: 'all',         label: 'All' },
  { key: 'assigned',    label: 'Assigned' },
  { key: 'maintenance', label: 'Under Maintenance' },
  { key: 'returned',    label: 'Returned' },
  { key: 'lost',        label: 'Lost' },
  { key: 'damaged',     label: 'Damaged' },
]

const fmt = d => (d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—')

export default function EmployeeAssetsPanel({ employeeId, filter = 'all', onFilterChange }) {
  const [rows, setRows]   = useState([])
  const [loading, setLoad] = useState(true)
  const [error, setError] = useState('')
  const [open, setOpen]   = useState(null)

  useEffect(() => {
    let alive = true
    setLoad(true)
    hrApi.employees.assets(employeeId)
      .then(r => { if (alive) { setRows(Array.isArray(r) ? r : []); setError('') } })
      .catch(() => { if (alive) setError('Could not load assets from Inventory.') })
      .finally(() => { if (alive) setLoad(false) })
    return () => { alive = false }
  }, [employeeId])

  const shown = useMemo(
    () => (filter === 'all' ? rows : rows.filter(r => r.state === filter)),
    [rows, filter],
  )

  const counts = useMemo(() => {
    const c = { all: rows.length }
    rows.forEach(r => { c[r.state] = (c[r.state] || 0) + 1 })
    return c
  }, [rows])

  if (loading) return <p className="text-xs py-8 text-center" style={{ color: 'var(--text-muted)' }}>Loading assets from Inventory…</p>
  if (error)   return <p className="text-xs py-8 text-center" style={{ color: '#d03b3b' }}>{error}</p>

  return (
    <div>
      {/* Filter rail — mirrors the Overview cards so a click from there lands here. */}
      <div className="no-print flex gap-1.5 overflow-x-auto scrollbar-hide mb-3">
        {FILTERS.map(f => {
          const active = filter === f.key
          const n = counts[f.key] || 0
          return (
            <button key={f.key} onClick={() => onFilterChange?.(f.key)}
              className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[11px] font-bold whitespace-nowrap transition-all"
              style={{
                background: active ? 'var(--text-h)' : 'var(--bg-input)',
                color: active ? 'var(--bg-card)' : 'var(--text-muted)',
                border: `1px solid ${active ? 'transparent' : 'var(--border)'}`,
                opacity: n === 0 && !active ? 0.55 : 1,
              }}>
              {f.label}
              <span style={{ fontVariantNumeric: 'tabular-nums', opacity: 0.75 }}>{n}</span>
            </button>
          )
        })}
      </div>

      <div className="overflow-x-auto rounded-xl" style={{ border: '1px solid var(--border)' }}>
        <table className="w-full text-sm" style={{ minWidth: 860 }}>
          <thead>
            <tr style={{ borderBottom: '1px solid var(--border)' }}>
              {['Asset', 'Category', 'Asset Code', 'Serial Number', 'Assigned Date', 'Assigned By', 'Warranty', 'Status', ''].map(h => (
                <th key={h} className="text-left px-3 py-2.5 label-caps whitespace-nowrap">{h}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {shown.length === 0 ? (
              <tr><td colSpan={9} className="px-3 py-10 text-center text-xs" style={{ color: 'var(--text-muted)' }}>
                {rows.length === 0
                  ? 'No assets assigned from Inventory.'
                  : `No ${FILTERS.find(f => f.key === filter)?.label.toLowerCase()} assets.`}
              </td></tr>
            ) : shown.map(a => (
              <tr key={`${a.id}-${a.state}`} className="cursor-pointer hover:opacity-90"
                  onClick={() => setOpen(a)} style={{ borderTop: '1px solid var(--border)' }}>
                <td className="px-3 py-2.5">
                  <div className="flex items-center gap-2.5">
                    <AssetThumb asset={a} />
                    <div className="min-w-0">
                      <div className="font-bold truncate" style={{ color: 'var(--text-h)' }}>{a.name}</div>
                      {a.brand && <div className="text-[10.5px] truncate" style={{ color: 'var(--text-muted)' }}>{a.brand}</div>}
                    </div>
                  </div>
                </td>
                <td className="px-3 py-2.5 text-xs" style={{ color: 'var(--text-muted)' }}>{a.category || '—'}</td>
                <td className="px-3 py-2.5 text-xs font-mono" style={{ color: 'var(--text-muted)' }}>{a.code || '—'}</td>
                <td className="px-3 py-2.5 text-xs font-mono" style={{ color: 'var(--text-muted)' }}>{a.serial_no || '—'}</td>
                <td className="px-3 py-2.5 text-xs whitespace-nowrap" style={{ color: 'var(--text-muted)' }}>{fmt(a.assigned_at)}</td>
                <td className="px-3 py-2.5 text-xs whitespace-nowrap" style={{ color: 'var(--text-muted)' }}>{a.assigned_by || '—'}</td>
                <td className="px-3 py-2.5 text-xs whitespace-nowrap" style={{ color: 'var(--text-muted)' }}>{fmt(a.warranty_until)}</td>
                <td className="px-3 py-2.5"><StateBadge state={a.state} label={a.status_label} /></td>
                <td className="px-3 py-2.5 text-right"><ExternalLink size={13} style={{ color: 'var(--text-muted)' }} /></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <p className="text-[11px] mt-2.5 flex items-center gap-1.5" style={{ color: 'var(--text-muted)' }}>
        <Boxes size={12} /> Read from the Inventory asset register. Assign, return and maintenance actions are performed in Inventory.
      </p>

      {open && <AssetDetail employeeId={employeeId} asset={open} onClose={() => setOpen(null)} />}
    </div>
  )
}

/** The product photo, when Inventory holds one — it is a private blob. */
function AssetThumb({ asset }) {
  const [url, setUrl] = useState(null)

  useEffect(() => {
    if (!asset.product_id || !asset.image_path) return
    let revoke = null
    inventoryApi.products.imageBlob(asset.product_id)
      .then(u => { revoke = u; setUrl(u) })
      .catch(() => {})
    return () => { if (revoke) URL.revokeObjectURL(revoke) }
  }, [asset.product_id, asset.image_path])

  return url
    ? <img src={url} alt="" className="rounded-lg object-cover shrink-0" style={{ width: 34, height: 34, border: '1px solid var(--border)' }} />
    : <div className="rounded-lg flex items-center justify-center shrink-0" style={{ width: 34, height: 34, background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
        <Boxes size={15} style={{ color: 'var(--text-muted)' }} />
      </div>
}

function StateBadge({ state, label }) {
  const tone = STATE_TONE[state] || 'var(--text-muted)'
  return (
    <span className="text-[10.5px] font-bold px-2 py-0.5 rounded-md whitespace-nowrap"
      style={{ color: tone, background: `color-mix(in srgb, ${tone} 13%, transparent)` }}>
      {label}
    </span>
  )
}

/** Read-only detail: the register's own record plus its full event history. */
function AssetDetail({ employeeId, asset, onClose }) {
  const navigate = useNavigate()
  const [full, setFull] = useState(null)

  useEffect(() => {
    let alive = true
    hrApi.employees.asset(employeeId, asset.id)
      .then(r => { if (alive) setFull(r) })
      .catch(() => {})
    return () => { alive = false }
  }, [employeeId, asset.id])

  const events = full?.events || []

  return (
    <div onClick={onClose} className="fixed inset-0 z-50 flex justify-end" style={{ background: 'rgba(0,0,0,.45)' }}>
      <div onClick={e => e.stopPropagation()} className="h-full overflow-y-auto"
        style={{ width: 460, maxWidth: '96vw', background: 'var(--bg-card)', borderLeft: '1px solid var(--border)' }}>

        <div className="flex items-start justify-between gap-3 p-5" style={{ borderBottom: '1px solid var(--border)' }}>
          <div className="flex items-center gap-3 min-w-0">
            <AssetThumb asset={asset} />
            <div className="min-w-0">
              <h3 className="font-extrabold text-[15px] truncate" style={{ color: 'var(--text-h)' }}>{asset.name}</h3>
              <div className="mt-1"><StateBadge state={asset.state} label={asset.status_label} /></div>
            </div>
          </div>
          <button onClick={onClose} className="p-1 rounded-lg shrink-0" style={{ color: 'var(--text-muted)' }}><X size={17} /></button>
        </div>

        <div className="p-5 space-y-5">
          <Section icon={Boxes} title="Asset">
            <Row k="Asset Code"    v={asset.code} mono />
            <Row k="Serial Number" v={asset.serial_no} mono />
            <Row k="Category"      v={asset.category} />
            <Row k="Brand"         v={asset.brand} />
            <Row k="SKU"           v={asset.sku} mono />
            <Row k="Location"      v={asset.location} />
            <Row k="Condition"     v={asset.condition} />
          </Section>

          <Section icon={ShieldCheck} title="Assignment">
            <Row k="Assigned Date" v={fmt(asset.assigned_at)} />
            <Row k="Assigned By"   v={asset.assigned_by} />
            {asset.returned_at && <Row k="Returned Date" v={fmt(asset.returned_at)} />}
          </Section>

          <Section icon={CalendarClock} title="Warranty & Service">
            <Row k="Warranty Expiry" v={fmt(asset.warranty_until)} />
            <Row k="Next Service Due" v={fmt(asset.next_service_due)} />
          </Section>

          <div>
            <div className="flex items-center gap-1.5 mb-2">
              <Wrench size={13} style={{ color: 'var(--text-muted)' }} />
              <span className="label-caps">Assignment & Maintenance History</span>
            </div>
            {!full ? (
              <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Loading history…</p>
            ) : events.length === 0 ? (
              <p className="text-xs" style={{ color: 'var(--text-muted)' }}>No history recorded.</p>
            ) : (
              <ol className="space-y-2.5">
                {[...events].reverse().map(ev => (
                  <li key={ev.id} className="flex gap-2.5">
                    <span className="rounded-full mt-1.5 shrink-0" style={{ width: 7, height: 7, background: 'var(--text-muted)' }} />
                    <div className="min-w-0">
                      <div className="text-xs font-bold capitalize" style={{ color: 'var(--text-h)' }}>{ev.type}</div>
                      {ev.description && <div className="text-[11.5px]" style={{ color: 'var(--text-muted)' }}>{ev.description}</div>}
                      <div className="text-[10.5px]" style={{ color: 'var(--text-muted)' }}>
                        {fmt(ev.performed_at)}
                        {ev.performer?.name && ` · ${ev.performer.name}`}
                        {ev.employee?.name && ` · ${ev.employee.name}`}
                        {ev.vendor && ` · ${ev.vendor}`}
                      </div>
                    </div>
                  </li>
                ))}
              </ol>
            )}
          </div>

          <button onClick={() => navigate(asset.inventory_url)}
            className="w-full flex items-center justify-center gap-1.5 py-2.5 rounded-xl text-xs font-bold"
            style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }}>
            <ExternalLink size={13} /> Open in Inventory
          </button>
        </div>
      </div>
    </div>
  )
}

const Section = ({ icon: Icon, title, children }) => (
  <div>
    <div className="flex items-center gap-1.5 mb-2">
      <Icon size={13} style={{ color: 'var(--text-muted)' }} />
      <span className="label-caps">{title}</span>
    </div>
    <div className="space-y-1.5">{children}</div>
  </div>
)

const Row = ({ k, v, mono }) => (
  <div className="flex items-baseline justify-between gap-3">
    <span className="text-[11.5px] shrink-0" style={{ color: 'var(--text-muted)' }}>{k}</span>
    <span className={`text-xs font-semibold text-right ${mono ? 'font-mono' : ''}`} style={{ color: 'var(--text-h)' }}>{v || '—'}</span>
  </div>
)
