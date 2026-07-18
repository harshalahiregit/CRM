import { useState, useEffect, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  Plus, RefreshCw, Search, Rocket, Eye, Trash2, ShieldCheck, Clock, CheckCircle, XCircle,
} from 'lucide-react'
import { tpvApi } from '@/services/tpvApi'
import { useAuth } from '@/context/AuthContext'
import { OB_STATUS, OB_STATUS_CONFIG, obStatusCfg, vendorStatusCfg, canManageTpv, fmtDate } from '../constants'
import {
  KIT3D_STYLE, labelStyle, inputStyle, Overlay, ModalFooter, InfoBox,
  Field, SelectInput, ActBtn, StatusBadge as StatusPill,
} from '@/components/ui/kit3d'

export default function TpvOnboardings() {
  const navigate = useNavigate()
  const { user } = useAuth()
  const manage = canManageTpv(user)

  const [rows, setRows]       = useState([])
  const [stats, setStats]     = useState({})
  const [loading, setLoading] = useState(true)
  const [search, setSearch]   = useState('')
  const [filterStatus, setFilterStatus] = useState('All')
  const [creating, setCreating] = useState(false)

  const fetchAll = useCallback(async () => {
    setLoading(true)
    try {
      const [listRes, statRes] = await Promise.all([tpvApi.onboarding.list(), tpvApi.onboarding.stats()])
      setRows(Array.isArray(listRes?.data ?? listRes) ? (listRes.data ?? listRes) : [])
      setStats(statRes?.data ?? statRes ?? {})
    } catch (e) { console.error('Failed to load onboardings', e) }
    finally { setLoading(false) }
  }, [])
  useEffect(() => { fetchAll() }, [fetchAll])

  const filtered = rows.filter(r => {
    const q = search.toLowerCase()
    const matchSearch = !q || r.vendor?.company_name?.toLowerCase().includes(q) || r.vendor?.vendor_code?.toLowerCase().includes(q)
    return matchSearch && (filterStatus === 'All' || r.status === filterStatus)
  })

  const remove = async (r) => {
    if (!confirm(`Delete the onboarding for ${r.vendor?.company_name}?`)) return
    try { await tpvApi.onboarding.delete(r.id); fetchAll() }
    catch (e) { alert(e?.response?.data?.message || 'Delete failed') }
  }

  const statCards = [
    { label: 'Total',       value: stats.total,       color: '#7C3AED', filter: 'All' },
    { label: 'In Progress', value: stats.in_progress, color: '#0ea5e9', filter: OB_STATUS.IN_PROGRESS },
    { label: 'Awaiting',    value: stats.awaiting,    color: '#f59e0b', filter: OB_STATUS.SUBMITTED },
    { label: 'Approved',    value: stats.approved,    color: '#10b981', filter: OB_STATUS.APPROVED },
    { label: 'Rejected',    value: stats.rejected,    color: '#ef4444', filter: OB_STATUS.REJECTED },
  ]

  return (
    <div style={{ padding: 24, minHeight: '100vh', background: 'var(--bg-global)' }}>
      <style>{KIT3D_STYLE}</style>

      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 16, flexWrap: 'wrap', gap: 12 }}>
        <div>
          <h1 style={{ color: 'var(--text-h)', fontSize: 22, fontWeight: 800, margin: 0 }}>Vendor Onboarding</h1>
          <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '4px 0 0' }}>Six-step HSSE onboarding — profile, statutory documents, review and activation.</p>
        </div>
        <div style={{ display: 'flex', gap: 10 }}>
          <button onClick={fetchAll} style={{ display: 'flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 13 }}>
            <RefreshCw size={14} /> Refresh
          </button>
          {manage && (
            <button onClick={() => setCreating(true)} style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '8px 18px', borderRadius: 10, background: 'linear-gradient(135deg,#7C3AED,#6d28d9)', color: '#fff', fontWeight: 700, border: 'none', cursor: 'pointer', fontSize: 13, boxShadow: '0 8px 20px -6px rgba(124,58,237,.6)' }}>
              <Plus size={15} /> Start Onboarding
            </button>
          )}
        </div>
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(5,1fr)', gap: 12, marginBottom: 20 }}>
        {statCards.map(s => (
          <div key={s.label} className="pr-kpi" onClick={() => setFilterStatus(s.filter)}
            style={{ textAlign: 'center', outline: filterStatus === s.filter && s.filter !== 'All' ? `1.5px solid ${s.color}` : 'none' }}>
            <div style={{ fontSize: 24, fontWeight: 900, color: s.color }}>{s.value || 0}</div>
            <div style={{ fontSize: 10.5, color: 'var(--text-muted)', fontWeight: 600, marginTop: 3 }}>{s.label}</div>
          </div>
        ))}
      </div>

      <div className="pr-glass" style={{ padding: '14px 18px', marginBottom: 18, display: 'flex', gap: 12, alignItems: 'center', borderRadius: 14 }}>
        <div style={{ position: 'relative', flex: 1 }}>
          <Search size={14} style={{ position: 'absolute', left: 10, top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)' }} />
          <input value={search} onChange={e => setSearch(e.target.value)} placeholder="Search vendor name or code…" style={{ ...inputStyle, paddingLeft: 32 }} />
        </div>
        <select value={filterStatus} onChange={e => setFilterStatus(e.target.value)} style={{ ...inputStyle, width: 'auto', cursor: 'pointer' }}>
          <option value="All">All Status</option>
          {Object.values(OB_STATUS).map(s => <option key={s} value={s}>{OB_STATUS_CONFIG[s]?.label || s}</option>)}
        </select>
      </div>

      {loading ? (
        <div style={{ textAlign: 'center', padding: 60, color: 'var(--text-muted)' }}>Loading onboardings…</div>
      ) : filtered.length === 0 ? (
        <div className="pr-glass" style={{ padding: 60, textAlign: 'center' }}>
          <div style={{ width: 60, height: 60, borderRadius: 18, margin: '0 auto 16px', display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'linear-gradient(145deg,#7C3AED,#5b21b6)', boxShadow: '0 10px 24px -6px rgba(124,58,237,.6)' }}>
            <Rocket size={26} color="#fff" />
          </div>
          <h3 style={{ color: 'var(--text-h)', fontSize: 16, fontWeight: 800, margin: '0 0 6px' }}>No onboardings yet</h3>
          <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: '0 0 18px' }}>Start onboarding a vendor to collect their statutory documents and activate site access.</p>
          {manage && <button onClick={() => setCreating(true)} style={{ display: 'inline-flex', alignItems: 'center', gap: 8, padding: '9px 20px', borderRadius: 10, background: 'linear-gradient(135deg,#7C3AED,#6d28d9)', color: '#fff', fontWeight: 700, border: 'none', cursor: 'pointer', fontSize: 13 }}><Plus size={15} /> Start Onboarding</button>}
        </div>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
          {filtered.map(r => (
            <div key={r.id} className="pr-glass pr-lift pr-pop" style={{ padding: 20, cursor: 'pointer' }}
              onClick={() => navigate(`/app/tpv/onboarding/${r.id}`)}>
              <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 16 }}>
                <div style={{ flex: 1, minWidth: 0 }}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 8, flexWrap: 'wrap' }}>
                    <span style={{ color: 'var(--text-h)', fontWeight: 700, fontSize: 15 }}>{r.vendor?.company_name || 'Vendor'}</span>
                    <span style={{ color: '#a78bfa', fontWeight: 800, fontSize: 12 }}>{r.vendor?.vendor_code}</span>
                    <StatusPill cfg={obStatusCfg(r.status)} />
                    {r.vendor?.status && <StatusPill cfg={vendorStatusCfg(r.vendor.status)} />}
                    {r.vendor?.vendor_type === 'temporary' && (
                      <span style={{ padding: '2px 8px', borderRadius: 10, fontSize: 10.5, fontWeight: 700, background: 'rgba(99,102,241,0.12)', color: '#818cf8', border: '1px solid rgba(99,102,241,0.25)' }}>temporary</span>
                    )}
                  </div>
                  <div style={{ display: 'flex', gap: 16, color: 'var(--text-muted)', fontSize: 12, flexWrap: 'wrap' }}>
                    <span>Step {r.current_step} of 6</span>
                    <span>Started {fmtDate(r.created_at)}</span>
                    {r.submitted_at && <span>Submitted {fmtDate(r.submitted_at)}</span>}
                  </div>
                  <div className="pr-bar" style={{ marginTop: 10, maxWidth: 320 }}>
                    <span style={{ width: `${Math.round(((r.current_step || 1) / 6) * 100)}%` }} />
                  </div>
                </div>
                <div style={{ display: 'flex', flexDirection: 'column', gap: 6, flexShrink: 0 }} onClick={e => e.stopPropagation()}>
                  <ActBtn onClick={() => navigate(`/app/tpv/onboarding/${r.id}`)} icon={Eye} color="var(--text-muted)" bg="var(--bg-card)" border>Open Wizard</ActBtn>
                  {manage && r.status === OB_STATUS.IN_PROGRESS && (
                    <ActBtn onClick={() => remove(r)} icon={Trash2} color="#f87171" bg="var(--bg-card)" border>Delete</ActBtn>
                  )}
                </div>
              </div>
            </div>
          ))}
        </div>
      )}

      {creating && <CreateModal onClose={() => setCreating(false)} onCreated={(id) => { setCreating(false); navigate(`/app/tpv/onboarding/${id}`) }} />}
    </div>
  )
}

