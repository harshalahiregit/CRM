import { useState, useEffect, useCallback } from 'react'
import { Building2, Plus, Loader2, Inbox, Search, Link2 } from 'lucide-react'
import { tpvApi } from '@/services/tpvApi'
import { Overlay, ModalFooter, Field, TextInput } from '@/components/ui/kit3d'

/**
 * Customers directly linked to this TPV vendor (clients.vendor_id).
 *
 * Add Customer creates a real Customer-module record (Client) with vendor_id set,
 * so the link is a first-class relation — not derived from projects. Reads
 * GET /tpv/vendors/{id}/customers; creates via POST the same route.
 */
export function VendorCustomers({ vendorId, vendorName, manage = false, api = tpvApi }) {
  const [rows, setRows] = useState(null)
  const [error, setError] = useState('')
  const [adding, setAdding] = useState(false)

  const load = useCallback(() => {
    setError('')
    api.vendors.customers.list(vendorId)
      .then(setRows)
      .catch(e => setError(e?.response?.data?.message || 'Could not load customers.'))
  }, [vendorId, api])

  useEffect(() => { load() }, [load])

  const th = { textAlign: 'left', padding: '9px 12px', fontSize: 10, textTransform: 'uppercase', letterSpacing: '.04em', color: 'var(--text-muted)', fontWeight: 700, whiteSpace: 'nowrap' }
  const td = { padding: '10px 12px', color: 'var(--text-muted)', fontSize: 13 }

  return (
    <div className="card-3d" style={{ padding: 18 }}>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 14, gap: 10 }}>
        <h2 style={{ fontSize: 15, fontWeight: 800, color: 'var(--text-h)', margin: 0, display: 'inline-flex', alignItems: 'center', gap: 8 }}>
          <Building2 size={16} style={{ color: '#a78bfa' }} /> Customers
          {rows && <span style={{ color: 'var(--text-muted)', fontWeight: 600 }}>· {rows.length}</span>}
        </h2>
        {manage && (
          <button onClick={() => setAdding(true)} style={primaryBtn}><Plus size={14} /> Add Customer</button>
        )}
      </div>

      {error && <p style={{ color: '#ef4444', fontSize: 12.5, margin: '0 0 10px' }}>{error}</p>}

      {rows === null ? (
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, color: 'var(--text-muted)', padding: '18px 0' }}>
          <Loader2 size={15} className="rfq-spin" /> Loading…
        </div>
      ) : rows.length === 0 ? (
        <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 8, padding: '34px 0', color: 'var(--text-muted)' }}>
          <Inbox size={26} style={{ opacity: 0.6 }} />
          <span style={{ fontSize: 13 }}>No customers linked to this vendor yet.</span>
        </div>
      ) : (
        <div style={{ overflowX: 'auto' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse' }}>
            <thead><tr style={{ background: 'var(--bg-input)' }}>
              <th style={th}>Customer</th><th style={th}>Phone</th><th style={th}>Location</th><th style={th}>GST</th><th style={th}>Added</th>
            </tr></thead>
            <tbody>
              {rows.map(c => (
                <tr key={c.id} style={{ borderTop: '1px solid var(--border)' }}>
                  <td style={{ ...td, color: 'var(--text-h)', fontWeight: 700 }}>{c.company || '—'}</td>
                  <td style={td}>{c.phone || '—'}</td>
                  <td style={td}>{[c.city, c.state, c.country].filter(Boolean).join(', ') || '—'}</td>
                  <td style={td}>{c.gst_number || '—'}</td>
                  <td style={td}>{c.created_at ? new Date(c.created_at).toLocaleDateString() : '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {adding && (
        <AddCustomerModal
          api={api}
          vendorId={vendorId}
          vendorName={vendorName}
          onClose={() => setAdding(false)}
          onDone={() => { setAdding(false); load() }}
        />
      )}
    </div>
  )
}

function AddCustomerModal({ api, vendorId, vendorName, onClose, onDone }) {
  // Default to searching existing customers — the senior's ask is to link a
  // registered customer, not always create a new one.
  const [mode, setMode] = useState('search')  // 'search' | 'create'
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  return (
    <Overlay onClose={() => !busy && onClose()} width={560}>
      <h2 style={{ color: 'var(--text-h)', margin: '0 0 4px', fontSize: 17, fontWeight: 800 }}>Add Customer</h2>
      <p style={{ margin: '0 0 12px', fontSize: 12, color: 'var(--text-muted)' }}>
        Link a customer to <strong style={{ color: 'var(--text-h)' }}>{vendorName || 'this vendor'}</strong> — search an existing one or create a new one.
      </p>

      {/* Mode tabs */}
      <div style={{ display: 'flex', gap: 6, marginBottom: 14 }}>
        <button onClick={() => { setMode('search'); setErr('') }} style={{ ...tabBtn, ...(mode === 'search' ? tabActive : {}) }}>
          <Search size={13} /> Search existing
        </button>
        <button onClick={() => { setMode('create'); setErr('') }} style={{ ...tabBtn, ...(mode === 'create' ? tabActive : {}) }}>
          <Plus size={13} /> Create new
        </button>
      </div>

      {err && (
        <div style={{ padding: '9px 12px', borderRadius: 10, marginBottom: 12, fontSize: 12.5, color: '#ef4444', background: 'rgba(239,68,68,0.1)', border: '1px solid rgba(239,68,68,0.35)' }}>{err}</div>
      )}

      {mode === 'search'
        ? <SearchExisting api={api} vendorId={vendorId} setErr={setErr} busy={busy} setBusy={setBusy} onLinked={onDone} onClose={onClose} />
        : <CreateNew api={api} vendorId={vendorId} setErr={setErr} busy={busy} setBusy={setBusy} onCreated={onDone} onClose={onClose} />}
    </Overlay>
  )
}

/* ── Search existing customers and link one ───────────────────────────── */
function SearchExisting({ api, vendorId, setErr, busy, setBusy, onLinked, onClose }) {
  const [q, setQ] = useState('')
  const [rows, setRows] = useState(null)
  const [loading, setLoading] = useState(false)

  // Debounced search — also runs once on mount (empty q → recent unlinked).
  useEffect(() => {
    let alive = true
    setLoading(true)
    const t = setTimeout(() => {
      api.vendors.customers.search(vendorId, q)
        .then(d => { if (alive) setRows(d || []) })
        .catch(() => { if (alive) setRows([]) })
        .finally(() => { if (alive) setLoading(false) })
    }, 250)
    return () => { alive = false; clearTimeout(t) }
  }, [q, vendorId, api])

  const link = async (client) => {
    setBusy(true); setErr('')
    try { await api.vendors.customers.link(vendorId, client.id); onLinked() }
    catch (e) { setErr(e?.response?.data?.message || 'Could not link that customer.'); setBusy(false) }
  }

  return (
    <div>
      <div style={{ position: 'relative', marginBottom: 10 }}>
        <Search size={15} style={{ position: 'absolute', left: 12, top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)' }} />
        <input
          value={q} onChange={e => setQ(e.target.value)} autoFocus
          placeholder="Search by company, phone or GST…"
          style={{ width: '100%', padding: '9px 12px 9px 34px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-input)', color: 'var(--text-h)', fontSize: 13 }}
        />
      </div>

      <div style={{ maxHeight: 300, overflowY: 'auto', border: '1px solid var(--border)', borderRadius: 10 }}>
        {loading && rows === null ? (
          <div style={{ display: 'flex', alignItems: 'center', gap: 8, color: 'var(--text-muted)', padding: 16, fontSize: 13 }}>
            <Loader2 size={14} className="rfq-spin" /> Searching…
          </div>
        ) : (rows && rows.length === 0) ? (
          <div style={{ padding: 20, textAlign: 'center', color: 'var(--text-muted)', fontSize: 13 }}>
            No available customers match. Use “Create new” to add one.
          </div>
        ) : (
          (rows || []).map(c => (
            <div key={c.id} style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 10, padding: '10px 12px', borderTop: '1px solid var(--border)' }}>
              <div style={{ minWidth: 0 }}>
                <div style={{ color: 'var(--text-h)', fontWeight: 700, fontSize: 13, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{c.company || '—'}</div>
                <div style={{ color: 'var(--text-muted)', fontSize: 11.5 }}>
                  {[c.phone, c.gst_number, [c.city, c.state].filter(Boolean).join(', ')].filter(Boolean).join(' · ') || 'No other details'}
                </div>
              </div>
              <button onClick={() => link(c)} disabled={busy} style={{ ...linkBtn, opacity: busy ? 0.6 : 1 }}>
                <Link2 size={13} /> Link
              </button>
            </div>
          ))
        )}
      </div>

      <div style={{ marginTop: 14, display: 'flex', justifyContent: 'flex-end' }}>
        <button onClick={onClose} disabled={busy} style={ghostBtn}>Close</button>
      </div>
    </div>
  )
}

/* ── Create a brand-new customer (original form) ──────────────────────── */
function CreateNew({ api, vendorId, setErr, busy, setBusy, onCreated, onClose }) {
  const [form, setForm] = useState({ company: '', phone: '', website: '', gst_number: '', city: '', state: '', country: '' })
  const set = (k, v) => setForm(f => ({ ...f, [k]: v }))

  const save = async () => {
    if (!form.company.trim()) { setErr('Company name is required.'); return }
    setBusy(true); setErr('')
    try { await api.vendors.customers.create(vendorId, { ...form, company: form.company.trim() }); onCreated() }
    catch (e) { setErr(e?.response?.data?.message || 'Could not add the customer.'); setBusy(false) }
  }

  return (
    <div>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
        <Field label="Company *"><TextInput value={form.company} onChange={e => set('company', e.target.value)} placeholder="Customer company name" autoFocus /></Field>
        <Field label="Phone"><TextInput value={form.phone} onChange={e => set('phone', e.target.value)} placeholder="Phone" /></Field>
        <Field label="Website"><TextInput value={form.website} onChange={e => set('website', e.target.value)} placeholder="https://" /></Field>
        <Field label="GST Number"><TextInput value={form.gst_number} onChange={e => set('gst_number', e.target.value)} placeholder="GSTIN" /></Field>
        <Field label="City"><TextInput value={form.city} onChange={e => set('city', e.target.value)} placeholder="City" /></Field>
        <Field label="State"><TextInput value={form.state} onChange={e => set('state', e.target.value)} placeholder="State" /></Field>
        <Field label="Country"><TextInput value={form.country} onChange={e => set('country', e.target.value)} placeholder="Country" /></Field>
      </div>
      <ModalFooter onClose={onClose} onConfirm={save} loading={busy} disabled={!form.company.trim()} confirmLabel="Add Customer" />
    </div>
  )
}

const tabBtn = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '7px 12px', borderRadius: 9, border: '1px solid var(--border)', background: 'var(--bg-input)', color: 'var(--text-muted)', fontWeight: 700, fontSize: 12.5, cursor: 'pointer' }
const tabActive = { background: 'linear-gradient(135deg,#7C3AED,#6d28d9)', color: '#fff', borderColor: 'transparent' }
const linkBtn = { display: 'inline-flex', alignItems: 'center', gap: 5, padding: '6px 12px', borderRadius: 8, border: 'none', background: 'linear-gradient(135deg,#7C3AED,#6d28d9)', color: '#fff', fontWeight: 700, fontSize: 12, cursor: 'pointer', whiteSpace: 'nowrap' }
const ghostBtn = { padding: '8px 16px', borderRadius: 9, border: '1px solid var(--border)', background: 'var(--bg-input)', color: 'var(--text-h)', fontWeight: 700, fontSize: 12.5, cursor: 'pointer' }

const primaryBtn = {
  display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 9,
  border: 'none', background: 'linear-gradient(135deg,#7C3AED,#6d28d9)', color: '#fff',
  fontWeight: 700, fontSize: 12.5, cursor: 'pointer',
}
