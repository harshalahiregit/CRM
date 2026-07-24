import { useState, useEffect, useCallback, useRef } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import {
  RefreshCw, Users, UserCheck, HeartPulse, GraduationCap, HardHat, QrCode, UserX, Plus,
} from 'lucide-react'
import { tpvApi } from '@/services/tpvApi'
import { portalApi } from '@/services/portalApi'
import { useAuth } from '@/context/AuthContext'
import { WORKER_STATUS, vendorStatusCfg, canManageTpv, fmtDate } from '../constants'
import {
  KIT3D_STYLE, StatusBadge as StatusPill, Overlay, ModalFooter, Field, TextInput, SelectInput, inputStyle
} from '@/components/ui/kit3d'

// Medical fitness values that clear the gate (mirror TpvMedicalFitness::PASSING).
const PASSING_MEDICAL = ['Fit', 'Fit_With_Restrictions']
const daysUntil = (d) => (d ? Math.ceil((new Date(d) - Date.now()) / 86400000) : null)

// Vendor-scoped Workforce dashboard. All cards are derived client-side from a
// single vendor-filtered worker list (workers.stats is tenant-wide and can't be
// scoped by vendor). This is also the landing screen for the Step-6 CTA.
export default function WorkforceDashboard() {
  const { vendorId } = useParams()   // present inside admin workspace; absent in vendor portal
  const navigate = useNavigate()
  const { user } = useAuth()
  const manage = canManageTpv(user)
  // Portal vendors use portalApi and clean URLs (no vendorId in path).
  const isPortal = user?.role === 'third_party_vendor'
  const api = isPortal ? portalApi : tpvApi
  const base = isPortal ? '/vendor-portal/workforce' : `/app/tpv/workforce/vendor/${vendorId}`

  const [vendor, setVendor] = useState(null)
  const [rows, setRows]     = useState([])
  const [loading, setLoad]  = useState(true)

  const fetchAll = useCallback(async () => {
    setLoad(true)
    try {
      const [vRes, wRes] = await Promise.all([
        isPortal ? api.vendors.get() : api.vendors.get(vendorId).catch(() => null),
        isPortal ? api.workers.list({}) : api.workers.list({ vendor_id: vendorId }),
      ])
      setVendor(vRes?.data ?? vRes ?? null)
      setRows(Array.isArray(wRes?.data ?? wRes) ? (wRes.data ?? wRes) : [])
    } catch (e) { console.error('Failed to load workforce dashboard', e) }
    finally { setLoad(false) }
  }, [vendorId, isPortal, api])
  useEffect(() => { fetchAll() }, [fetchAll])

  const live = rows.filter(r => r.status !== WORKER_STATUS.TERMINATED)
  const stats = {
    total:      rows.length,
    active:     rows.filter(r => r.status === WORKER_STATUS.ACTIVE).length,
    terminated: rows.filter(r => r.status === WORKER_STATUS.TERMINATED).length,
    pendingMedical:   live.filter(r => !r.medical || !PASSING_MEDICAL.includes(r.medical.fitness_status)).length,
    pendingInduction: live.filter(r => !r.induction || !r.induction.passed).length,
    ppePending:       live.filter(r => r.status === WORKER_STATUS.DRAFT
      && r.medical && PASSING_MEDICAL.includes(r.medical.fitness_status)
      && r.induction?.passed && !r.badge_number).length,
    expiring:   rows.filter(r => r.status === WORKER_STATUS.ACTIVE
      && r.badge_valid_until && daysUntil(r.badge_valid_until) != null
      && daysUntil(r.badge_valid_until) <= 30 && daysUntil(r.badge_valid_until) >= 0).length,
  }

  const cards = [
    { key: 'total',      label: 'Total Workers',     value: stats.total,            color: '#7C3AED', icon: Users,        href: `${base}/workers` },
    { key: 'active',     label: 'Active',            value: stats.active,           color: '#10b981', icon: UserCheck,    href: `${base}/workers?status=${WORKER_STATUS.ACTIVE}` },
    { key: 'medical',    label: 'Pending Medical',   value: stats.pendingMedical,   color: '#ec4899', icon: HeartPulse,   href: `${base}/workers` },
    { key: 'induction',  label: 'Pending Induction', value: stats.pendingInduction, color: '#8b5cf6', icon: GraduationCap, href: `${base}/workers` },
    { key: 'ppe',        label: 'PPE Pending',       value: stats.ppePending,       color: '#f59e0b', icon: HardHat,      href: `${base}/workers` },
    { key: 'expiring',   label: 'Expiring Badges',   value: stats.expiring,         color: '#f97316', icon: QrCode,       href: `${base}/workers?status=${WORKER_STATUS.ACTIVE}` },
    { key: 'terminated', label: 'Terminated',        value: stats.terminated,       color: '#ef4444', icon: UserX,        href: `${base}/workers?status=${WORKER_STATUS.TERMINATED}` },
  ]

  const [creating, setCreating]   = useState(false)
  const [uploading, setUploading] = useState(false)

  const workerHref = (wid) => isPortal
    ? `/vendor-portal/workforce/workers/${wid}`
    : (vendorId ? `/app/tpv/workforce/vendor/${vendorId}/workers/${wid}` : `/app/tpv/workforce/${wid}`)

  return (
    <div style={{ padding: 24, minHeight: '100vh', background: 'var(--bg-global)' }}>
      <style>{KIT3D_STYLE}</style>

      {/* Vendor context header & Quick Actions */}
      <div className="pr-glass" style={{ padding: '18px 20px', marginBottom: 20, borderRadius: 16, display: 'flex', alignItems: 'center', gap: 16, flexWrap: 'wrap' }}>
        <div style={{ width: 52, height: 52, borderRadius: 16, flexShrink: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 20, background: 'linear-gradient(145deg,#9f67ff,#7C3AED)', boxShadow: '0 8px 20px -6px rgba(124,58,237,.6)' }}>🦺</div>
        <div style={{ flex: 1, minWidth: 0 }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
            <h1 style={{ color: 'var(--text-h)', fontSize: 21, fontWeight: 800, margin: 0 }}>{vendor?.company_name || 'Vendor'}</h1>
            {vendor?.vendor_code && <span style={{ color: '#a78bfa', fontWeight: 700, fontSize: 12 }}>{vendor.vendor_code}</span>}
            {vendor && <StatusPill cfg={vendorStatusCfg(vendor.status)} />}
          </div>
          <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '5px 0 0' }}>
            Workforce workspace
            {vendor?.registration_number && ` · Reg. ${vendor.registration_number}`}
            {vendor?.approved_at && ` · active since ${fmtDate(vendor.approved_at)}`}
          </p>
        </div>
        <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap' }}>
          <button onClick={fetchAll} style={ghostBtn}><RefreshCw size={14} /> Refresh</button>
          <button onClick={() => setUploading(true)} style={{ ...ghostBtn, background: '#ecfdf5', color: '#047857', border: '1px solid #a7f3d0', fontWeight: 800 }}>
            📁 Bulk Upload (CSV/Excel)
          </button>
          <button onClick={() => setCreating(true)} style={primaryBtn}>
            <Plus size={15} /> Register Worker
          </button>
        </div>
      </div>

      {loading ? (
        <div style={{ textAlign: 'center', padding: 60, color: 'var(--text-muted)' }}>Loading workforce…</div>
      ) : (
        <>
          {/* KPI Cards Grid */}
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(150px,1fr))', gap: 14, marginBottom: 24 }}>
            {cards.map(c => (
              <div key={c.key} className="pr-kpi pr-lift" onClick={() => navigate(c.href)} style={{ cursor: 'pointer', padding: 18 }}>
                <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 10 }}>
                  <div style={{ width: 34, height: 34, borderRadius: 11, display: 'flex', alignItems: 'center', justifyContent: 'center', background: `${c.color}22` }}>
                    <c.icon size={17} style={{ color: c.color }} />
                  </div>
                </div>
                <div style={{ fontSize: 28, fontWeight: 900, color: c.color, lineHeight: 1 }}>{c.value || 0}</div>
                <div style={{ fontSize: 11.5, color: 'var(--text-muted)', fontWeight: 600, marginTop: 6 }}>{c.label}</div>
              </div>
            ))}
          </div>

          {/* Quick Start Action Bar */}
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 16, flexWrap: 'wrap', gap: 12 }}>
            <div>
              <h2 style={{ color: 'var(--text-h)', fontSize: 17, fontWeight: 800, margin: 0 }}>Workforce &amp; 5-Step Wizard</h2>
              <p style={{ color: 'var(--text-muted)', fontSize: 12, margin: '2px 0 0' }}>Select a worker below to enter Medical, Induction, PPE, and Entry Badge steps.</p>
            </div>
            <button onClick={() => navigate(`${base}/workers`)} style={{ fontSize: 12.5, color: '#a78bfa', background: 'transparent', border: 'none', fontWeight: 800, cursor: 'pointer' }}>
              View All Workers ({rows.length}) →
            </button>
          </div>

          {rows.length === 0 ? (
            <div className="pr-glass" style={{ padding: 40, textAlign: 'center', borderRadius: 16 }}>
              <div style={{ fontSize: 40, marginBottom: 12 }}>🦺</div>
              <h3 style={{ color: 'var(--text-h)', fontSize: 16, fontWeight: 800, margin: '0 0 6px' }}>No Workers Registered Yet</h3>
              <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: '0 0 18px' }}>
                Start by registering workers individually or uploading a bulk CSV/Excel sheet.
              </p>
              <div style={{ display: 'flex', gap: 12, justifyContent: 'center' }}>
                <button onClick={() => setUploading(true)} style={{ ...ghostBtn, background: '#ecfdf5', color: '#047857', border: '1px solid #a7f3d0', fontWeight: 800 }}>
                  📁 Bulk Upload (CSV/Excel)
                </button>
                <button onClick={() => setCreating(true)} style={primaryBtn}>
                  <Plus size={15} /> Register Single Worker
                </button>
              </div>
            </div>
          ) : (
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))', gap: 14 }}>
              {rows.slice(0, 6).map(r => (
                <div key={r.id} className="pr-glass pr-lift" onClick={() => navigate(workerHref(r.id))} style={{ padding: 16, cursor: 'pointer', borderRadius: 14 }}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
                    <div style={{ width: 44, height: 44, borderRadius: 14, flexShrink: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 15, fontWeight: 900, color: '#fff', background: 'linear-gradient(145deg,#9f67ff,#7C3AED)' }}>
                      {r.name?.split(' ').map(n => n[0]).join('').slice(0, 2).toUpperCase()}
                    </div>
                    <div style={{ flex: 1, minWidth: 0 }}>
                      <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
                        <span style={{ color: 'var(--text-h)', fontWeight: 800, fontSize: 14 }}>{r.name}</span>
                        <span style={{ color: '#a78bfa', fontWeight: 700, fontSize: 11 }}>{r.worker_code}</span>
                      </div>
                      <div style={{ color: 'var(--text-muted)', fontSize: 12, marginTop: 2 }}>
                        {r.designation || 'Worker'} {r.age != null && `· ${r.age} yrs`}
                      </div>
                      <div style={{ marginTop: 8, display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
                        <span style={{ fontSize: 11, fontWeight: 700, color: r.status === 'Active' ? '#10b981' : '#f59e0b' }}>
                          Step {r.current_step || 1} of 5
                        </span>
                        <span style={{ fontSize: 11.5, color: '#7C3AED', fontWeight: 800 }}>Open Wizard →</span>
                      </div>
                    </div>
                  </div>
                </div>
              ))}
            </div>
          )}
        </>
      )}

      {creating && <CreateModal vendorId={vendorId} onClose={() => setCreating(false)} onCreated={(id) => { setCreating(false); navigate(workerHref(id)) }} api={api} isPortal={isPortal} />}
      {uploading && <BulkUploadModal vendorId={vendorId} onClose={() => setUploading(false)} onUploaded={() => { setUploading(false); fetchAll() }} api={api} isPortal={isPortal} />}
    </div>
  )
}