// ── Start-onboarding modal ───────────────────────────────────────────────────
function CreateModal({ onClose, onCreated }) {
  const [vendors, setVendors] = useState([])
  const [vendorId, setVendorId] = useState('')
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    // Any vendor may be onboarded for TPV; the engagement tag is applied on create.
    tpvApi.vendors.list({ engagement: undefined })
      .then(res => setVendors(Array.isArray(res?.data ?? res) ? (res.data ?? res) : []))
      .catch(() => {})
  }, [])

  const create = async () => {
    if (!vendorId) { alert('Select a vendor.'); return }
    setSaving(true)
    try {
      const ob = await tpvApi.onboarding.create({ vendor_id: Number(vendorId) })
      onCreated(ob?.id ?? ob?.data?.id)
    } catch (e) { alert(e?.response?.data?.message || 'Could not start onboarding') }
    finally { setSaving(false) }
  }

  return (
    <Overlay onClose={() => !saving && onClose()} width={520}>
      <h2 style={{ color: 'var(--text-h)', margin: '0 0 4px', fontSize: 18, fontWeight: 800 }}>Start Vendor Onboarding</h2>
      <p style={{ color: 'var(--text-muted)', fontSize: 12, margin: '0 0 16px' }}>Pick the vendor to take through the six-step HSSE onboarding.</p>
      <InfoBox>The required document set depends on the vendor type — <strong>standard</strong> vendors need the full statutory set, <strong>temporary</strong> vendors a reduced one.</InfoBox>
      <Field label="Vendor">
        <SelectInput value={vendorId} onChange={e => setVendorId(e.target.value)} pairs
          options={[['', 'Select vendor…'], ...vendors.map(v => [String(v.id), `${v.company_name} · ${v.vendor_type}${v.status !== 'Active' ? ` (${v.status_label || v.status})` : ''}`])]} />
      </Field>
      <ModalFooter onClose={onClose} onConfirm={create} loading={saving} disabled={!vendorId} confirmLabel="Start Onboarding" />
    </Overlay>
  )
}
