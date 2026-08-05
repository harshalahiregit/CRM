import { useState, useEffect, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { Building2, Plus, Search, RefreshCw, CheckCircle2, Eye, CalendarDays } from 'lucide-react'
import { purchaseApi } from '@/services/purchaseApi'
import PurchaseVendorForm, { validatePurchaseVendor } from '@/modules/purchase/components/PurchaseVendorForm'
import PurchaseRegistrationBadge from '@/modules/purchase/components/PurchaseRegistrationBadge'
import TemporaryVendorValidityBadge from '@/modules/purchase/components/TemporaryVendorValidityBadge'
import { PV_DEFAULTS } from '@/modules/purchase/components/purchaseVendorFormConstants'

/**
 * Purchase Vendors — the admin master list for the Purchase-owned vendor entity
 * (/api/purchase/vendors). Independent of the shared Vendor and of TPV.
 */
const inputStyle = { width: '100%', padding: '9px 12px', background: 'var(--bg-input)', border: '1px solid var(--border)', borderRadius: 8, color: 'var(--text-h)', fontSize: 13, outline: 'none', boxSizing: 'border-box' }
const STATUS_COLORS = { Active: '#10b981', Pending_Approval: '#f59e0b', Draft: '#6b7280', On_Hold: '#f59e0b', Rejected: '#ef4444', Blacklisted: '#991b1b', Inactive: '#6b7280' }

export default function PurchaseVendors() {
  const navigate = useNavigate()
  const [rows, setRows] = useState([])
  const [stats, setStats] = useState({ total: 0, active: 0, pending: 0, draft: 0 })
  const [loading, setLoading] = useState(true)
  const [q, setQ] = useState('')
  const [modal, setModal] = useState(null)
  const [saving, setSaving] = useState(false)
  const [err, setErr] = useState('')

  const load = useCallback(() => {
    setLoading(true)
    Promise.all([purchaseApi.vendors.list({ search: q }), purchaseApi.vendors.stats()])
      .then(([list, s]) => { setRows(Array.isArray(list) ? list : (list?.data ?? [])); setStats(s || {}) })
      .catch(() => {})
      .finally(() => setLoading(false))
  }, [q])

  useEffect(() => { load() }, [load])

  const save = async () => {
    const invalid = validatePurchaseVendor(modal)
    if (invalid) { setErr(invalid); return }
    setSaving(true); setErr('')
    try {
      await purchaseApi.vendors.create(modal)
      setModal(null); load()
    } catch (e) {
      const errors = e?.response?.data?.errors
      setErr(errors ? Object.values(errors).flat()[0] : (e?.response?.data?.message || 'Could not create vendor.'))
    } finally { setSaving(false) }
  }

  const activate = async (id) => { try { await purchaseApi.vendors.approve(id); load() } catch { /* noop */ } }

  return (
    <div style={{ padding: 20 }}>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: 12, marginBottom: 16 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          <Building2 size={22} style={{ color: '#7C3AED' }} />
          <h1 style={{ fontSize: 20, fontWeight: 800, color: 'var(--text-h)', margin: 0 }}>Purchase Vendors</h1>
        </div>
        <div style={{ display: 'flex', gap: 8 }}>
          <button onClick={load} style={{ ...btn }}><RefreshCw size={14} /> Refresh</button>
          <button onClick={() => navigate('/app/purchase/kickoff')} style={{ ...btn }}><CalendarDays size={14} /> Kickoff Meetings</button>
          <button onClick={() => { setErr(''); setModal({ company_name: '', email: '', ...PV_DEFAULTS }) }} style={{ ...btn, background: '#7C3AED', color: '#fff', border: 'none' }}><Plus size={14} /> New Vendor</button>
        </div>
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(130px,1fr))', gap: 12, marginBottom: 16 }}>
        {/* Permanent + Temporary sum to Total: both derive from registration_type,
            so no vendor is counted twice or missed. */}
        {[['Total', stats.total], ['Permanent', stats.permanent], ['Temporary', stats.temporary], ['Active', stats.active], ['Pending', stats.pending], ['Draft', stats.draft]].map(([k, v]) => (
          <div key={k} className="card-3d" style={{ padding: 14 }}>
            <div style={{ fontSize: 11, color: 'var(--text-muted)', textTransform: 'uppercase', fontWeight: 700 }}>{k}</div>
            <div style={{ fontSize: 22, fontWeight: 800, color: 'var(--text-h)' }}>{v ?? 0}</div>
          </div>
        ))}
      </div>

      <div style={{ position: 'relative', marginBottom: 12, maxWidth: 340 }}>
        <Search size={15} style={{ position: 'absolute', left: 10, top: 11, color: 'var(--text-muted)' }} />
        <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search vendors…" style={{ ...inputStyle, paddingLeft: 32 }} />
      </div>

      <div className="card-3d" style={{ overflow: 'hidden' }}>
        <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
          <thead>
            <tr style={{ background: 'var(--bg-input)' }}>
              {['Code', 'Company', 'Email', 'Type', 'Remaining Validity', 'Status', ''].map((h) => <th key={h} style={th}>{h}</th>)}
            </tr>
          </thead>
          <tbody>
            {loading ? <tr><td colSpan={6} style={{ padding: 24, textAlign: 'center', color: 'var(--text-muted)' }}>Loading…</td></tr>
              : rows.length === 0 ? <tr><td colSpan={6} style={{ padding: 24, textAlign: 'center', color: 'var(--text-muted)' }}>No purchase vendors yet.</td></tr>
              : rows.map((v) => (
                <tr key={v.id} style={{ borderTop: '1px solid var(--border)' }}>
                  <td style={td}>{v.purchase_vendor_code}</td>
                  <td style={{ ...td, fontWeight: 700, color: 'var(--text-h)' }}>{v.company_name}</td>
                  <td style={td}>{v.email || '—'}</td>
                  <td style={td}><PurchaseRegistrationBadge type={v.registration_type} label={v.registration_type_label} /></td>
                  <td style={td}><TemporaryVendorValidityBadge countdown={v.validity_countdown} compact /></td>
                  <td style={td}><span style={{ fontSize: 11, fontWeight: 700, color: STATUS_COLORS[v.status] || '#6b7280' }}>{v.status_label || v.status}</span></td>
                  <td style={{ ...td, textAlign: 'right', whiteSpace: 'nowrap' }}>
                    {v.status !== 'Active' && <button onClick={() => activate(v.id)} style={{ ...miniBtn, color: '#10b981' }}><CheckCircle2 size={13} /> Activate</button>}
                    <button onClick={() => navigate(`/app/purchase/vendors/${v.id}`)} style={miniBtn}><Eye size={13} /> View</button>
                  </td>
                </tr>
              ))}
          </tbody>
        </table>
      </div>

      {modal && (
        <div style={overlay} onClick={() => setModal(null)}>
          <div className="card-3d" style={{ padding: 0, width: 720, maxWidth: '95vw', maxHeight: '92vh', display: 'flex', flexDirection: 'column' }} onClick={(e) => e.stopPropagation()}>
            <div style={{ padding: '18px 20px', borderBottom: '1px solid var(--border)' }}>
              <h2 style={{ fontSize: 17, fontWeight: 800, color: 'var(--text-h)', margin: 0 }}>New Purchase Vendor</h2>
              <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 2 }}>Vendor Code is auto-generated on save.</div>
            </div>
            <div style={{ padding: 20, overflowY: 'auto' }}>
              <PurchaseVendorForm value={modal} onChange={setModal} mode="create" />
            </div>
            <div style={{ padding: '14px 20px', borderTop: '1px solid var(--border)', display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 8 }}>
              <span style={{ color: '#ef4444', fontSize: 12 }}>{err}</span>
              <div style={{ display: 'flex', gap: 8 }}>
                <button onClick={() => setModal(null)} style={btn}>Cancel</button>
                <button onClick={save} disabled={saving || !modal.company_name} style={{ ...btn, background: '#7C3AED', color: '#fff', border: 'none' }}>{saving ? 'Saving…' : 'Create Vendor'}</button>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}

const btn = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 12px', borderRadius: 8, background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-h)', cursor: 'pointer', fontSize: 13, fontWeight: 700 }
const miniBtn = { display: 'inline-flex', alignItems: 'center', gap: 4, padding: '4px 8px', borderRadius: 6, background: 'transparent', border: '1px solid var(--border)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 11.5, fontWeight: 700, marginLeft: 6 }
const th = { textAlign: 'left', padding: '10px 12px', fontSize: 11, textTransform: 'uppercase', letterSpacing: '.04em', color: 'var(--text-muted)', fontWeight: 700 }
const td = { padding: '10px 12px', color: 'var(--text-muted)' }
const overlay = { position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.5)', display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 60 }
