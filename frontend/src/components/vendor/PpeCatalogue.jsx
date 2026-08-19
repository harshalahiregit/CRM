import { useState, useEffect } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { Package, AlertTriangle, CheckCircle2, XCircle, ArrowUpRight, Boxes } from 'lucide-react'

/**
 * PPE catalogue + summary, read live from INVENTORY.
 *
 * Holds no stock of its own and computes no quantities — every figure comes from
 * the PPE endpoints, which read inventory_stock. Issuing posts through the same
 * service, so the numbers on screen and the numbers in Inventory cannot drift.
 *
 * `api` is the module's own client (tpvApi / portalApi), so TPV and Purchase stay
 * independent while sharing this presentation.
 */

/* Status palette — reserved for state, each paired with an icon AND a word. */
const STATUS = {
  in_stock:     { label: 'In Stock',     tone: '#0ca30c', icon: CheckCircle2 },
  low_stock:    { label: 'Low Stock',    tone: '#fab219', icon: AlertTriangle },
  out_of_stock: { label: 'Out of Stock', tone: '#d03b3b', icon: XCircle },
}

export default function PpeCatalogue({ api, workers = [], canIssue = false, accent = '#7C3AED', onIssued }) {
  const qc = useQueryClient()
  const [issuing, setIssuing] = useState(null)   // the catalogue row being issued

  // staleTime 0: stock changes the moment anyone issues or returns, so a cached
  // figure here would be actively misleading rather than merely stale.
  const common = { staleTime: 0, refetchOnMount: 'always', refetchOnWindowFocus: true }
  const { data: items = [], isLoading } = useQuery({ queryKey: ['ppe-catalogue'], queryFn: api.ppe.catalogue, ...common })
  const { data: summary = {} } = useQuery({ queryKey: ['ppe-summary'], queryFn: api.ppe.summary, ...common })

  const cards = [
    { label: 'Total PPE Items',  value: summary.total_items ?? 0,        icon: Package },
    { label: 'Available Stock',  value: summary.total_available ?? 0,    icon: Boxes },
    { label: 'Low Stock',        value: summary.low_stock_items ?? 0,    icon: AlertTriangle, tone: STATUS.low_stock.tone },
    { label: 'Out of Stock',     value: summary.out_of_stock_items ?? 0, icon: XCircle,       tone: STATUS.out_of_stock.tone },
    { label: 'Issued Today',     value: summary.issued_today ?? 0,       icon: ArrowUpRight },
    { label: 'Returned Today',   value: summary.returned_today ?? 0,     icon: CheckCircle2,  tone: STATUS.in_stock.tone },
  ]

  return (
    <div>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(140px,1fr))', gap: 10, marginBottom: 18 }}>
        {cards.map(c => (
          <div key={c.label} style={{ padding: '13px 15px', borderRadius: 12, background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 7, marginBottom: 7 }}>
              <c.icon size={13} strokeWidth={2.4} style={{ color: c.value ? (c.tone || accent) : 'var(--text-muted)' }} />
              <span style={{ fontSize: 10, fontWeight: 700, letterSpacing: '.04em', textTransform: 'uppercase', color: 'var(--text-muted)' }}>{c.label}</span>
            </div>
            <div style={{ fontSize: 22, fontWeight: 800, lineHeight: 1, fontVariantNumeric: 'tabular-nums', color: c.value ? 'var(--text-h)' : 'var(--text-muted)' }}>
              {c.value}
            </div>
          </div>
        ))}
      </div>

      {isLoading ? (
        <Muted>Loading PPE stock…</Muted>
      ) : items.length === 0 ? (
        <div style={{ padding: '34px 22px', textAlign: 'center', borderRadius: 12, background: 'var(--bg-card)', border: '1px dashed var(--border)' }}>
          <Package size={24} strokeWidth={1.8} style={{ color: 'var(--text-muted)', marginBottom: 9 }} />
          <p style={{ margin: 0, fontSize: 13.5, fontWeight: 700, color: 'var(--text-h)' }}>No PPE items yet</p>
          <p style={{ margin: '6px auto 0', maxWidth: 420, fontSize: 12, color: 'var(--text-muted)', lineHeight: 1.55 }}>
            PPE comes from Inventory. Create a category named <strong>PPE</strong> and add items to it —
            their stock, reorder level and status will appear here automatically.
          </p>
        </div>
      ) : (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill,minmax(280px,1fr))', gap: 12 }}>
          {items.map(it => {
            const st = STATUS[it.status] || STATUS.in_stock
            return (
              <div key={it.product_id} style={{ padding: 15, borderRadius: 12, background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
                <div style={{ display: 'flex', alignItems: 'flex-start', gap: 11, marginBottom: 12 }}>
                  <PpeImage item={it} api={api} accent={accent} />
                  <div style={{ flex: 1, minWidth: 0 }}>
                    <p style={{ margin: 0, fontSize: 13.5, fontWeight: 700, color: 'var(--text-h)' }}>{it.name}</p>
                    <p style={{ margin: '2px 0 0', fontSize: 11, color: 'var(--text-muted)' }}>{it.sku}</p>
                  </div>
                  <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4, fontSize: 10.5, fontWeight: 700, padding: '3px 8px', borderRadius: 7, whiteSpace: 'nowrap', color: st.tone, background: `color-mix(in srgb, ${st.tone} 12%, transparent)` }}>
                    <st.icon size={11} /> {st.label}
                  </span>
                </div>

                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4,1fr)', gap: 8, marginBottom: 12 }}>
                  {[['Available', it.available], ['Issued', it.issued], ['Reserved', it.reserved], ['Reorder', it.reorder_level]].map(([k, v]) => (
                    <div key={k}>
                      <p style={{ margin: 0, fontSize: 9, fontWeight: 700, letterSpacing: '.04em', textTransform: 'uppercase', color: 'var(--text-muted)' }}>{k}</p>
                      <p style={{ margin: '1px 0 0', fontSize: 15, fontWeight: 700, fontVariantNumeric: 'tabular-nums', color: v ? 'var(--text-h)' : 'var(--text-muted)' }}>{v ?? 0}</p>
                    </div>
                  ))}
                </div>

                <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                  {canIssue && (
                    <button
                      onClick={() => setIssuing(it)}
                      disabled={it.available <= 0}
                      style={{
                        padding: '7px 13px', borderRadius: 8, border: 'none', fontSize: 12, fontWeight: 700,
                        cursor: it.available > 0 ? 'pointer' : 'not-allowed',
                        background: it.available > 0 ? accent : 'var(--bg-input)',
                        color: it.available > 0 ? '#fff' : 'var(--text-muted)',
                      }}
                    >Issue</button>
                  )}
                  {/* PPE → Inventory: item detail, stock ledger and movement history. */}
                  <Link to={`/app/inventory/products/${it.product_id}`} style={{ fontSize: 11.5, fontWeight: 700, color: 'var(--text-muted)', textDecoration: 'none' }}>
                    Inventory ↗
                  </Link>
                </div>
              </div>
            )
          })}
        </div>
      )}

      {issuing && (
        <IssueDrawer
          item={issuing} workers={workers} api={api} accent={accent}
          onClose={() => setIssuing(null)}
          onDone={() => {
            setIssuing(null)
            // Both queries refetch, so the catalogue and the summary cards update
            // together the instant stock moves — no manual refresh.
            qc.invalidateQueries({ queryKey: ['ppe-catalogue'] })
            qc.invalidateQueries({ queryKey: ['ppe-summary'] })
            qc.invalidateQueries({ queryKey: ['worker-ppe'] })
            // Issuing also moves the worker's PPE step forward — let the host reload it.
            onIssued?.()
          }}
        />
      )}
    </div>
  )
}

