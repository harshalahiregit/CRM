import { useState, useEffect, useCallback, useMemo } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import {
  RefreshCw, Search, Eye, Truck, PackageCheck, PackageX, FileText,
  CalendarDays, AlertTriangle, Filter, X, ExternalLink,
} from 'lucide-react'
import { purchaseApi } from '@/services/purchaseApi'
import { grnStatusCfg, fmtDate } from '../constants'
import {
  KIT3D_STYLE as PURCHASE_STYLE, labelStyle, inputStyle, Overlay,
  StatusBadge as StatusPill,
} from '@/components/ui/kit3d'

/**
 * Goods Received register.
 *
 * Receipts are RAISED inside a purchase order and always were — this does not
 * duplicate that. It is the other direction: the lookup a stores clerk needs
 * when they hold a delivery note and do not know which PO it belongs to. The
 * sidebar advertised this screen and opened a construction stub instead.
 *
 * Read-only by design. Recording and confirming stay on the PO, where the
 * ordered quantities live, so there is exactly one place a receipt is created.
 */

const STATUSES = ['All', 'Draft', 'Confirmed', 'Cancelled']

const qty = (n) => {
  const v = Number(n) || 0
  return v % 1 === 0 ? String(v) : v.toFixed(2)
}

const lineTotals = (grn) => (grn.items || []).reduce(
  (a, it) => ({
    ordered:  a.ordered  + (Number(it.ordered_qty)  || 0),
    accepted: a.accepted + (Number(it.accepted_qty) || 0),
    rejected: a.rejected + (Number(it.rejected_qty) || 0),
  }),
  { ordered: 0, accepted: 0, rejected: 0 },
)

// ── Headline tiles ───────────────────────────────────────────────────────────
function Tiles({ stats, active, onPick }) {
  const CARDS = [
    { key: 'All',       label: 'Receipts',       value: stats.total,        icon: Truck,         color: '#a78bfa' },
    { key: 'Confirmed', label: 'Confirmed',      value: stats.confirmed,    icon: PackageCheck,  color: '#10b981' },
    { key: 'Draft',     label: 'Draft',          value: stats.draft,        icon: FileText,      color: '#94a3b8' },
    { key: 'rejects',   label: 'With Rejections', value: stats.with_rejections, icon: PackageX,  color: '#f59e0b' },
  ]

  return (
    <div className="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
      {CARDS.map(c => {
        const Icon = c.icon
        const on = active === c.key
        return (
          <button key={c.key} type="button" onClick={() => onPick(on ? 'All' : c.key)}
            title={`Filter by ${c.label}`}
            style={{
              textAlign: 'left', padding: '13px 15px', borderRadius: 16, cursor: 'pointer',
              background: on ? `linear-gradient(135deg, ${c.color}26, ${c.color}0f)` : 'var(--bg-card)',
              border: `1.5px solid ${on ? c.color : 'var(--border)'}`,
            }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 9, marginBottom: 6 }}>
              <span style={{ width: 28, height: 28, borderRadius: 9, display: 'flex', alignItems: 'center', justifyContent: 'center', background: `linear-gradient(145deg, ${c.color}, ${c.color}aa)`, color: '#fff' }}>
                <Icon size={14} />
              </span>
              <span style={{ fontSize: 10.5, fontWeight: 800, letterSpacing: '0.05em', textTransform: 'uppercase', color: 'var(--text-muted)' }}>{c.label}</span>
            </div>
            <p style={{ fontSize: 22, fontWeight: 900, color: 'var(--text-h)', margin: 0 }}>{c.value ?? 0}</p>
          </button>
        )
      })}
    </div>
  )
}

