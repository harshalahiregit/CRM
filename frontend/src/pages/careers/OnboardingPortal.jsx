import { useState, useEffect, useCallback, useRef } from 'react'
import { useParams } from 'react-router-dom'
import {
  ShieldCheck, LayoutDashboard, ListChecks, User, FileText, CheckSquare, Bell, History,
  MessageSquare, Upload, CheckCircle2, XCircle, Clock, Plus, Trash2, Download, RefreshCw,
} from 'lucide-react'
import { onboardingApi } from '@/services/onboardingApi'
import AuditTimeline from '@/components/ui/AuditTimeline'
import { ONBOARDING_DOCS, isDocMandatory } from '@/config/onboardingDocs'
import {
  accent, inputStyle, Card, ProgressBar, Empty, Link, Grid, Input, Sel, Field,
} from '@/pages/careers/OnboardingShared'
import OnboardingFormTab from '@/pages/careers/OnboardingFormTab'
import OfferPortal from '@/pages/careers/OfferPortal'

const EMPTY = {
  personal_details: { dob: '', gender: '', father_name: '', marital_status: '', blood_group: '' },
  address: { current: '', permanent: '', city: '', state: '', pincode: '' },
  emergency_contact: { name: '', relation: '', phone: '' },
  education: [{ degree: '', institution: '', year: '' }],
  experience: [{ company: '', role: '', years: '' }],
  bank_details: { account_name: '', account_number: '', ifsc: '', bank_name: '' },
}
const fmt = (d) => d ? new Date(d).toLocaleString('en-IN', { dateStyle: 'medium', timeStyle: 'short' }) : ''
const DOC_STATUS = (s) => s === 'Verified' ? { c: '#059669', bg: '#ecfdf5', I: CheckCircle2 } : s === 'Rejected' ? { c: '#dc2626', bg: '#fef2f2', I: XCircle } : { c: '#d97706', bg: '#fffbeb', I: Clock }