function CreateModal({ vendorId, onClose, onCreated, api, isPortal }) {
  const [f, setF] = useState({
    name: '', email: '', mobile: '', gender: 'Male', dob: '', age_reason: '',
    blood_group: '', designation: 'Helper', skill_category: 'Unskilled', aadhar_number: '', photo_file: null, photo_preview: null
  })
  const [saving, setSaving] = useState(false)
  const [showCam, setShowCam] = useState(false)
  const videoRef = useRef(null)
  const canvasRef = useRef(null)

  useEffect(() => {
    let stream = null
    if (showCam) {
      navigator.mediaDevices?.getUserMedia({ video: true })
        .then(s => {
          stream = s
          if (videoRef.current) videoRef.current.srcObject = s
        })
        .catch(err => alert('Unable to access camera: ' + err.message))
    }
    return () => {
      if (stream) stream.getTracks().forEach(t => t.stop())
    }
  }, [showCam])

  const set = (k) => (e) => setF(p => ({ ...p, [k]: e.target.value }))

  const age = f.dob ? Math.floor((Date.now() - new Date(f.dob)) / 31557600000) : null
  const isAgeException = age !== null && (age < 18 || age > 60)

  const create = async () => {
    if (!f.name?.trim()) { alert('Full Name is required.'); return }
    if (!f.gender) { alert('Gender is required.'); return }
    if (!f.dob) { alert('Date of Birth is required.'); return }
    if (isAgeException && !f.age_reason?.trim()) {
      alert('Age Exception Reason is required for underage (<18) or overage (>60) workers.')
      return
    }
    if (!f.designation) { alert('Designation is required.'); return }
    if (!f.skill_category) { alert('Skill Category is required.'); return }

    setSaving(true)
    try {
      const payload = Object.fromEntries(Object.entries(f).filter(([, v]) => v !== '' && v !== null))
      const body = isPortal ? payload : { ...payload, vendor_id: Number(vendorId || 1) }
      const w = await api.workers.create(body)
      onCreated(w?.id ?? w?.data?.id)
    } catch (e) {
      const errObj = e?.response?.data?.errors
      const errText = errObj ? Object.values(errObj).flat().join('\n') : (e?.response?.data?.message || 'Could not register worker')
      alert(errText)
    }
    finally { setSaving(false) }
  }

  return (
    <Overlay onClose={() => !saving && onClose()} width={760}>
      <h2 style={{ color: 'var(--text-h)', margin: '0 0 4px', fontSize: 18, fontWeight: 800 }}>
        ➕ Worker Registration (Step 1)
      </h2>
      <p style={{ color: 'var(--text-muted)', fontSize: 12, margin: '0 0 16px' }}>
        Fill out worker personal details, work classification, and Aadhaar information.
      </p>

      {/* ── Section 1: Personal Information ── */}
      <div style={{ fontSize: 11, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 10, borderBottom: '1px solid var(--border)', paddingBottom: 4 }}>
        📇 Personal Information
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 12, marginBottom: 14 }}>
        <Field label="Full Name *">
          <TextInput value={f.name} onChange={set('name')} placeholder="e.g. Suresh Patil" />
        </Field>
        <Field label="Email">
          <TextInput type="email" value={f.email} onChange={set('email')} placeholder="worker@email.com" />
        </Field>
        <Field label="Mobile">
          <TextInput type="tel" value={f.mobile} onChange={set('mobile')} maxLength={15} placeholder="10-digit mobile" />
        </Field>

        <Field label="Gender *">
          <SelectInput value={f.gender} onChange={set('gender')} pairs options={[['Male', 'Male'], ['Female', 'Female'], ['Transgender', 'Transgender']]} />
        </Field>
        <Field label="Date of Birth *">
          <TextInput type="date" value={f.dob} onChange={set('dob')} />
        </Field>
        <Field label="Age (Auto)">
          <div style={{ ...inputStyle, background: 'var(--bg-input)', display: 'flex', alignItems: 'center', justifyContent: 'space-between', opacity: 0.9 }}>
            <span>{age !== null ? `${age} yrs` : 'Auto'}</span>
            {age !== null && (
              <span style={{ fontSize: 10, fontWeight: 800, padding: '2px 6px', borderRadius: 4, background: age < 18 ? '#fef2f2' : age > 60 ? '#fffbe6' : '#ecfdf5', color: age < 18 ? '#dc2626' : age > 60 ? '#d97706' : '#059669' }}>
                {age < 18 ? 'Underage' : age > 60 ? 'Overage' : 'Valid'}
              </span>
            )}
          </div>
        </Field>

        {isAgeException && (
          <Field label="Age Exception Reason *" full>
            <TextInput value={f.age_reason} onChange={set('age_reason')} placeholder="Provide reason for underage / overage exception..." />
          </Field>
        )}

        <Field label="Blood Group">
          <SelectInput value={f.blood_group} onChange={set('blood_group')} pairs options={[['', '-- Select --'], ['A+', 'A+'], ['A-', 'A-'], ['B+', 'B+'], ['B-', 'B-'], ['AB+', 'AB+'], ['AB-', 'AB-'], ['O+', 'O+'], ['O-', 'O-']]} />
        </Field>
      </div>

      {/* ── Section 2: Work Details ── */}
      <div style={{ fontSize: 11, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 10, borderBottom: '1px solid var(--border)', paddingBottom: 4, marginTop: 14 }}>
        💼 Work Details
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 12, marginBottom: 14 }}>
        <Field label="Designation *">
          <SelectInput value={f.designation} onChange={set('designation')} pairs options={[['Mason', 'Mason'], ['Helper', 'Helper'], ['Electrician', 'Electrician'], ['Carpenter', 'Carpenter'], ['Plumber', 'Plumber'], ['Welder', 'Welder'], ['Supervisor', 'Supervisor'], ['Operator', 'Operator'], ['Fitter', 'Fitter'], ['Rigger', 'Rigger']]} />
        </Field>
        <Field label="Skill Category *">
          <SelectInput value={f.skill_category} onChange={set('skill_category')} pairs options={[['Skilled', 'Skilled'], ['Semi Skilled', 'Semi Skilled'], ['Unskilled', 'Unskilled']]} />
        </Field>
        <Field label="Aadhaar Number (12 digits)">
          <TextInput value={f.aadhar_number} onChange={set('aadhar_number')} maxLength={12} placeholder="12-digit Aadhaar" />
        </Field>
      </div>

      {/* ── Section 3: Profile Photo & Camera ── */}
      <div style={{ fontSize: 11, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 10, borderBottom: '1px solid var(--border)', paddingBottom: 4, marginTop: 14 }}>
        📷 Profile Photo
      </div>

      <div style={{ display: 'flex', alignItems: 'center', gap: 16, marginBottom: 16 }}>
        <div style={{ width: 68, height: 68, borderRadius: 999, border: '2px dashed var(--border)', background: 'var(--bg-input)', display: 'flex', alignItems: 'center', justifyContent: 'center', overflow: 'hidden', flexShrink: 0 }}>
          {f.photo_preview ? (
            <img src={f.photo_preview} alt="Preview" style={{ width: '100%', height: '100%', objectFit: 'cover' }} />
          ) : (
            <span style={{ fontSize: 24, opacity: 0.5 }}>👤</span>
          )}
        </div>

        <div style={{ flex: 1 }}>
          <label style={{ fontSize: 12, color: 'var(--text-muted)', fontWeight: 600, display: 'block', marginBottom: 6 }}>Choose File (JPG/PNG, max 2MB) or Use Live Camera</label>
          <div style={{ display: 'flex', gap: 10, alignItems: 'center' }}>
            <input type="file" accept="image/jpeg,image/png" onChange={e => {
              const file = e.target.files[0]
              if (file) {
                const reader = new FileReader()
                reader.onload = ev => setF(p => ({ ...p, photo_preview: ev.target.result, photo_file: file }))
                reader.readAsDataURL(file)
              }
            }} style={{ ...inputStyle, padding: 6, flex: 1 }} />

            <button type="button" onClick={() => setShowCam(true)} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 8, background: '#7C3AED', color: '#fff', fontWeight: 700, border: 'none', cursor: 'pointer', fontSize: 12.5, whiteSpace: 'nowrap' }}>
              📷 Use Camera
            </button>
          </div>
        </div>
      </div>

      {/* Live Camera Modal */}
      {showCam && (
        <Overlay onClose={() => setShowCam(false)} width={440}>
          <h3 style={{ color: 'var(--text-h)', margin: '0 0 12px', fontSize: 16, fontWeight: 800, display: 'flex', alignItems: 'center', gap: 8 }}>
            📷 Take Photo
          </h3>
          <div style={{ background: '#000', borderRadius: 12, overflow: 'hidden', position: 'relative', minHeight: 240, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
            <video ref={videoRef} autoPlay playsInline style={{ width: '100%', maxHeight: 300, objectFit: 'cover' }} />
            <canvas ref={canvasRef} style={{ display: 'none' }} />
          </div>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginTop: 14 }}>
            <span style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>Position worker facing camera</span>
            <div style={{ display: 'flex', gap: 8 }}>
              <button type="button" onClick={() => setShowCam(false)} style={{ padding: '8px 14px', borderRadius: 8, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer' }}>Cancel</button>
              <button type="button" onClick={() => {
                if (videoRef.current && canvasRef.current) {
                  const video = videoRef.current
                  const canvas = canvasRef.current
                  canvas.width = video.videoWidth || 320
                  canvas.height = video.videoHeight || 240
                  canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height)
                  const url = canvas.toDataURL('image/png')
                  setF(p => ({ ...p, photo_preview: url, photo_file: url }))
                  setShowCam(false)
                }
              }} style={{ padding: '8px 16px', borderRadius: 8, background: '#10b981', color: '#fff', fontWeight: 800, border: 'none', cursor: 'pointer' }}>📸 Capture</button>
            </div>
          </div>
        </Overlay>
      )}

      <ModalFooter onClose={onClose} onConfirm={create} loading={saving} disabled={!f.name || !f.dob || (isAgeException && !f.age_reason)} confirmLabel="Save & Continue to Step 2 →" />
    </Overlay>
  )
}