/**
 * The item photo from Inventory. The file is private, so it is fetched with the
 * bearer token and shown as a blob; items without one fall back to the icon.
 */
function PpeImage({ item, api, accent }) {
  const [url, setUrl] = useState(null)

  useEffect(() => {
    if (!item.has_image) return
    let revoke = null
    api.ppe.imageBlob(item.product_id).then(u => { revoke = u; setUrl(u) }).catch(() => {})
    return () => { if (revoke) URL.revokeObjectURL(revoke) }
  }, [item.product_id, item.has_image, api])

  return url
    ? <img src={url} alt="" style={{ width: 42, height: 42, borderRadius: 10, objectFit: 'cover', flexShrink: 0 }} />
    : <div style={{ width: 42, height: 42, borderRadius: 10, flexShrink: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', background: `color-mix(in srgb, ${accent} 12%, transparent)` }}>
        <Package size={19} style={{ color: accent }} />
      </div>
}

/** Issue drawer: worker + quantity, with the arithmetic shown before committing. */
function IssueDrawer({ item, workers, api, accent, onClose, onDone }) {
  const [workerId, setWorkerId] = useState('')
  const [qty, setQty] = useState(1)
  const [err, setErr] = useState('')

  const requested = Number(qty) || 0
  const remaining = item.available - requested
  const short = requested > item.available
  const canSubmit = workerId && requested > 0 && !short

  const issue = useMutation({
    mutationFn: () => api.ppe.issue(workerId, { inventory_item_id: item.product_id, qty: requested }),
    onSuccess: onDone,
    // The server is the authority on stock; surface its message verbatim rather
    // than a generic failure, so "only N available" reaches the user.
    onError: (e) => setErr(e?.response?.data?.message || 'Could not issue this item.'),
  })

  return (
    <div style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,.45)', display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 60 }}>
      <div onClick={e => e.stopPropagation()} style={{ width: 420, maxWidth: '94vw', background: 'var(--bg-card)', border: '1px solid var(--border)', borderRadius: 16, padding: 22 }}>
        <h3 style={{ margin: 0, fontSize: 16, fontWeight: 800, color: 'var(--text-h)' }}>Issue {item.name}</h3>
        <p style={{ margin: '4px 0 16px', fontSize: 12, color: 'var(--text-muted)' }}>{item.sku}</p>

        <Field label="Worker">
          <select value={workerId} onChange={e => { setWorkerId(e.target.value); setErr('') }} style={inputStyle}>
            <option value="">Select worker…</option>
            {workers.map(w => <option key={w.id} value={w.id}>{w.full_name || w.name}</option>)}
          </select>
        </Field>

        <Field label="Quantity">
          <input type="number" min="1" value={qty} onChange={e => { setQty(e.target.value); setErr('') }} style={inputStyle} />
        </Field>

        {/* The sum, before committing — so nobody has to guess what will be left. */}
        <div style={{ marginTop: 14, padding: '11px 13px', borderRadius: 10, background: 'var(--bg-input)', fontSize: 12.5 }}>
          {[['Available', item.available], ['Requested', requested],
            ['Remaining after issue', short ? '—' : remaining]].map(([k, v]) => (
            <div key={k} style={{ display: 'flex', justifyContent: 'space-between', padding: '2px 0' }}>
              <span style={{ color: 'var(--text-muted)' }}>{k}</span>
              <strong style={{ color: k === 'Remaining after issue' && short ? '#d03b3b' : 'var(--text-h)', fontVariantNumeric: 'tabular-nums' }}>{v}</strong>
            </div>
          ))}
        </div>

        {short && (
          <p style={{ margin: '10px 0 0', fontSize: 12, fontWeight: 700, color: '#d03b3b' }}>
            Only {item.available} {item.name}{item.available === 1 ? ' is' : ' are'} available.
          </p>
        )}
        {err && <p style={{ margin: '10px 0 0', fontSize: 12, color: '#d03b3b' }}>{err}</p>}

        <div style={{ display: 'flex', gap: 8, marginTop: 18 }}>
          <button onClick={onClose} style={{ flex: 1, padding: '9px', borderRadius: 9, border: '1px solid var(--border)', background: 'transparent', color: 'var(--text-muted)', fontWeight: 700, fontSize: 12.5, cursor: 'pointer' }}>Cancel</button>
          <button
            onClick={() => issue.mutate()}
            disabled={!canSubmit || issue.isPending}
            style={{
              flex: 1, padding: '9px', borderRadius: 9, border: 'none', fontWeight: 700, fontSize: 12.5,
              cursor: canSubmit ? 'pointer' : 'not-allowed',
              background: canSubmit ? accent : 'var(--bg-input)',
              color: canSubmit ? '#fff' : 'var(--text-muted)',
            }}
          >{issue.isPending ? 'Issuing…' : 'Issue'}</button>
        </div>
      </div>
    </div>
  )
}

const inputStyle = {
  width: '100%', padding: '9px 11px', borderRadius: 9, fontSize: 13,
  background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)',
}
const Field = ({ label, children }) => (
  <div style={{ marginTop: 12 }}>
    <label style={{ display: 'block', fontSize: 10.5, fontWeight: 700, letterSpacing: '.04em', textTransform: 'uppercase', color: 'var(--text-muted)', marginBottom: 5 }}>{label}</label>
    {children}
  </div>
)
const Muted = ({ children }) => <p style={{ color: 'var(--text-muted)', fontSize: 13 }}>{children}</p>