export default function OnboardingPortal() {
  const { token } = useParams()
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [notFound, setNotFound] = useState(false)
  const [tab, setTab] = useState('dashboard')

  const load = useCallback(async () => {
    try { setData(await onboardingApi.get(token)) } catch { setNotFound(true) } finally { setLoading(false) }
  }, [token])
  useEffect(() => { load() }, [load])

  if (loading) return <Center>Loading your portal…</Center>
  if (notFound || !data) return <Center><h1 style={{ fontSize: 22, fontWeight: 800, color: '#0f172a' }}>This onboarding link is invalid or has expired.</h1></Center>

  const c = data.candidate
  const TABS = [
    { key: 'dashboard', label: 'Dashboard', icon: LayoutDashboard },
    { key: 'progress', label: 'Progress', icon: ListChecks },
    { key: 'profile', label: 'My Profile', icon: User },
    { key: 'documents', label: 'Documents', icon: FileText, badge: data.documents.filter(d => d.status === 'Rejected').length || null },
    { key: 'form', label: 'Onboarding Form', icon: ListChecks, badge: null },
    // Unlocks automatically once HR generates/sends the offer — reuses the existing
    // Offer Portal end-to-end (PDF, signature, accept/decline/clarify, pre-joining).
    ...(data.offer?.exists ? [{ key: 'offer', label: 'Offer Letter', icon: FileText, badge: null }] : []),
    { key: 'tasks', label: 'Tasks', icon: CheckSquare },
    { key: 'notifications', label: 'Notifications', icon: Bell, badge: data.notifications.length || null },
    { key: 'timeline', label: 'Timeline', icon: History },
    { key: 'messages', label: 'Messages', icon: MessageSquare, badge: data.communication.length || null },
  ]

  return (
    <div style={{ minHeight: '100vh', background: '#f8fafc', color: '#0f172a', fontFamily: 'system-ui,-apple-system,sans-serif' }}>
      <header style={{ background: '#fff', borderBottom: '1px solid #e2e8f0' }}>
        <div style={{ maxWidth: 920, margin: '0 auto', padding: '14px 24px', display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 10, flexWrap: 'wrap' }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}><ShieldCheck size={20} style={{ color: accent }} /><strong style={{ fontSize: 15 }}>Candidate Portal</strong></div>
          <div style={{ fontSize: 13, color: '#475569' }}>{c.name} · <span style={{ fontFamily: 'monospace' }}>{c.reference}</span></div>
        </div>
      </header>

      <div style={{ maxWidth: 920, margin: '0 auto', padding: '20px 24px 60px' }}>
        {/* Tab bar */}
        <div style={{ display: 'flex', gap: 6, overflowX: 'auto', paddingBottom: 4, marginBottom: 18 }}>
          {TABS.map(t => {
            const on = tab === t.key
            return (
              <button key={t.key} onClick={() => setTab(t.key)} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 14px', borderRadius: 10, border: 'none', cursor: 'pointer', whiteSpace: 'nowrap', fontSize: 13, fontWeight: 700, background: on ? accent : '#fff', color: on ? '#fff' : '#475569', boxShadow: on ? 'none' : 'inset 0 0 0 1px #e2e8f0' }}>
                <t.icon size={14} /> {t.label}
                {t.badge ? <span style={{ fontSize: 10, fontWeight: 800, padding: '1px 6px', borderRadius: 999, background: on ? 'rgba(255,255,255,0.25)' : '#f1f5f9', color: on ? '#fff' : accent }}>{t.badge}</span> : null}
              </button>
            )
          })}
        </div>

        {tab === 'dashboard' && <Dashboard data={data} onGo={setTab} />}
        {tab === 'progress' && <Card title="Recruitment Progress"><ProgressTracker steps={data.progress} /></Card>}
        {tab === 'profile' && <ProfileTab token={token} data={data} onSaved={load} />}
        {tab === 'documents' && <DocumentsTab token={token} data={data} onChanged={load} />}
        {tab === 'form' && <OnboardingFormTab token={token} data={data} onChanged={load} onGoDocs={() => setTab('documents')} />}
        {tab === 'offer' && data.offer?.exists && <OfferPortal token={data.offer.token} embedded />}
        {tab === 'tasks' && <TasksTab tasks={data.tasks} />}
        {tab === 'notifications' && <NotificationsTab items={data.notifications} />}
        {tab === 'timeline' && <Card title="Activity Timeline"><AuditTimeline entries={data.timeline} /></Card>}
        {tab === 'messages' && <MessagesTab items={data.communication} />}
      </div>
    </div>
  )
}

// ── Dashboard ────────────────────────────────────────────────────────────────
function Dashboard({ data, onGo }) {
  const c = data.candidate
  return (
    <div style={{ display: 'grid', gap: 16 }}>
      <div style={{ background: '#fff', border: '1px solid #e2e8f0', borderRadius: 16, padding: 24 }}>
        <h1 style={{ fontSize: 24, fontWeight: 900, margin: 0 }}>Welcome, {c.name} 👋</h1>
        <p style={{ color: '#475569', marginTop: 6, fontSize: 14.5 }}>Track your recruitment and onboarding here.</p>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(150px,1fr))', gap: 12, marginTop: 18 }}>
          <Stat k="Candidate ID" v={c.reference} />
          <Stat k="Applied Job" v={c.applied_job} />
          <Stat k="Current Status" v={c.current_status} highlight />
          <Stat k="Assigned Recruiter" v={c.assigned_recruiter || 'To be assigned'} />
        </div>
      </div>

      <Card title="Progress" action={<Link onClick={() => onGo('progress')}>View all</Link>}><ProgressTracker steps={data.progress} compact /></Card>

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
        <Card title="Onboarding Tasks" action={<Link onClick={() => onGo('tasks')}>Open</Link>}>
          <ProgressBar percent={data.tasks.percent} />
          <p style={{ fontSize: 12.5, color: '#64748b', marginTop: 8 }}>{data.tasks.completed} of {data.tasks.total} completed</p>
        </Card>
        <Card title="Recent Notifications" action={<Link onClick={() => onGo('notifications')}>All</Link>}>
          {data.notifications.slice(0, 3).map((n, i) => <NotifRow key={i} n={n} />)}
          {!data.notifications.length && <Empty>No notifications yet.</Empty>}
        </Card>
      </div>
    </div>
  )
}