function BulkUploadModal({ vendorId, onClose, onUploaded, api, isPortal }) {
  const [file, setFile] = useState(null)
  const [uploading, setUploading] = useState(false)
  const [result, setResult] = useState(null)

  const downloadSampleCsv = () => {
    const headers = "Full Name,Gender,DOB,Mobile,Blood Group,Designation,Skill Category,Aadhaar Number\n"
    const sampleRows = "Suresh Patil,Male,1990-05-15,9876543210,B+,Electrician,Skilled,123456789012\n"
                     + "Ramesh Kumar,Male,1992-08-20,9876543211,O+,Mason,Semi Skilled,234567890123\n"
                     + "Priya Sharma,Female,1995-02-10,9876543212,A+,Helper,Unskilled,345678901234"
    const blob = new Blob([headers + sampleRows], { type: 'text/csv;charset=utf-8;' })
    const url = URL.createObjectURL(blob)
    const link = document.createElement("a")
    link.setAttribute("href", url)
    link.setAttribute("download", "workforce_bulk_upload_sample.csv")
    document.body.appendChild(link)
    link.click()
    document.body.removeChild(link)
  }

  const doUpload = async () => {
    if (!file) { alert('Please select a CSV or Excel file.'); return }
    setUploading(true)
    try {
      const res = await api.workers.uploadWorkers(file, vendorId || 1)
      setResult(res)
    } catch (e) {
      alert(e?.response?.data?.message || 'Bulk upload failed')
    } finally {
      setUploading(false)
    }
  }

  return (
    <Overlay onClose={() => !uploading && onClose()} width={620}>
      <h2 style={{ color: 'var(--text-h)', margin: '0 0 4px', fontSize: 18, fontWeight: 800 }}>Bulk Worker Upload</h2>
      <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '0 0 16px' }}>
        Upload a CSV, XLS, XLSX, or ZIP archive file. Columns: Full Name, Gender, DOB, Mobile, Blood Group, Designation, Skill Category, Aadhaar Number, Photo Filename (Optional).
      </p>

      {/* Download Sample Template Banner */}
      <div style={{ padding: '10px 14px', borderRadius: 10, background: '#f0f9ff', border: '1px solid #bae6fd', marginBottom: 16, display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: 10 }}>
        <div>
          <strong style={{ color: '#0369a1', fontSize: 12.5, display: 'block' }}>Need an example template file?</strong>
          <span style={{ fontSize: 11.5, color: '#0284c7' }}>Download sample CSV pre-formatted with required headers and example rows.</span>
        </div>
        <button type="button" onClick={downloadSampleCsv} style={{ padding: '6px 12px', borderRadius: 8, background: '#0284c7', color: '#fff', fontWeight: 800, border: 'none', cursor: 'pointer', fontSize: 12, display: 'inline-flex', alignItems: 'center', gap: 6, whiteSpace: 'nowrap' }}>
          📥 Download Sample Template (.csv)
        </button>
      </div>

      {/* ZIP Photo Instructions Box */}
      <div style={{ padding: '10px 14px', borderRadius: 10, background: 'var(--bg-input)', border: '1px dashed var(--border)', marginBottom: 16, fontSize: 12, color: 'var(--text-muted)' }}>
        💡 <strong>How to upload worker photos in bulk:</strong> Create a <strong style={{ color: 'var(--text-h)' }}>ZIP file</strong> containing your <code style={{ color: '#a78bfa' }}>workers.csv</code> and a <code style={{ color: '#a78bfa' }}>photos/</code> folder with image files named by <strong>Aadhaar Number</strong> (e.g. <code style={{ color: '#10b981' }}>123456789012.jpg</code>) or <strong>Worker Name</strong> (e.g. <code style={{ color: '#10b981' }}>Suresh_Patil.jpg</code>).
      </div>

      {!result ? (
        <Field label="Select Worker File (.csv, .xls, .xlsx, .zip) *" full>
          <input type="file" accept=".csv,.xls,.xlsx,.zip" onChange={e => setFile(e.target.files[0])} style={{ ...inputStyle, padding: 8 }} />
        </Field>
      ) : (
        <div style={{ padding: 16, borderRadius: 12, background: result.status === 'success' ? '#ecfdf5' : '#fef2f2', border: `1px solid ${result.status === 'success' ? '#6ee7b7' : '#fca5a5'}`, marginBottom: 16 }}>
          <strong style={{ color: result.status === 'success' ? '#047857' : '#991b1b', fontSize: 14, display: 'block', marginBottom: 6 }}>
            {result.message}
          </strong>
          {result.errors && result.errors.length > 0 && (
            <ul style={{ margin: '8px 0 0', paddingLeft: 18, fontSize: 12, color: '#b91c1c' }}>
              {result.errors.map((err, i) => <li key={i}>{err}</li>)}
            </ul>
          )}
        </div>
      )}

      <ModalFooter
        onClose={onClose}
        onConfirm={result ? onUploaded : doUpload}
        loading={uploading}
        confirmLabel={result ? 'Done' : 'Upload & Process'}
      />
    </Overlay>
  )
}

const ghostBtn = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 13 }
const primaryBtn = { display: 'inline-flex', alignItems: 'center', gap: 8, padding: '8px 18px', borderRadius: 10, background: 'linear-gradient(135deg,#7C3AED,#6d28d9)', color: '#fff', fontWeight: 700, border: 'none', cursor: 'pointer', fontSize: 13, boxShadow: '0 8px 20px -6px rgba(124,58,237,.6)' }
