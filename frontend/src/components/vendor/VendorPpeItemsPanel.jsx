import { useEffect, useState } from 'react'
import { createPortal } from 'react-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { HardHat, Plus, Pencil, Power, Send, X, Package } from 'lucide-react'

/**
 * A vendor's OWN PPE list — the kit the vendor bought itself.
 *
 * This is the vendor's stock, not the company's: it never touches Inventory,
 * and the quantity shown is the vendor's own count. One component serves four
 * surfaces by swapping the client it is handed:
 *
 *   TPV portal       portalApi.ppe.myItems          (manage + issue)
 *   Purchase portal  purchasePortalApi.ppe.myItems  (manage + issue)
 *   TPV admin        tpvApi.ppe.vendorItems         (read-only)
 *   Purchase admin   purchaseApi.ppe.vendorItems    (read-only)
 *
 * `client` is { list, create?, update?, setActive?, imageBlob }. Issuing posts
 * through the module's ordinary worker-issue route with `vendor_ppe_item_id`,
 * so the hand-out lands in the same worker PPE record as Inventory kit.
 *
 * Popups close only from ✕ or Cancel — never a backdrop click — so a
 * half-filled form cannot be lost to a stray tap.
 */
export default function VendorPpeItemsPanel({
  client,
  scopeKey = 'self',
  canManage = false,
  workers = [],
  issue,
  accent = '#f59e0b',
  title = 'My PPE items',
  hint,
}) {
  const qc = useQueryClient()
  const [editing, setEditing] = useState(null)   // {} for new, a row to edit
  const [issuing, setIssuing] = useState(null)
  const [err, setErr] = useState('')

  const queryKey = ['vendor-ppe-items', scopeKey]
  const { data, isLoading, error } = useQuery({
    queryKey,
    queryFn: client.list,
    staleTime: 0,
    refetchOnMount: 'always',
  })
  const rows = data?.data ?? (Array.isArray(data) ? data : [])
  const categories = data?.categories ?? {}

  const refresh = () => {
    qc.invalidateQueries({ queryKey: ['vendor-ppe-items'] })
    // An issue or a return moves a worker's PPE too.
    ;['worker-ppe', 'ppe-summary'].forEach(k => qc.invalidateQueries({ queryKey: [k] }))
  }

  const toggle = useMutation({
    mutationFn: (row) => client.setActive(row.id, !row.is_active),
    onSuccess: refresh,
    onError: (e) => setErr(message(e, 'Could not change that item.')),
  })

  const canIssue = canManage && typeof issue === 'function'

  return (
    <div style={{ marginTop: 22 }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 4, flexWrap: 'wrap' }}>
        <HardHat size={16} style={{ color: accent }} />
        <h2 style={{ margin: 0, fontSize: 15, fontWeight: 800, color: 'var(--text-h)' }}>{title}</h2>
        {canManage && (
          <button type="button" onClick={() => { setErr(''); setEditing({}) }}
            style={{ marginLeft: 'auto', display: 'inline-flex', alignItems: 'center', gap: 6, padding: '7px 13px', borderRadius: 9, border: 'none', background: accent, color: '#fff', fontWeight: 700, fontSize: 12.5, cursor: 'pointer' }}>
            <Plus size={14} /> Add PPE item
          </button>
        )}
      </div>
      <p style={{ margin: '0 0 12px', fontSize: 12, color: 'var(--text-muted)', lineHeight: 1.5 }}>
        {hint ?? (canManage
          ? 'PPE you supply yourself. This is your stock, kept apart from the company Inventory. Issuing an item takes it off your count; a genuine return puts it back.'
          : 'PPE this vendor supplies from its own stock. Kept by the vendor from its portal and separate from the company Inventory.')}
      </p>

      {err && <p style={{ margin: '0 0 10px', fontSize: 12, color: '#d03b3b' }}>{err}</p>}

      {isLoading ? (
        <p style={{ color: 'var(--text-muted)', fontSize: 13 }}>Loading PPE items…</p>
      ) : error ? (
        <p style={{ color: '#d03b3b', fontSize: 13 }}>{message(error, 'Could not load the PPE list.')}</p>
      ) : rows.length === 0 ? (
        <div style={{ padding: '26px 20px', textAlign: 'center', borderRadius: 12, background: 'var(--bg-card)', border: '1px dashed var(--border)' }}>
          <Package size={22} strokeWidth={1.8} style={{ color: 'var(--text-muted)', marginBottom: 8 }} />
          <p style={{ margin: 0, fontSize: 13, fontWeight: 700, color: 'var(--text-h)' }}>No PPE items yet</p>
          <p style={{ margin: '5px 0 0', fontSize: 12, color: 'var(--text-muted)' }}>
            {canManage ? 'Add the helmets, gloves, shoes and other kit you supply to your workers.' : 'This vendor has not listed any PPE of its own.'}
          </p>
        </div>
      ) : (
        <div style={{ borderRadius: 12, border: '1px solid var(--border)', overflowX: 'auto', background: 'var(--bg-card)' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12.5, minWidth: 640 }}>
            <thead>
              <tr style={{ background: 'var(--bg-input)' }}>
                {['Item', 'Category', 'Size', 'In stock', 'With workers', 'Status', ''].map(h => (
                  <th key={h} style={th}>{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {rows.map(r => (
                <tr key={r.id} style={{ borderTop: '1px solid var(--border)', opacity: r.is_active ? 1 : 0.6 }}>
                  <td style={td}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 9 }}>
                      <Thumb row={r} client={client} accent={accent} />
                      <div style={{ minWidth: 0 }}>
                        <div style={{ fontWeight: 700, color: 'var(--text-h)' }}>{r.name}</div>
                        {r.spec && <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>{r.spec}</div>}
                      </div>
                    </div>
                  </td>
                  <td style={tdMuted}>{categories[r.category] || r.category || '—'}</td>
                  <td style={tdMuted}>{r.size || '—'}</td>
                  <td style={{ ...td, fontWeight: 700, fontVariantNumeric: 'tabular-nums', color: Number(r.qty_in_stock) > 0 ? 'var(--text-h)' : '#d03b3b' }}>
                    {fmtQty(r.qty_in_stock)} <span style={{ fontWeight: 400, color: 'var(--text-muted)' }}>{r.unit}</span>
                  </td>
                  <td style={{ ...tdMuted, fontVariantNumeric: 'tabular-nums' }}>{fmtQty(r.issued ?? 0)}</td>
                  <td style={td}>
                    <span style={{ fontSize: 10.5, fontWeight: 700, padding: '3px 8px', borderRadius: 7, whiteSpace: 'nowrap', color: r.is_active ? '#0ca30c' : '#8a8a8a', background: r.is_active ? 'rgba(12,163,12,.12)' : 'rgba(138,138,138,.14)' }}>
                      {r.is_active ? 'Active' : 'Inactive'}
                    </span>
                  </td>
                  <td style={{ ...td, textAlign: 'right', whiteSpace: 'nowrap' }}>
                    {canIssue && r.is_active && (
                      <button type="button" onClick={() => { setErr(''); setIssuing(r) }} disabled={Number(r.qty_in_stock) <= 0} style={miniBtn(Number(r.qty_in_stock) > 0 ? accent : null)}>
                        <Send size={11} /> Issue
                      </button>
                    )}
                    {canManage && (
                      <>
                        <button type="button" onClick={() => { setErr(''); setEditing(r) }} style={miniBtn()}>
                          <Pencil size={11} /> Edit
                        </button>
                        <button type="button" onClick={() => toggle.mutate(r)} disabled={toggle.isPending} style={miniBtn()}>
                          <Power size={11} /> {r.is_active ? 'Deactivate' : 'Activate'}
                        </button>
                      </>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {editing && (
        <ItemFormModal
          row={editing.id ? editing : null}
          categories={categories}
          client={client}
          accent={accent}
          onClose={() => setEditing(null)}
          onSaved={() => { setEditing(null); refresh() }}
        />
      )}

      {issuing && (
        <IssueModal
          item={issuing}
          workers={workers}
          issue={issue}
          accent={accent}
          onClose={() => setIssuing(null)}
          onDone={() => { setIssuing(null); refresh() }}
        />
      )}
    </div>
  )
}

/** The item photo, fetched with the bearer token; an icon when there is none. */
function Thumb({ row, client, accent }) {
  const [url, setUrl] = useState(null)

  useEffect(() => {
    if (!row.has_image || !client.imageBlob) return undefined
    let revoke = null
    client.imageBlob(row.id).then(u => { revoke = u; setUrl(u) }).catch(() => {})
    return () => { if (revoke) URL.revokeObjectURL(revoke) }
  }, [row.id, row.has_image, row.updated_at]) // eslint-disable-line react-hooks/exhaustive-deps

  return url
    ? <img src={url} alt="" style={{ width: 34, height: 34, borderRadius: 8, objectFit: 'cover', flexShrink: 0 }} />
    : <div style={{ width: 34, height: 34, borderRadius: 8, flexShrink: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', background: `color-mix(in srgb, ${accent} 12%, transparent)` }}>
        <HardHat size={16} style={{ color: accent }} />
      </div>
}

/** Add or edit an item. Sent as FormData so an optional photo can ride along. */
function ItemFormModal({ row, categories, client, accent, onClose, onSaved }) {
  const [form, setForm] = useState({
    name: row?.name ?? '',
    category: row?.category ?? 'helmet',
    size: row?.size ?? '',
    spec: row?.spec ?? '',
    unit: row?.unit ?? 'pcs',
    qty_in_stock: row ? String(row.qty_in_stock ?? 0) : '',
    notes: row?.notes ?? '',
  })
  const [image, setImage] = useState(null)
  const [err, setErr] = useState('')

  const set = (k) => (e) => { setForm(f => ({ ...f, [k]: e.target.value })); setErr('') }
  const valid = form.name.trim() && form.category && form.qty_in_stock !== '' && Number(form.qty_in_stock) >= 0

  const save = useMutation({
    mutationFn: () => {
      const fd = new FormData()
      Object.entries(form).forEach(([k, v]) => fd.append(k, v ?? ''))
      if (image) fd.append('image', image)
      return row ? client.update(row.id, fd) : client.create(fd)
    },
    onSuccess: onSaved,
    onError: (e) => setErr(message(e, 'Could not save this item.')),
  })

  const options = Object.keys(categories).length ? categories : { other: 'Other' }

  return createPortal(
    <div style={overlay}>
      <div style={{ ...dialog, width: 460 }}>
        <button type="button" onClick={onClose} aria-label="Close" style={closeBtn}><X size={17} /></button>
        <h3 style={{ margin: 0, fontSize: 16, fontWeight: 800, color: 'var(--text-h)', paddingRight: 24 }}>
          {row ? `Edit ${row.name}` : 'Add PPE item'}
        </h3>
        <p style={{ margin: '4px 0 6px', fontSize: 12, color: 'var(--text-muted)' }}>
          Your own stock — it is not added to the company Inventory.
        </p>

        <Field label="Name *"><input value={form.name} onChange={set('name')} maxLength={160} style={inp} placeholder="e.g. Safety helmet (white)" /></Field>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10 }}>
          <Field label="Type *">
            <select value={form.category} onChange={set('category')} style={inp}>
              {Object.entries(options).map(([k, label]) => <option key={k} value={k}>{label}</option>)}
            </select>
          </Field>
          <Field label="Size"><input value={form.size} onChange={set('size')} maxLength={40} style={inp} placeholder="e.g. L, 9, Universal" /></Field>
        </div>
        <Field label="Specification"><input value={form.spec} onChange={set('spec')} maxLength={255} style={inp} placeholder="e.g. IS 2925, EN 388" /></Field>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10 }}>
          <Field label="Quantity in stock *"><input type="number" min="0" step="1" value={form.qty_in_stock} onChange={set('qty_in_stock')} style={inp} /></Field>
          <Field label="Unit"><input value={form.unit} onChange={set('unit')} maxLength={20} style={inp} placeholder="pcs, pairs" /></Field>
        </div>
        <Field label="Notes"><textarea value={form.notes} onChange={set('notes')} rows={2} maxLength={2000} style={{ ...inp, resize: 'vertical' }} /></Field>
        <Field label={row?.has_image ? 'Replace photo' : 'Photo (optional)'}>
          <input type="file" accept="image/png,image/jpeg,image/webp" onChange={e => setImage(e.target.files?.[0] ?? null)} style={{ fontSize: 12, color: 'var(--text-muted)' }} />
        </Field>

        {err && <p style={{ margin: '10px 0 0', fontSize: 12, color: '#d03b3b' }}>{err}</p>}

        <div style={{ display: 'flex', gap: 8, marginTop: 18 }}>
          <button type="button" onClick={onClose} style={cancelBtn}>Cancel</button>
          <button type="button" onClick={() => save.mutate()} disabled={!valid || save.isPending} style={primaryBtn(valid, accent)}>
            {save.isPending ? 'Saving…' : row ? 'Save changes' : 'Add item'}
          </button>
        </div>
      </div>
    </div>,
    document.body
  )
}

/** Give one of the vendor's own items to one of its workers. */
function IssueModal({ item, workers, issue, accent, onClose, onDone }) {
  const [workerId, setWorkerId] = useState('')
  const [qty, setQty] = useState(1)
  const [size, setSize] = useState(item.size || '')
  const [err, setErr] = useState('')

  const available = Number(item.qty_in_stock) || 0
  const requested = Number(qty) || 0
  const short = requested > available
  const valid = workerId && requested > 0 && !short

  const act = useMutation({
    mutationFn: () => issue(workerId, { vendor_ppe_item_id: item.id, qty: requested, size: size || null }),
    onSuccess: onDone,
    onError: (e) => setErr(message(e, 'Could not issue this item.')),
  })

  return createPortal(
    <div style={overlay}>
      <div style={{ ...dialog, width: 420 }}>
        <button type="button" onClick={onClose} aria-label="Close" style={closeBtn}><X size={17} /></button>
        <h3 style={{ margin: 0, fontSize: 16, fontWeight: 800, color: 'var(--text-h)', paddingRight: 24 }}>Issue {item.name}</h3>
        <p style={{ margin: '4px 0 6px', fontSize: 12, color: 'var(--text-muted)' }}>From your own PPE stock.</p>

        <Field label="Worker *">
          <select value={workerId} onChange={e => { setWorkerId(e.target.value); setErr('') }} style={inp}>
            <option value="">Select worker…</option>
            {workers.map(w => (
              <option key={w.id} value={w.id}>
                {(w.full_name || w.name) + (w.worker_code ? ` · ${w.worker_code}` : '')}
              </option>
            ))}
          </select>
        </Field>
        {workers.length === 0 && (
          <p style={{ margin: '6px 0 0', fontSize: 11.5, color: 'var(--text-muted)' }}>No workers yet — register one under Workforce first.</p>
        )}
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10 }}>
          <Field label="Quantity *"><input type="number" min="1" value={qty} onChange={e => { setQty(e.target.value); setErr('') }} style={inp} /></Field>
          <Field label="Size"><input value={size} onChange={e => setSize(e.target.value)} maxLength={40} style={inp} /></Field>
        </div>

        <div style={{ marginTop: 14, padding: '10px 13px', borderRadius: 10, background: 'var(--bg-input)', fontSize: 12.5 }}>
          {[['In stock', fmtQty(available)], ['Requested', fmtQty(requested)], ['Left after issue', short ? '—' : fmtQty(available - requested)]].map(([k, v]) => (
            <div key={k} style={{ display: 'flex', justifyContent: 'space-between', padding: '2px 0' }}>
              <span style={{ color: 'var(--text-muted)' }}>{k}</span>
              <strong style={{ color: k === 'Left after issue' && short ? '#d03b3b' : 'var(--text-h)', fontVariantNumeric: 'tabular-nums' }}>{v}</strong>
            </div>
          ))}
        </div>
        {short && <p style={{ margin: '10px 0 0', fontSize: 12, fontWeight: 700, color: '#d03b3b' }}>Only {fmtQty(available)} in stock.</p>}
        {err && <p style={{ margin: '10px 0 0', fontSize: 12, color: '#d03b3b' }}>{err}</p>}

        <div style={{ display: 'flex', gap: 8, marginTop: 18 }}>
          <button type="button" onClick={onClose} style={cancelBtn}>Cancel</button>
          <button type="button" onClick={() => act.mutate()} disabled={!valid || act.isPending} style={primaryBtn(valid, accent)}>
            {act.isPending ? 'Issuing…' : 'Issue'}
          </button>
        </div>
      </div>
    </div>,
    document.body
  )
}

/* ── helpers ──────────────────────────────────────────────────────────── */

/** The server's own words: the field error if there is one, else its message. */
function message(e, fallback) {
  const errors = e?.response?.data?.errors
  if (errors && typeof errors === 'object') {
    const first = Object.values(errors).flat()[0]
    if (first) return first
  }
  return e?.response?.data?.message || e?.message || fallback
}

const fmtQty = (n) => {
  const v = Number(n) || 0
  return Number.isInteger(v) ? String(v) : String(Math.round(v * 1000) / 1000)
}

const Field = ({ label, children }) => (
  <div style={{ marginTop: 12 }}>
    <label style={{ display: 'block', fontSize: 10.5, fontWeight: 700, letterSpacing: '.04em', textTransform: 'uppercase', color: 'var(--text-muted)', marginBottom: 5 }}>{label}</label>
    {children}
  </div>
)

const th = { padding: '8px 12px', textAlign: 'left', fontSize: 9.5, fontWeight: 700, letterSpacing: '.05em', textTransform: 'uppercase', color: 'var(--text-muted)', whiteSpace: 'nowrap' }
const td = { padding: '9px 12px', verticalAlign: 'middle' }
const tdMuted = { ...td, color: 'var(--text-muted)' }
const inp = { width: '100%', padding: '9px 11px', borderRadius: 9, fontSize: 13, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)', boxSizing: 'border-box' }
// No onClick on the overlay: popups close only from ✕ or Cancel.
const overlay = { position: 'fixed', inset: 0, background: 'rgba(0,0,0,.45)', display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 1000, padding: 16 }
const dialog = { maxWidth: '94vw', maxHeight: '92vh', overflowY: 'auto', background: 'var(--bg-card)', border: '1px solid var(--border)', borderRadius: 16, padding: 22, position: 'relative', boxSizing: 'border-box' }
const closeBtn = { position: 'absolute', top: 14, right: 14, background: 'none', border: 'none', cursor: 'pointer', color: 'var(--text-muted)' }
const cancelBtn = { flex: 1, padding: 9, borderRadius: 9, border: '1px solid var(--border)', background: 'transparent', color: 'var(--text-muted)', fontWeight: 700, fontSize: 12.5, cursor: 'pointer' }
const primaryBtn = (on, accent) => ({ flex: 1, padding: 9, borderRadius: 9, border: 'none', fontWeight: 700, fontSize: 12.5, cursor: on ? 'pointer' : 'not-allowed', background: on ? accent : 'var(--bg-input)', color: on ? '#fff' : 'var(--text-muted)' })
const miniBtn = (tone) => ({
  display: 'inline-flex', alignItems: 'center', gap: 4, marginLeft: 6, padding: '5px 10px', borderRadius: 7,
  border: tone ? 'none' : '1px solid var(--border)', background: tone || 'transparent',
  color: tone ? '#fff' : 'var(--text-muted)', fontSize: 11.5, fontWeight: 700, cursor: 'pointer',
})
