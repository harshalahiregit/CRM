import { useState, useEffect, useCallback } from 'react'
import { Building2, Plus, Loader2, Inbox } from 'lucide-react'
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
          vendorName={vendorName}
          onClose={() => setAdding(false)}
          onSave={async (data) => { await api.vendors.customers.create(vendorId, data); setAdding(false); load() }}
        />
      )}
    </div>
  )
}

function AddCustomerModal({ vendorName, onClose, onSave }) {
  const [form, setForm] = useState({ company: '', phone: '', website: '', gst_number: '', city: '', state: '', country: '' })
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const set = (k, v) => setForm(f => ({ ...f, [k]: v }))

  const save = async () => {
    if (!form.company.trim()) { setErr('Company name is required.'); return }
    setBusy(true); setErr('')
    try { await onSave({ ...form, company: form.company.trim() }) }
    catch (e) { setErr(e?.response?.data?.message || 'Could not add the customer.'); setBusy(false) }
  }

  return (
    <Overlay onClose={() => !busy && onClose()} width={520}>
      <h2 style={{ color: 'var(--text-h)', margin: '0 0 4px', fontSize: 17, fontWeight: 800 }}>Add Customer</h2>
      <p style={{ margin: '0 0 14px', fontSize: 12, color: 'var(--text-muted)' }}>
        The new customer will be linked to <strong style={{ color: 'var(--text-h)' }}>{vendorName || 'this vendor'}</strong>.
      </p>
      {err && (
        <div style={{ padding: '9px 12px', borderRadius: 10, marginBottom: 12, fontSize: 12.5, color: '#ef4444', background: 'rgba(239,68,68,0.1)', border: '1px solid rgba(239,68,68,0.35)' }}>{err}</div>
      )}
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
    </Overlay>
  )
}

const primaryBtn = {
  display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 9,
  border: 'none', background: 'linear-gradient(135deg,#7C3AED,#6d28d9)', color: '#fff',
  fontWeight: 700, fontSize: 12.5, cursor: 'pointer',
}
