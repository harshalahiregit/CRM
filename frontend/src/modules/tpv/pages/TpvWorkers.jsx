import { useState, useEffect, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { Plus, RefreshCw, Search, Eye, Trash2, HardHat, QrCode, AlertTriangle } from 'lucide-react'
import { tpvApi } from '@/services/tpvApi'
import { useAuth } from '@/context/AuthContext'
import {
  WORKER_STATUS, WORKER_STATUS_CONFIG, workerStatusCfg, fitnessCfg,
  SKILL_CATEGORIES, canManageTpv, fmtDate,
} from '../constants'
import {
  KIT3D_STYLE, inputStyle, Overlay, ModalFooter, InfoBox,
  Field, TextInput, SelectInput, ActBtn, StatusBadge as StatusPill,
} from '@/components/ui/kit3d'

export default function TpvWorkers() {
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
      const [listRes, statRes] = await Promise.all([tpvApi.workers.list(), tpvApi.workers.stats()])
      setRows(Array.isArray(listRes?.data ?? listRes) ? (listRes.data ?? listRes) : [])
      setStats(statRes?.data ?? statRes ?? {})
    } catch (e) { console.error('Failed to load workers', e) }
    finally { setLoading(false) }
  }, [])
  useEffect(() => { fetchAll() }, [fetchAll])

  const filtered = rows.filter(r => {
    const q = search.toLowerCase()
    const match = !q || r.name?.toLowerCase().includes(q) || r.worker_code?.toLowerCase().includes(q)
      || r.designation?.toLowerCase().includes(q) || r.vendor?.company_name?.toLowerCase().includes(q)
    return match && (filterStatus === 'All' || r.status === filterStatus)
  })

  const remove = async (r) => {
    if (!confirm(`Delete draft worker ${r.name}?`)) return
    try { await tpvApi.workers.delete(r.id); fetchAll() }
    catch (e) { alert(e?.response?.data?.message || 'Delete failed') }
  }

  const statCards = [
    { label: 'Total',      value: stats.total,      color: '#7C3AED', filter: 'All' },
    { label: 'Draft',      value: stats.draft,      color: '#94a3b8', filter: WORKER_STATUS.DRAFT },
    { label: 'Active',     value: stats.active,     color: '#10b981', filter: WORKER_STATUS.ACTIVE },
    { label: 'Suspended',  value: stats.suspended,  color: '#f59e0b', filter: WORKER_STATUS.SUSPENDED },
    { label: 'Terminated', value: stats.terminated, color: '#ef4444', filter: WORKER_STATUS.TERMINATED },
    { label: 'Expiring 30d', value: stats.expiring, color: '#f59e0b', filter: WORKER_STATUS.ACTIVE },
  ]

  return (
    <div style={{ padding: 24, minHeight: '100vh', background: 'var(--bg-global)' }}>
      <style>{KIT3D_STYLE}</style>

      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 16, flexWrap: 'wrap', gap: 12 }}>
        <div>
          <h1 style={{ color: 'var(--text-h)', fontSize: 22, fontWeight: 800, margin: 0 }}>Workforce</h1>
          <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '4px 0 0' }}>Five-step registration — profile, medical, HSSE induction, PPE and entry badge.</p>
        </div>
        <div style={{ display: 'flex', gap: 10 }}>
          <button onClick={fetchAll} style={{ display: 'flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 13 }}>
            <RefreshCw size={14} /> Refresh
          </button>
          {manage && (
            <button onClick={() => setCreating(true)} style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '8px 18px', borderRadius: 10, background: 'linear-gradient(135deg,#7C3AED,#6d28d9)', color: '#fff', fontWeight: 700, border: 'none', cursor: 'pointer', fontSize: 13, boxShadow: '0 8px 20px -6px rgba(124,58,237,.6)' }}>
              <Plus size={15} /> Register Worker
            </button>
          )}
        </div>
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(6,1fr)', gap: 12, marginBottom: 20 }}>
        {statCards.map(s => (
          <div key={s.label} className="pr-kpi" onClick={() => setFilterStatus(s.filter)} style={{ textAlign: 'center' }}>
            <div style={{ fontSize: 24, fontWeight: 900, color: s.color }}>{s.value || 0}</div>
            <div style={{ fontSize: 10.5, color: 'var(--text-muted)', fontWeight: 600, marginTop: 3 }}>{s.label}</div>
          </div>
        ))}
      </div>

      <div className="pr-glass" style={{ padding: '14px 18px', marginBottom: 18, display: 'flex', gap: 12, alignItems: 'center', borderRadius: 14 }}>
        <div style={{ position: 'relative', flex: 1 }}>
          <Search size={14} style={{ position: 'absolute', left: 10, top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)' }} />
          <input value={search} onChange={e => setSearch(e.target.value)} placeholder="Search name, code, designation or vendor…" style={{ ...inputStyle, paddingLeft: 32 }} />
        </div>
        <select value={filterStatus} onChange={e => setFilterStatus(e.target.value)} style={{ ...inputStyle, width: 'auto', cursor: 'pointer' }}>
          <option value="All">All Status</option>
          {Object.values(WORKER_STATUS).map(s => <option key={s} value={s}>{WORKER_STATUS_CONFIG[s]?.label || s}</option>)}
        </select>
      </div>

      {loading ? (
        <div style={{ textAlign: 'center', padding: 60, color: 'var(--text-muted)' }}>Loading workforce…</div>
      ) : filtered.length === 0 ? (
        <div className="pr-glass" style={{ padding: 60, textAlign: 'center' }}>
          <div style={{ width: 60, height: 60, borderRadius: 18, margin: '0 auto 16px', display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'linear-gradient(145deg,#7C3AED,#5b21b6)', boxShadow: '0 10px 24px -6px rgba(124,58,237,.6)' }}>
            <HardHat size={26} color="#fff" />
          </div>
          <h3 style={{ color: 'var(--text-h)', fontSize: 16, fontWeight: 800, margin: '0 0 6px' }}>No workers registered</h3>
          <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: '0 0 18px' }}>Register a worker to take them through medical, induction, PPE and badging.</p>
          {manage && <button onClick={() => setCreating(true)} style={{ display: 'inline-flex', alignItems: 'center', gap: 8, padding: '9px 20px', borderRadius: 10, background: 'linear-gradient(135deg,#7C3AED,#6d28d9)', color: '#fff', fontWeight: 700, border: 'none', cursor: 'pointer', fontSize: 13 }}><Plus size={15} /> Register Worker</button>}
        </div>
      ) : (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(2,1fr)', gap: 14 }}>
          {filtered.map(r => (
            <div key={r.id} className="pr-glass pr-lift pr-pop" style={{ padding: 18, cursor: 'pointer' }}
              onClick={() => navigate(`/app/tpv/workforce/${r.id}`)}>
              <div style={{ display: 'flex', alignItems: 'flex-start', gap: 12 }}>
                <div style={{ width: 44, height: 44, borderRadius: 14, flexShrink: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 15, fontWeight: 900, color: '#fff', background: 'linear-gradient(145deg,#9f67ff,#7C3AED)', boxShadow: '0 6px 16px -4px rgba(124,58,237,.6)' }}>
                  {r.name?.split(' ').map(n => n[0]).join('').slice(0, 2).toUpperCase()}
                </div>
                <div style={{ flex: 1, minWidth: 0 }}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap', marginBottom: 4 }}>
                    <span style={{ color: 'var(--text-h)', fontWeight: 800, fontSize: 14 }}>{r.name}</span>
                    <span style={{ color: '#a78bfa', fontWeight: 700, fontSize: 11 }}>{r.worker_code}</span>
                    <StatusPill cfg={workerStatusCfg(r.status)} />
                    {r.badge_number && <span style={{ display: 'inline-flex', alignItems: 'center', gap: 3, fontSize: 10.5, fontWeight: 700, color: '#10b981' }}><QrCode size={10} /> {r.badge_number}</span>}
                  </div>
                  <div style={{ display: 'flex', gap: 12, color: 'var(--text-muted)', fontSize: 11.5, flexWrap: 'wrap' }}>
                    {r.designation && <span>{r.designation}</span>}
                    {r.age != null && <span>{r.age} yrs</span>}
                    {r.vendor?.company_name && <span>· {r.vendor.company_name}</span>}
                  </div>
                  <div style={{ display: 'flex', gap: 8, marginTop: 8, alignItems: 'center', flexWrap: 'wrap' }}>
                    {r.medical?.fitness_status && <StatusPill cfg={fitnessCfg(r.medical.fitness_status)} />}
                    {r.induction && (
                      <span style={{ fontSize: 10.5, fontWeight: 700, padding: '2px 8px', borderRadius: 8, background: r.induction.passed ? 'rgba(16,185,129,0.14)' : 'rgba(239,68,68,0.14)', color: r.induction.passed ? '#10b981' : '#ef4444' }}>
                        Induction {r.induction.passed ? 'passed' : 'not passed'}
                      </span>
                    )}
                    {r.status === WORKER_STATUS.ACTIVE && !r.badge_valid && (
                      <span style={{ display: 'inline-flex', alignItems: 'center', gap: 3, fontSize: 10.5, fontWeight: 700, color: '#ef4444' }}><AlertTriangle size={10} /> badge lapsed</span>
                    )}
                  </div>
                  <div className="pr-bar" style={{ marginTop: 10 }}>
                    <span style={{ width: `${Math.round(((r.current_step || 1) / 5) * 100)}%` }} />
                  </div>
                </div>
                <div style={{ display: 'flex', flexDirection: 'column', gap: 6, flexShrink: 0 }} onClick={e => e.stopPropagation()}>
                  <ActBtn onClick={() => navigate(`/app/tpv/workforce/${r.id}`)} icon={Eye} color="var(--text-muted)" bg="var(--bg-card)" border>Open</ActBtn>
                  {manage && r.status === WORKER_STATUS.DRAFT && (
                    <ActBtn onClick={() => remove(r)} icon={Trash2} color="#f87171" bg="var(--bg-card)" border>Delete</ActBtn>
                  )}
                </div>
              </div>
            </div>
          ))}
        </div>
      )}

      {creating && <CreateModal onClose={() => setCreating(false)} onCreated={(id) => { setCreating(false); navigate(`/app/tpv/workforce/${id}`) }} />}
    </div>
  )
}