// ── Detail drawer ────────────────────────────────────────────────────────────
function ReceiptDrawer({ grn, onClose }) {
  const t = lineTotals(grn)

  return (
    <Overlay onClose={onClose} width={720} closeOnBackdrop>
      <div style={{ display: 'flex', alignItems: 'flex-start', gap: 12, marginBottom: 18 }}>
        <span style={{ width: 40, height: 40, borderRadius: 12, display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'linear-gradient(145deg,#7C3AED,#6d28d9)', color: '#fff', flexShrink: 0 }}>
          <Truck size={18} />
        </span>
        <div style={{ minWidth: 0 }}>
          <p style={{ fontSize: 17, fontWeight: 900, color: 'var(--text-h)', margin: 0 }}>{grn.grn_number}</p>
          <p style={{ fontSize: 12, color: 'var(--text-muted)', margin: '2px 0 0' }}>
            {grn.vendor?.company_name || '—'}
            {grn.purchase_order?.po_number && <> · against {grn.purchase_order.po_number}</>}
          </p>
        </div>
        <div style={{ marginLeft: 'auto' }}><StatusPill cfg={grnStatusCfg(grn.status)} /></div>
      </div>

      <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
        {[
          ['Received',      fmtDate(grn.received_date)],
          ['Delivery Note', grn.delivery_note_ref || '—'],
          ['Received By',   grn.receiver?.name || '—'],
          ['Lines',         grn.items?.length ?? 0],
        ].map(([label, value]) => (
          <div key={label}>
            <p style={{ ...labelStyle, marginBottom: 3 }}>{label}</p>
            <p style={{ fontSize: 13, fontWeight: 700, color: 'var(--text-h)', margin: 0, wordBreak: 'break-word' }}>{value}</p>
          </div>
        ))}
      </div>

      <p style={labelStyle}>Lines</p>
      <div style={{ overflowX: 'auto', border: '1px solid var(--border)', borderRadius: 12 }}>
        <table style={{ width: '100%', borderCollapse: 'collapse', minWidth: 480 }}>
          <thead>
            <tr style={{ background: 'var(--bg-input)' }}>
              <th style={{ ...labelStyle, textAlign: 'left',  padding: '9px 12px', marginBottom: 0 }}>Description</th>
              <th style={{ ...labelStyle, textAlign: 'right', padding: '9px 12px', marginBottom: 0 }}>Ordered</th>
              <th style={{ ...labelStyle, textAlign: 'right', padding: '9px 12px', marginBottom: 0 }}>Accepted</th>
              <th style={{ ...labelStyle, textAlign: 'right', padding: '9px 12px', marginBottom: 0 }}>Rejected</th>
            </tr>
          </thead>
          <tbody>
            {(grn.items || []).map(it => (
              <tr key={it.id} style={{ borderTop: '1px solid var(--border)' }}>
                <td style={{ padding: '9px 12px', fontSize: 12.5, color: 'var(--text-h)' }}>
                  {it.description || '—'}
                  {it.remarks && <span style={{ display: 'block', fontSize: 11, color: 'var(--text-muted)' }}>{it.remarks}</span>}
                </td>
                {/* Quantities right-aligned with tabular figures so the columns
                    line up digit-for-digit — the scorecard's list rule. */}
                <td style={{ padding: '9px 12px', fontSize: 12.5, textAlign: 'right', fontVariantNumeric: 'tabular-nums', color: 'var(--text-muted)' }}>{qty(it.ordered_qty)}</td>
                <td style={{ padding: '9px 12px', fontSize: 12.5, textAlign: 'right', fontVariantNumeric: 'tabular-nums', fontWeight: 700, color: '#10b981' }}>{qty(it.accepted_qty)}</td>
                <td style={{ padding: '9px 12px', fontSize: 12.5, textAlign: 'right', fontVariantNumeric: 'tabular-nums', fontWeight: 700, color: Number(it.rejected_qty) > 0 ? '#f59e0b' : 'var(--text-muted)' }}>{qty(it.rejected_qty)}</td>
              </tr>
            ))}
          </tbody>
          <tfoot>
            <tr style={{ borderTop: '1.5px solid var(--border)', background: 'var(--bg-input)' }}>
              <td style={{ padding: '9px 12px', fontSize: 12, fontWeight: 800, color: 'var(--text-muted)' }}>Total</td>
              <td style={{ padding: '9px 12px', fontSize: 12.5, textAlign: 'right', fontVariantNumeric: 'tabular-nums', fontWeight: 800, color: 'var(--text-h)' }}>{qty(t.ordered)}</td>
              <td style={{ padding: '9px 12px', fontSize: 12.5, textAlign: 'right', fontVariantNumeric: 'tabular-nums', fontWeight: 800, color: '#10b981' }}>{qty(t.accepted)}</td>
              <td style={{ padding: '9px 12px', fontSize: 12.5, textAlign: 'right', fontVariantNumeric: 'tabular-nums', fontWeight: 800, color: t.rejected > 0 ? '#f59e0b' : 'var(--text-h)' }}>{qty(t.rejected)}</td>
            </tr>
          </tfoot>
        </table>
      </div>

      {grn.notes && (
        <>
          <p style={{ ...labelStyle, marginTop: 16 }}>Notes</p>
          <p style={{ fontSize: 12.5, color: 'var(--text-muted)', margin: 0, whiteSpace: 'pre-wrap' }}>{grn.notes}</p>
        </>
      )}

      {grn.purchase_order?.id && (
        <div style={{ marginTop: 20 }}>
          {/* Everything editable about a receipt lives on its order, so the
              drawer sends you there rather than duplicating the actions. */}
          <Link to={`/app/purchase/orders?open=${grn.purchase_order.id}`}
            style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontSize: 12, fontWeight: 800, color: '#a78bfa' }}>
            Open purchase order {grn.purchase_order.po_number} <ExternalLink size={12} />
          </Link>
        </div>
      )}
    </Overlay>
  )
}