// ── Progress Tracker ─────────────────────────────────────────────────────────
function ProgressTracker({ steps, compact }) {
  return (
    <div style={{ display: 'flex', flexWrap: 'wrap', gap: compact ? 6 : 10 }}>
      {steps.map((s, i) => {
        const col = s.status === 'done' ? '#059669' : s.status === 'current' ? accent : '#cbd5e1'
        const bg = s.status === 'done' ? '#ecfdf5' : s.status === 'current' ? `${accent}12` : '#f8fafc'
        return (
          <div key={s.key} style={{ display: 'flex', alignItems: 'center', gap: 6, padding: compact ? '5px 10px' : '8px 14px', borderRadius: 999, background: bg, border: `1px solid ${col}40` }}>
            <span style={{ width: 16, height: 16, borderRadius: '50%', background: col, color: '#fff', fontSize: 10, fontWeight: 800, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>{s.status === 'done' ? '✓' : i + 1}</span>
            <span style={{ fontSize: compact ? 11.5 : 13, fontWeight: 700, color: s.status === 'pending' ? '#94a3b8' : col }}>{s.label}</span>
          </div>
        )
      })}
    </div>
  )
}

// ── Profile ──────────────────────────────────────────────────────────────────
function ProfileTab({ token, data, onSaved }) {
  const [form, setForm] = useState({ ...EMPTY, ...(data.profile.submission || {}) })
  const [busy, setBusy] = useState(false)
  const [msg, setMsg] = useState('')
  const editable = data.profile.editable
  const set = (sec, k, v) => setForm(f => ({ ...f, [sec]: { ...f[sec], [k]: v } }))
  const setRow = (sec, i, k, v) => setForm(f => ({ ...f, [sec]: f[sec].map((r, idx) => idx === i ? { ...r, [k]: v } : r) }))
  const addRow = (sec, tmpl) => setForm(f => ({ ...f, [sec]: [...f[sec], tmpl] }))
  const rmRow = (sec, i) => setForm(f => ({ ...f, [sec]: f[sec].filter((_, idx) => idx !== i) }))

  const save = async () => {
    setBusy(true); setMsg('')
    try { await onboardingApi.submit(token, form, {}); setMsg('Saved. HR will review your details.'); onSaved() }
    catch (e) { setMsg(e?.response?.data?.message || 'Could not save. Try again.') }
    finally { setBusy(false) }
  }

  if (!editable) return <Card title="My Profile"><Empty>Your profile has been approved and is now locked. Contact HR for changes.</Empty></Card>

  return (
    <div style={{ display: 'grid', gap: 16 }}>
      <Card title="Personal Details"><Grid>
        <Field label="Date of Birth"><Input type="date" value={form.personal_details.dob} onChange={e => set('personal_details', 'dob', e.target.value)} /></Field>
        <Field label="Gender"><Sel value={form.personal_details.gender} onChange={e => set('personal_details', 'gender', e.target.value)} opts={['', 'Male', 'Female', 'Other']} /></Field>
        <Field label="Father's / Guardian's Name"><Input value={form.personal_details.father_name} onChange={e => set('personal_details', 'father_name', e.target.value)} /></Field>
        <Field label="Marital Status"><Sel value={form.personal_details.marital_status} onChange={e => set('personal_details', 'marital_status', e.target.value)} opts={['', 'Single', 'Married', 'Other']} /></Field>
        <Field label="Blood Group"><Input value={form.personal_details.blood_group} onChange={e => set('personal_details', 'blood_group', e.target.value)} /></Field>
      </Grid></Card>
      <Card title="Address"><Grid>
        <Field label="Current Address" full><Input value={form.address.current} onChange={e => set('address', 'current', e.target.value)} /></Field>
        <Field label="Permanent Address" full><Input value={form.address.permanent} onChange={e => set('address', 'permanent', e.target.value)} /></Field>
        <Field label="City"><Input value={form.address.city} onChange={e => set('address', 'city', e.target.value)} /></Field>
        <Field label="State"><Input value={form.address.state} onChange={e => set('address', 'state', e.target.value)} /></Field>
        <Field label="Pincode"><Input value={form.address.pincode} onChange={e => set('address', 'pincode', e.target.value)} /></Field>
      </Grid></Card>
      <Card title="Emergency Contact"><Grid>
        <Field label="Name"><Input value={form.emergency_contact.name} onChange={e => set('emergency_contact', 'name', e.target.value)} /></Field>
        <Field label="Relationship"><Input value={form.emergency_contact.relation} onChange={e => set('emergency_contact', 'relation', e.target.value)} /></Field>
        <Field label="Phone"><Input value={form.emergency_contact.phone} onChange={e => set('emergency_contact', 'phone', e.target.value)} /></Field>
      </Grid></Card>
      <Card title="Education" action={<Link onClick={() => addRow('education', { degree: '', institution: '', year: '' })}>+ Add</Link>}>
        {form.education.map((r, i) => <RowGrid key={i} onRemove={form.education.length > 1 ? () => rmRow('education', i) : null}>
          <Input placeholder="Degree" value={r.degree} onChange={e => setRow('education', i, 'degree', e.target.value)} />
          <Input placeholder="Institution" value={r.institution} onChange={e => setRow('education', i, 'institution', e.target.value)} />
          <Input placeholder="Year" value={r.year} onChange={e => setRow('education', i, 'year', e.target.value)} />
        </RowGrid>)}
      </Card>
      <Card title="Experience" action={<Link onClick={() => addRow('experience', { company: '', role: '', years: '' })}>+ Add</Link>}>
        {form.experience.map((r, i) => <RowGrid key={i} onRemove={form.experience.length > 1 ? () => rmRow('experience', i) : null}>
          <Input placeholder="Company" value={r.company} onChange={e => setRow('experience', i, 'company', e.target.value)} />
          <Input placeholder="Role" value={r.role} onChange={e => setRow('experience', i, 'role', e.target.value)} />
          <Input placeholder="Years" value={r.years} onChange={e => setRow('experience', i, 'years', e.target.value)} />
        </RowGrid>)}
      </Card>
      <Card title="Bank Details"><Grid>
        <Field label="Account Holder Name"><Input value={form.bank_details.account_name} onChange={e => set('bank_details', 'account_name', e.target.value)} /></Field>
        <Field label="Account Number"><Input value={form.bank_details.account_number} onChange={e => set('bank_details', 'account_number', e.target.value)} /></Field>
        <Field label="IFSC Code"><Input value={form.bank_details.ifsc} onChange={e => set('bank_details', 'ifsc', e.target.value)} /></Field>
        <Field label="Bank Name"><Input value={form.bank_details.bank_name} onChange={e => set('bank_details', 'bank_name', e.target.value)} /></Field>
      </Grid></Card>
      {msg && <div style={{ fontSize: 13, color: '#475569' }}>{msg}</div>}
      <button onClick={save} disabled={busy} style={{ padding: '12px', borderRadius: 10, background: busy ? '#94a3b8' : accent, color: '#fff', border: 'none', cursor: 'pointer', fontWeight: 700, fontSize: 15 }}>{busy ? 'Saving…' : 'Save Profile'}</button>
    </div>
  )
}

// ── Documents ────────────────────────────────────────────────────────────────
function ReqBadge({ mandatory }) {
  return mandatory
    ? <span style={{ fontSize: 10.5, fontWeight: 800, padding: '1px 7px', borderRadius: 999, background: '#fef2f2', color: '#dc2626' }}>🔴 Mandatory</span>
    : <span style={{ fontSize: 10.5, fontWeight: 800, padding: '1px 7px', borderRadius: 999, background: '#ecfdf5', color: '#059669' }}>🟢 Optional</span>
}

function DocumentsTab({ token, data, onChanged }) {
  const byType = Object.fromEntries(data.documents.map(d => [d.type, d]))
  const [busyType, setBusyType] = useState(null)
  const refs = useRef({})
  const locked = data.verification_status === 'Approved'
  const experienceYears = data.candidate?.experience_years

  const upload = async (type, file) => {
    if (!file) return
    setBusyType(type)
    try { await onboardingApi.uploadDocument(token, type, file); onChanged() }
    catch { /* ignore */ } finally { setBusyType(null) }
  }

  return (
    <div style={{ display: 'grid', gap: 12 }}>
      <div style={{ fontSize: 12.5, color: '#64748b' }}>
        <span style={{ color: '#dc2626', fontWeight: 700 }}>🔴 Mandatory</span> documents are required to complete onboarding · <span style={{ color: '#059669', fontWeight: 700 }}>🟢 Optional</span> are welcome but not required.
      </div>
      {ONBOARDING_DOCS.map(f => {
        const doc = byType[f.key]
        const st = doc ? DOC_STATUS(doc.status) : null
        const mandatory = isDocMandatory(f.key, experienceYears)
        return (
          <div key={f.key} style={{ background: '#fff', border: '1px solid #e2e8f0', borderRadius: 14, padding: 16, display: 'flex', alignItems: 'center', gap: 12, flexWrap: 'wrap' }}>
            <div style={{ flex: 1, minWidth: 180 }}>
              <div style={{ fontSize: 14, fontWeight: 700, display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>{f.label} <ReqBadge mandatory={mandatory} /></div>
              {doc ? (
                <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginTop: 4, flexWrap: 'wrap' }}>
                  <span style={{ fontSize: 12, color: '#64748b' }}>{doc.original_name}</span>
                  <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4, fontSize: 11.5, fontWeight: 700, padding: '2px 8px', borderRadius: 999, background: st.bg, color: st.c }}><st.I size={11} /> {doc.status}</span>
                  {doc.status === 'Rejected' && doc.remarks && <span style={{ fontSize: 12, color: '#dc2626' }}>· {doc.remarks}</span>}
                </div>
              ) : <div style={{ fontSize: 12, color: '#94a3b8', marginTop: 4 }}>Not uploaded yet</div>}
            </div>
            {!locked && (
              <>
                <button onClick={() => refs.current[f.key]?.click()} disabled={busyType === f.key} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 9, border: 'none', cursor: 'pointer', fontSize: 12.5, fontWeight: 700, color: '#fff', background: doc?.status === 'Rejected' ? '#dc2626' : accent }}>
                  {busyType === f.key ? <RefreshCw size={13} className="spin" /> : doc ? <RefreshCw size={13} /> : <Upload size={13} />}
                  {doc ? (doc.status === 'Rejected' ? 'Re-upload' : 'Replace') : 'Upload'}
                </button>
                <input ref={el => refs.current[f.key] = el} type="file" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" style={{ display: 'none' }} onChange={e => upload(f.key, e.target.files?.[0])} />
              </>
            )}
          </div>
        )
      })}
      {locked && <Empty>Your documents are approved and locked.</Empty>}
    </div>
  )
}