// ── Register-worker modal ────────────────────────────────────────────────────
function CreateModal({ onClose, onCreated }) {
  const [vendors, setVendors] = useState([])
  const [f, setF] = useState({ vendor_id: '', name: '', dob: '', designation: '', skill_category: '', aadhar_number: '', mobile: '' })
  const [saving, setSaving] = useState(false)
  const set = (k) => (e) => setF(p => ({ ...p, [k]: e.target.value }))

  useEffect(() => {
    tpvApi.vendors.list().then(res => setVendors(Array.isArray(res?.data ?? res) ? (res.data ?? res) : [])).catch(() => {})
  }, [])

  const age = f.dob ? Math.floor((Date.now() - new Date(f.dob)) / 31557600000) : null
  const chosen = vendors.find(v => String(v.id) === String(f.vendor_id))

  const create = async () => {
    if (!f.vendor_id || !f.name) { alert('Vendor and name are required.'); return }
    setSaving(true)
    try {
      const payload = Object.fromEntries(Object.entries(f).filter(([, v]) => v !== ''))
      const w = await tpvApi.workers.create({ ...payload, vendor_id: Number(f.vendor_id) })
      onCreated(w?.id ?? w?.data?.id)
    } catch (e) { alert(e?.response?.data?.message || 'Could not register worker') }
    finally { setSaving(false) }
  }

  return (
    <Overlay onClose={() => !saving && onClose()} width={860}>
      <h2 style={{ color: 'var(--text-h)', margin: '0 0 4px', fontSize: 18, fontWeight: 800 }}>Register Worker</h2>
      <p style={{ color: 'var(--text-muted)', fontSize: 12, margin: '0 0 16px' }}>Step 1 of 5 — the rest of the wizard opens next.</p>

      {chosen && chosen.status !== 'Active' && (
        <InfoBox tone="danger">
          <strong>{chosen.company_name}</strong> is {chosen.status_label || chosen.status}. You can register workers now,
          but no badge can be issued until that vendor's onboarding is approved.
        </InfoBox>
      )}
      {age !== null && age < 18 && <InfoBox tone="danger">This worker is {age} — a badge cannot be issued below 18.</InfoBox>}

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 14 }}>
        <Field label="Employing Vendor *" full>
          <SelectInput value={f.vendor_id} onChange={set('vendor_id')} pairs
            options={[['', 'Select vendor…'], ...vendors.map(v => [String(v.id), `${v.company_name} · ${v.status_label || v.status}`])]} />
        </Field>
        <Field label="Full Name *"><TextInput value={f.name} onChange={set('name')} placeholder="e.g. Suresh Patil" /></Field>
        <Field label="Date of Birth">
          <TextInput type="date" value={f.dob} onChange={set('dob')} />
          {age !== null && <span style={{ fontSize: 11, color: age < 18 ? '#ef4444' : 'var(--text-muted)', fontWeight: 600 }}>Age {age}</span>}
        </Field>
        <Field label="Designation"><TextInput value={f.designation} onChange={set('designation')} placeholder="e.g. Fitter" /></Field>
        <Field label="Skill Category"><SelectInput value={f.skill_category} onChange={set('skill_category')} pairs options={[['', 'Select…'], ...SKILL_CATEGORIES]} /></Field>
        <Field label="Aadhar Number"><TextInput value={f.aadhar_number} onChange={set('aadhar_number')} maxLength={12} placeholder="12 digits" /></Field>
        <Field label="Mobile"><TextInput value={f.mobile} onChange={set('mobile')} /></Field>
      </div>
      <ModalFooter onClose={onClose} onConfirm={create} loading={saving} disabled={!f.vendor_id || !f.name} confirmLabel="Register & Continue" />
    </Overlay>
  )
}