// ── Page ─────────────────────────────────────────────────────────────────────
export default function PurchaseGoodsReceived() {
  const [params, setParams] = useSearchParams()

  const [rows, setRows]       = useState([])
  const [stats, setStats]     = useState({})
  const [loading, setLoading] = useState(true)
  const [error, setError]     = useState(null)
  const [open, setOpen]       = useState(null)

  // Filters live in the URL so a filtered register can be linked and reloaded.
  const status   = params.get('status')   || 'All'
  const search   = params.get('search')   || ''
  const from     = params.get('from')     || ''
  const to       = params.get('to')       || ''
  const rejects  = params.get('rejects')  === '1'

  const [searchDraft, setSearchDraft] = useState(search)
  useEffect(() => { setSearchDraft(search) }, [search])

  const setParam = useCallback((patch) => {
    setParams(prev => {
      const next = new URLSearchParams(prev)
      Object.entries(patch).forEach(([k, v]) => {
        if (v === '' || v == null || v === false || v === 'All') next.delete(k)
        else next.set(k, v === true ? '1' : v)
      })
      return next
    }, { replace: true })
  }, [setParams])

  const load = useCallback(async () => {
    setLoading(true); setError(null)
    try {
      const [list, s] = await Promise.all([
        purchaseApi.receipts.list({
          status: status === 'All' ? undefined : status,
          search: search || undefined,
          from: from || undefined,
          to: to || undefined,
          has_rejections: rejects ? 1 : undefined,
        }),
        purchaseApi.receipts.stats(),
      ])
      setRows(Array.isArray(list) ? list : (list?.data ?? []))
      setStats(s || {})
    } catch (e) {
      // A failed load must not look like an empty register — otherwise the
      // clerk concludes the goods were never received.
      setError(e?.response?.data?.message || 'Could not load goods receipts.')
      setRows([])
    } finally { setLoading(false) }
  }, [status, search, from, to, rejects])

  useEffect(() => { load() }, [load])

  const onTile = (key) => {
    if (key === 'rejects') setParam({ rejects: !rejects, status: 'All' })
    else setParam({ status: key, rejects: false })
  }

  const activeTile = rejects ? 'rejects' : status
  const filtered = useMemo(() => rows, [rows])
  const anyFilter = status !== 'All' || !!search || !!from || !!to || rejects

  return (
    <div>
      <style>{PURCHASE_STYLE}</style>

      <div className="flex items-start justify-between gap-3 flex-wrap mb-4">
        <div>
          <h1 style={{ fontSize: 21, fontWeight: 900, color: 'var(--text-h)', margin: 0 }}>Goods Received</h1>
          <p style={{ fontSize: 12.5, color: 'var(--text-muted)', margin: '4px 0 0' }}>
            Every receipt across purchase orders. Record a new one from its order.
          </p>
        </div>
        <button type="button" onClick={load} className="pr-node"
          style={{ display: 'inline-flex', alignItems: 'center', gap: 7, padding: '9px 14px', borderRadius: 12, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-h)', fontSize: 12.5, fontWeight: 700, cursor: 'pointer' }}>
          <RefreshCw size={14} /> Refresh
        </button>
      </div>

      <Tiles stats={stats} active={activeTile} onPick={onTile} />

      {/* Filters, one row above the list */}
      <div className="flex items-end gap-2 flex-wrap mb-4">
        <div style={{ position: 'relative', flex: '1 1 240px', minWidth: 200 }}>
          <Search size={14} style={{ position: 'absolute', left: 11, top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)' }} />
          <input
            value={searchDraft}
            onChange={e => setSearchDraft(e.target.value)}
            onKeyDown={e => { if (e.key === 'Enter') setParam({ search: searchDraft }) }}
            onBlur={() => setParam({ search: searchDraft })}
            placeholder="GRN number, delivery note, PO or vendor"
            style={{ ...inputStyle, paddingLeft: 32 }} />
        </div>

        <div>
          <label style={labelStyle}>Status</label>
          <select value={status} onChange={e => setParam({ status: e.target.value, rejects: false })} style={{ ...inputStyle, width: 'auto', minWidth: 130 }}>
            {STATUSES.map(s => <option key={s} value={s}>{s}</option>)}
          </select>
        </div>

        <div>
          <label style={labelStyle}>From</label>
          <input type="date" value={from} onChange={e => setParam({ from: e.target.value })} style={{ ...inputStyle, width: 'auto' }} />
        </div>
        <div>
          <label style={labelStyle}>To</label>
          <input type="date" value={to} onChange={e => setParam({ to: e.target.value })} style={{ ...inputStyle, width: 'auto' }} />
        </div>

        <button type="button" onClick={() => setParam({ rejects: !rejects })}
          style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 13px', borderRadius: 10, cursor: 'pointer', fontSize: 12, fontWeight: 700,
            border: `1px solid ${rejects ? '#f59e0b' : 'var(--border)'}`,
            background: rejects ? 'rgba(245,158,11,0.14)' : 'var(--bg-card)',
            color: rejects ? '#f59e0b' : 'var(--text-muted)' }}>
          <AlertTriangle size={13} /> With rejections
        </button>

        {anyFilter && (
          <button type="button" onClick={() => setParams(new URLSearchParams(), { replace: true })}
            style={{ display: 'inline-flex', alignItems: 'center', gap: 5, padding: '9px 12px', borderRadius: 10, cursor: 'pointer', fontSize: 12, fontWeight: 700, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)' }}>
            <X size={13} /> Clear
          </button>
        )}
      </div>

      {/* Error is its own state — never rendered as an empty list. */}
      {error && (
        <div style={{ padding: '14px 16px', borderRadius: 12, border: '1px solid rgba(239,68,68,0.35)', background: 'rgba(239,68,68,0.08)', marginBottom: 14 }}>
          <p style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 13, fontWeight: 700, color: '#f87171', margin: 0 }}>
            <AlertTriangle size={15} /> {error}
          </p>
          <button type="button" onClick={load} style={{ marginTop: 8, fontSize: 12, fontWeight: 700, color: '#a78bfa', cursor: 'pointer', background: 'none', border: 'none', padding: 0 }}>
            Try again
          </button>
        </div>
      )}

      <div className="card-3d" style={{ overflow: 'hidden' }}>
        <div style={{ overflowX: 'auto' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', minWidth: 760 }}>
            <thead>
              <tr style={{ background: 'var(--bg-input)' }}>
                {['GRN', 'Vendor', 'Purchase Order', 'Received', 'Delivery Note'].map(h => (
                  <th key={h} style={{ ...labelStyle, textAlign: 'left', padding: '11px 14px', marginBottom: 0, whiteSpace: 'nowrap' }}>{h}</th>
                ))}
                {['Accepted', 'Rejected'].map(h => (
                  <th key={h} style={{ ...labelStyle, textAlign: 'right', padding: '11px 14px', marginBottom: 0, whiteSpace: 'nowrap' }}>{h}</th>
                ))}
                <th style={{ ...labelStyle, textAlign: 'left', padding: '11px 14px', marginBottom: 0 }}>Status</th>
                <th style={{ ...labelStyle, textAlign: 'right', padding: '11px 14px', marginBottom: 0 }}></th>
              </tr>
            </thead>
            <tbody>
              {/* Skeleton keeps the header in place so the layout does not jump. */}
              {loading && Array.from({ length: 5 }).map((_, i) => (
                <tr key={`sk-${i}`} style={{ borderTop: '1px solid var(--border)' }}>
                  {Array.from({ length: 8 }).map((__, j) => (
                    <td key={j} style={{ padding: '13px 14px' }}>
                      <span style={{ display: 'block', height: 11, borderRadius: 6, background: 'var(--bg-input)' }} />
                    </td>
                  ))}
                </tr>
              ))}

              {!loading && !error && filtered.length === 0 && (
                <tr>
                  <td colSpan={8} style={{ padding: '46px 20px', textAlign: 'center' }}>
                    <Truck size={30} style={{ color: 'var(--text-muted)', opacity: 0.45 }} />
                    {/* "Nothing yet" and "nothing matched" are different problems
                        and need different words and different next actions. */}
                    {anyFilter ? (
                      <>
                        <p style={{ fontSize: 13.5, fontWeight: 800, color: 'var(--text-h)', margin: '10px 0 3px' }}>No receipt matches these filters</p>
                        <p style={{ fontSize: 12, color: 'var(--text-muted)', margin: 0 }}>Try a wider date range, or clear the filters.</p>
                        <button type="button" onClick={() => setParams(new URLSearchParams(), { replace: true })}
                          style={{ marginTop: 10, fontSize: 12, fontWeight: 700, color: '#a78bfa', cursor: 'pointer', background: 'none', border: 'none' }}>
                          Clear filters
                        </button>
                      </>
                    ) : (
                      <>
                        <p style={{ fontSize: 13.5, fontWeight: 800, color: 'var(--text-h)', margin: '10px 0 3px' }}>No goods received yet</p>
                        <p style={{ fontSize: 12, color: 'var(--text-muted)', margin: 0 }}>Receipts are recorded against a purchase order.</p>
                        <Link to="/app/purchase/orders" style={{ display: 'inline-block', marginTop: 10, fontSize: 12, fontWeight: 700, color: '#a78bfa' }}>
                          Go to Purchase Orders
                        </Link>
                      </>
                    )}
                  </td>
                </tr>
              )}

              {!loading && filtered.map(g => {
                const t = lineTotals(g)
                return (
                  <tr key={g.id} className="pr-row" style={{ borderTop: '1px solid var(--border)' }}>
                    <td style={{ padding: '12px 14px', fontSize: 12.5, fontWeight: 800, color: '#a78bfa', whiteSpace: 'nowrap' }}>{g.grn_number}</td>
                    <td style={{ padding: '12px 14px', fontSize: 12.5, color: 'var(--text-h)' }}>{g.vendor?.company_name || '—'}</td>
                    <td style={{ padding: '12px 14px', fontSize: 12.5, color: 'var(--text-muted)', whiteSpace: 'nowrap' }}>{g.purchase_order?.po_number || '—'}</td>
                    <td style={{ padding: '12px 14px', fontSize: 12.5, color: 'var(--text-muted)', whiteSpace: 'nowrap' }}>
                      <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5 }}><CalendarDays size={12} /> {fmtDate(g.received_date)}</span>
                    </td>
                    <td style={{ padding: '12px 14px', fontSize: 12.5, color: 'var(--text-muted)' }}>{g.delivery_note_ref || '—'}</td>
                    <td style={{ padding: '12px 14px', fontSize: 12.5, textAlign: 'right', fontVariantNumeric: 'tabular-nums', fontWeight: 700, color: '#10b981' }}>{qty(t.accepted)}</td>
                    <td style={{ padding: '12px 14px', fontSize: 12.5, textAlign: 'right', fontVariantNumeric: 'tabular-nums', fontWeight: 700, color: t.rejected > 0 ? '#f59e0b' : 'var(--text-muted)' }}>{qty(t.rejected)}</td>
                    <td style={{ padding: '12px 14px' }}><StatusPill cfg={grnStatusCfg(g.status)} /></td>
                    <td style={{ padding: '12px 14px', textAlign: 'right' }}>
                      <button type="button" onClick={() => setOpen(g)} title="View receipt"
                        style={{ padding: 6, borderRadius: 8, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer' }}>
                        <Eye size={13} />
                      </button>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      </div>

      {open && <ReceiptDrawer grn={open} onClose={() => setOpen(null)} />}
    </div>
  )
}