// ── Tasks ────────────────────────────────────────────────────────────────────
function TasksTab({ tasks }) {
  return (
    <Card title="My Onboarding Tasks">
      <ProgressBar percent={tasks.percent} />
      <p style={{ fontSize: 12.5, color: '#64748b', margin: '8px 0 14px' }}>{tasks.completed} of {tasks.total} completed</p>
      <div style={{ display: 'grid', gap: 8 }}>
        {tasks.items.map(t => (
          <div key={t.key} style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '10px 12px', borderRadius: 10, background: t.done ? '#ecfdf5' : '#f8fafc', border: '1px solid #e2e8f0' }}>
            {t.done ? <CheckCircle2 size={17} style={{ color: '#059669' }} /> : <Clock size={17} style={{ color: '#d97706' }} />}
            <span style={{ fontSize: 13.5, color: t.done ? '#065f46' : '#334155', textDecoration: t.done ? 'none' : 'none' }}>{t.label}</span>
          </div>
        ))}
      </div>
    </Card>
  )
}

// ── Notifications / Messages ─────────────────────────────────────────────────
function NotificationsTab({ items }) {
  return <Card title="Notifications">{items.length ? items.map((n, i) => <NotifRow key={i} n={n} />) : <Empty>No notifications yet.</Empty>}</Card>
}
function NotifRow({ n }) {
  const col = n.type === 'success' ? '#059669' : n.type === 'warning' ? '#d97706' : '#2563eb'
  return (
    <div style={{ display: 'flex', gap: 10, padding: '10px 0', borderBottom: '1px solid #f1f5f9' }}>
      <span style={{ width: 8, height: 8, borderRadius: '50%', background: col, marginTop: 6, flexShrink: 0 }} />
      <div><div style={{ fontSize: 13.5, fontWeight: 600 }}>{n.title}</div>{n.detail && <div style={{ fontSize: 12.5, color: '#64748b' }}>{n.detail}</div>}<div style={{ fontSize: 11, color: '#94a3b8', marginTop: 2 }}>{fmt(n.at)}</div></div>
    </div>
  )
}
function MessagesTab({ items }) {
  return (
    <Card title="Messages from HR">
      {items.length ? items.map((m, i) => (
        <div key={i} style={{ padding: '12px 14px', borderRadius: 10, background: '#f8fafc', border: '1px solid #e2e8f0', marginBottom: 10 }}>
          <div style={{ fontSize: 11, fontWeight: 700, color: accent }}>{m.from}</div>
          <div style={{ fontSize: 13.5, color: '#334155', marginTop: 3, whiteSpace: 'pre-wrap' }}>{m.body}</div>
          <div style={{ fontSize: 11, color: '#94a3b8', marginTop: 4 }}>{fmt(m.at)}</div>
        </div>
      )) : <Empty>No messages from HR yet.</Empty>}
    </Card>
  )
}

// ── Primitives ───────────────────────────────────────────────────────────────
const Center = ({ children }) => <div style={{ minHeight: '100vh', background: '#f8fafc', display: 'flex', alignItems: 'center', justifyContent: 'center', textAlign: 'center', padding: 24, color: '#64748b', fontFamily: 'system-ui,sans-serif' }}>{children}</div>
const Stat = ({ k, v, highlight }) => (
  <div style={{ background: highlight ? `${accent}0d` : '#f8fafc', border: `1px solid ${highlight ? accent + '30' : '#e2e8f0'}`, borderRadius: 12, padding: '12px 14px' }}>
    <div style={{ fontSize: 10.5, color: '#94a3b8', fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.04em' }}>{k}</div>
    <div style={{ fontSize: 14, fontWeight: 700, marginTop: 3, color: highlight ? accent : '#0f172a' }}>{v || '—'}</div>
  </div>
)
const RowGrid = ({ children, onRemove }) => <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr auto', gap: 10, marginBottom: 10, alignItems: 'center' }}>{children}{onRemove ? <button onClick={onRemove} style={{ background: 'none', border: 'none', cursor: 'pointer', color: '#f87171' }}><Trash2 size={16} /></button> : <span />}</div>
