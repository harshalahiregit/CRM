import { useState, useEffect, useCallback } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import {
  ArrowLeft, User, GraduationCap, Briefcase, FileText, Landmark, ShieldCheck,
  Laptop, Presentation, BookOpen, ListChecks, BadgeCheck, Activity as ActivityIcon,
  Clock, Plus, Pencil, Trash2, Check, X, Info, LayoutDashboard,
} from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import OnboardingVerificationPanel from '@/modules/hr/components/OnboardingVerificationPanel'
import { HrLoading, HrEmpty } from '@/components/ui/HrState'
import AuditTimeline from '@/components/ui/AuditTimeline'
import EmployeeAssetsPanel from '@/modules/hr/components/EmployeeAssetsPanel'
import {
  ONB_STAGES, onbStatusCfg, TASK_STATUS, TASK_STATUSES, TASK_CATEGORIES, BGV_STATUSES, fmtDate, fmtDateTime,
} from '@/modules/hr/employeeOnboardingConstants'

const unwrap = r => r?.data ?? r
const inputStyle = { width: '100%', padding: '9px 12px', borderRadius: 9, border: '1px solid var(--border)', background: 'var(--bg-input)', color: 'var(--text-h)', fontSize: 13, outline: 'none' }
const lbl = { fontSize: 10.5, fontWeight: 700, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.04em', marginBottom: 4, display: 'block' }

const TABS = [
  { key: 'verification', label: 'Verification' },
  { key: 'overview', label: 'Overview', icon: LayoutDashboard },
  { key: 'personal', label: 'Personal', icon: User },
  { key: 'education', label: 'Education', icon: GraduationCap },
  { key: 'experience', label: 'Experience', icon: Briefcase },
  { key: 'documents', label: 'Documents', icon: FileText },
  { key: 'bank', label: 'Bank', icon: Landmark },
  { key: 'compliance', label: 'Compliance', icon: ShieldCheck },
  { key: 'assets', label: 'Assets', icon: Laptop },
  { key: 'orientation', label: 'Orientation', icon: Presentation },
  { key: 'training', label: 'Training', icon: BookOpen },
  { key: 'checklist', label: 'Checklist', icon: ListChecks },
  { key: 'approvals', label: 'Approvals', icon: BadgeCheck },
  { key: 'timeline', label: 'Timeline', icon: Clock },
  { key: 'activity', label: 'Activity', icon: ActivityIcon },
]

// Field specs — one source of truth for the section forms.
const F = {
  personal: [
    ['first_name', 'First Name'], ['middle_name', 'Middle Name'], ['last_name', 'Last Name'],
    ['dob', 'Date of Birth', 'date'], ['gender', 'Gender', 'select', ['Male', 'Female', 'Other', 'Prefer not to say']],
    ['marital_status', 'Marital Status', 'select', ['Single', 'Married', 'Other']],
    ['blood_group', 'Blood Group'], ['father_name', "Father's Name"], ['mother_name', "Mother's Name"],
    ['nationality', 'Nationality'], ['religion', 'Religion'],
  ],
  contact: [['personal_email', 'Personal Email'], ['official_email', 'Official Email'], ['mobile_phone', 'Mobile'], ['alternate_phone', 'Alternate Phone']],
  address: [
    ['current_address', 'Current Address', 'textarea'], ['current_city', 'City'], ['current_state', 'State'], ['current_pincode', 'Pincode'], ['current_country', 'Country'],
    ['permanent_address', 'Permanent Address', 'textarea'], ['permanent_city', 'City'], ['permanent_state', 'State'], ['permanent_pincode', 'Pincode'], ['permanent_country', 'Country'],
    ['same_as_current', 'Permanent same as current', 'checkbox'],
  ],
  emergency: [['emergency_name', 'Name'], ['emergency_relationship', 'Relationship'], ['emergency_phone', 'Phone'], ['emergency_alt_phone', 'Alternate Phone'], ['emergency_address', 'Address', 'textarea']],
  bank: [['bank_account_holder_name', 'Account Holder'], ['bank_account_number', 'Account Number'], ['bank_ifsc', 'IFSC'], ['bank_name', 'Bank Name'], ['bank_branch', 'Branch'], ['bank_account_type', 'Account Type', 'select', ['Savings', 'Current']]],
  compliance: [
    ['pan_number', 'PAN'], ['aadhaar_number', 'Aadhaar'], ['uan_number', 'UAN'], ['pf_number', 'PF Number'],
    ['esic_number', 'ESIC Number'], ['esic_ip_number', 'ESIC IP Number'], ['pf_nominee_name', 'PF Nominee'], ['pf_nominee_relation', 'Nominee Relation'],
    ['tax_regime', 'Tax Regime', 'select', ['Old', 'New']], ['is_international_worker', 'International Worker', 'checkbox'], ['has_previous_pf', 'Has Previous PF', 'checkbox'],
  ],
}
const CHILD = {
  education: [['degree', 'Degree'], ['specialization', 'Specialization'], ['institution', 'Institution'], ['board_university', 'Board / University'], ['year_of_passing', 'Year'], ['percentage_grade', '% / Grade']],
  experience: [['company_name', 'Company'], ['designation', 'Designation'], ['from_date', 'From', 'date'], ['to_date', 'To', 'date'], ['last_ctc', 'Last CTC', 'number'], ['reason_for_leaving', 'Reason for Leaving']],
  family: [['member_name', 'Name'], ['relationship', 'Relationship'], ['dob', 'DOB', 'date'], ['occupation', 'Occupation'], ['contact', 'Contact'], ['is_dependent', 'Dependent', 'checkbox'], ['is_nominee', 'Nominee', 'checkbox']],
  // No `assets` entry: assets are Inventory records, shown read-only by
  // OnboardingAssetsTab. HR does not create or edit them.
}

export default function EmployeeOnboardingDetail() {
  const { id } = useParams()
  const navigate = useNavigate()
  const [d, setD] = useState(null)
  const [loading, setLoading] = useState(true)
  const [tab, setTab] = useState('overview')
  const [toast, setToast] = useState(null)
  const showToast = (msg, type = 'ok') => { setToast({ msg, type }); setTimeout(() => setToast(null), 2600) }

  const load = useCallback(async () => {
    try { setD(unwrap(await hrApi.employeeOnboarding.get(id))) }
    catch (e) { showToast(e.response?.data?.message || 'Failed to load', 'error') }
    finally { setLoading(false) }
  }, [id])
  useEffect(() => { load() }, [load])

  if (loading) return <HrLoading label="Loading onboarding…" />
  if (!d) return <HrEmpty icon={User} title="Onboarding not found" />

  const o = d.onboarding, ov = d.overview, ab = d.abilities || {}
  const st = onbStatusCfg(o.status)

  return (
    <div>
      <button onClick={() => navigate('/app/hr/employee-onboarding')} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, background: 'none', border: 'none', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 13, marginBottom: 12 }}><ArrowLeft size={15} /> Back to Onboarding</button>

      {/* Header */}
      <div className="card-3d" style={{ padding: 20, marginBottom: 16 }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', gap: 14, flexWrap: 'wrap' }}>
          <div style={{ display: 'flex', gap: 14, alignItems: 'center' }}>
            <div style={{ width: 54, height: 54, borderRadius: 14, background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#fff', fontWeight: 900, fontSize: 20 }}>{(ov.name || '?').split(' ').map(w => w[0]).slice(0, 2).join('')}</div>
            <div>
              <h1 style={{ color: 'var(--text-h)', fontSize: 20, fontWeight: 800, margin: 0 }}>{ov.name || '—'}</h1>
              <p style={{ fontSize: 12.5, color: 'var(--text-muted)', marginTop: 3 }}>{ov.employee_code} · {ov.designation || '—'} · {ov.department || '—'}</p>
            </div>
          </div>
          <div style={{ textAlign: 'right' }}>
            <span style={{ fontSize: 11, fontWeight: 700, padding: '3px 11px', borderRadius: 9, background: st.bg, color: st.color }}>{st.label}</span>
            <div style={{ fontSize: 26, fontWeight: 900, color: '#a78bfa', marginTop: 6 }}>{o.progress_percent}%</div>
            <p style={{ fontSize: 11, color: 'var(--text-muted)' }}>Current: {o.current_stage_label}</p>
          </div>
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(120px,1fr))', gap: 10, marginTop: 16 }}>
          {[['Manager', ov.manager || '—'], ['Joining Date', fmtDate(ov.joining_date)], ['Current Step', ov.current_step], ['Status', st.label]].map(([k, v]) => (
            <div key={k} style={{ padding: '9px 12px', background: 'var(--bg-input)', borderRadius: 9 }}>
              <div style={lbl}>{k}</div><div style={{ fontSize: 13, fontWeight: 700, color: 'var(--text-h)' }}>{v}</div>
            </div>
          ))}
        </div>
      </div>

      {/* Progress tracker */}
      <Tracker current={d.tracker.current_index} />

      {/* Tabs */}
      <div style={{ display: 'flex', gap: 6, margin: '16px 0', flexWrap: 'wrap' }}>
        {TABS.map(t => {
          const active = tab === t.key
          return (
            <button key={t.key} onClick={() => setTab(t.key)} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 13px', borderRadius: 9, fontSize: 12.5, fontWeight: 600, cursor: 'pointer', border: `1px solid ${active ? 'rgba(124,58,237,0.5)' : 'var(--border)'}`, background: active ? 'rgba(124,58,237,0.15)' : 'var(--bg-card)', color: active ? '#a78bfa' : 'var(--text-muted)' }}>
              <t.icon size={14} /> {t.label}
            </button>
          )
        })}
      </div>

      <div className="card-3d" style={{ padding: 22 }}>
        {tab === 'overview' && <Overview d={d} />}
        {tab === 'personal' && <PersonalTab d={d} id={id} reload={load} toast={showToast} editable={ab.personal?.edit} />}
        {tab === 'education' && <ChildTab section="education" id={id} rows={d.education} reload={load} toast={showToast} editable={ab.education?.edit} />}
        {tab === 'experience' && <ChildTab section="experience" id={id} rows={d.experience} reload={load} toast={showToast} editable={ab.experience?.edit} />}
        {tab === 'documents' && <DocumentsTab docs={d.documents} />}
        {tab === 'bank' && <SectionForm section="bank" title="Bank Details" id={id} profile={d.profile} reload={load} toast={showToast} editable={ab.bank?.edit} />}
        {tab === 'compliance' && <SectionForm section="compliance" title="Statutory Compliance" id={id} profile={d.profile} reload={load} toast={showToast} editable={ab.compliance?.edit} />}
        {tab === 'assets' && <OnboardingAssetsTab employeeId={ov?.employee_id} />}
        {tab === 'orientation' && <TasksTab id={id} tasks={pickTasks(d.tasks, ['Orientation'])} reload={load} toast={showToast} editable={ab.orientation?.edit} />}
        {tab === 'training' && <TasksTab id={id} tasks={pickTasks(d.tasks, ['Training'])} reload={load} toast={showToast} editable={ab.training?.edit} />}
        {tab === 'checklist' && <TasksTab id={id} tasks={pickTasks(d.tasks, ['IT_Setup', 'HR_Checklist', 'Manager_Checklist'])} reload={load} toast={showToast} editable={ab.checklist?.edit} grouped />}
        {tab === 'verification' && <OnboardingVerificationPanel id={id} data={d} reload={load} toast={showToast} />}
        {tab === 'approvals' && <ApprovalsTab o={o} ov={ov} bgv={d.background_verification} id={id} reload={load} showToast={showToast} />}
        {tab === 'timeline' && <AuditTimeline entries={d.audit_logs} newestFirst />}
        {tab === 'activity' && <ActivityTab logs={d.audit_logs} />}
      </div>

      {toast && <div style={{ position: 'fixed', bottom: 22, right: 22, padding: '11px 16px', borderRadius: 10, background: toast.type === 'error' ? '#ef4444' : '#10b981', color: '#fff', fontSize: 13, fontWeight: 600, zIndex: 60 }}>{toast.msg}</div>}
    </div>
  )
}

/* ── Progress tracker ── */
function Tracker({ current }) {
  return (
    <div className="card-3d" style={{ padding: '16px 14px', overflowX: 'auto' }}>
      <div style={{ display: 'flex', alignItems: 'flex-start', minWidth: 'max-content', gap: 0 }}>
        {ONB_STAGES.map((s, i) => {
          const done = i < current, active = i === current
          const color = done ? '#10b981' : active ? '#7C3AED' : 'var(--border)'
          return (
            <div key={s.key} style={{ display: 'flex', alignItems: 'flex-start' }}>
              <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', width: 92 }}>
                <div style={{ width: 30, height: 30, borderRadius: '50%', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 12, fontWeight: 800, color: done || active ? '#fff' : 'var(--text-muted)', background: done ? '#10b981' : active ? 'linear-gradient(135deg,#7C3AED,#5b21b6)' : 'var(--bg-input)', border: active ? '2px solid #a78bfa' : 'none' }}>{done ? <Check size={15} /> : i + 1}</div>
                <span style={{ fontSize: 9.5, textAlign: 'center', marginTop: 6, lineHeight: 1.2, color: active ? '#a78bfa' : 'var(--text-muted)', fontWeight: active ? 700 : 500 }}>{s.label}</span>
              </div>
              {i < ONB_STAGES.length - 1 && <div style={{ height: 2, width: 22, background: done ? '#10b981' : 'var(--border)', marginTop: 14 }} />}
            </div>
          )
        })}
      </div>
    </div>
  )
}

/* ── Overview ── */
function Overview({ d }) {
  const ov = d.overview, sec = d.section_status || {}
  const rows = [
    ['Employee', ov.name], ['Employee ID', ov.employee_code], ['Designation', ov.designation], ['Department', ov.department],
    ['Manager', ov.manager], ['Joining Date', fmtDate(ov.joining_date)], ['Current Status', ov.status], ['Current Step', ov.current_step],
  ]
  return (
    <div>
      <h3 style={{ fontSize: 14, fontWeight: 800, color: 'var(--text-h)', margin: '0 0 14px' }}>Employee Overview</h3>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(180px,1fr))', gap: 12, marginBottom: 20 }}>
        {rows.map(([k, v]) => (
          <div key={k}><div style={lbl}>{k}</div><div style={{ fontSize: 13.5, fontWeight: 700, color: 'var(--text-h)' }}>{v || '—'}</div></div>
        ))}
      </div>
      <h3 style={{ fontSize: 13, fontWeight: 800, color: 'var(--text-h)', margin: '0 0 10px' }}>Section Status</h3>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(150px,1fr))', gap: 8 }}>
        {Object.values(sec).map(s => (
          <div key={s.section} style={{ display: 'flex', justifyContent: 'space-between', padding: '7px 11px', borderRadius: 8, background: 'var(--bg-input)', fontSize: 12 }}>
            <span style={{ color: 'var(--text-muted)', textTransform: 'capitalize' }}>{s.section}</span>
            <span style={{ fontWeight: 700, color: s.status === 'Verified' ? '#10b981' : s.status === 'Submitted' ? '#a78bfa' : 'var(--text-muted)' }}>{s.status}</span>
          </div>
        ))}
      </div>
    </div>
  )
}

/* ── Personal tab (personal + contact + address + emergency + family) ── */
function PersonalTab({ d, id, reload, toast, editable }) {
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 22 }}>
      <SectionForm section="personal" title="Personal Information" id={id} profile={d.profile} reload={reload} toast={toast} editable={editable} />
      <SectionForm section="contact" title="Contact Information" id={id} profile={d.profile} reload={reload} toast={toast} editable={editable} />
      <SectionForm section="address" title="Address" id={id} profile={d.profile} reload={reload} toast={toast} editable={editable} />
      <SectionForm section="emergency" title="Emergency Contact" id={id} profile={d.profile} reload={reload} toast={toast} editable={editable} />
      <ChildTab section="family" id={id} rows={d.family} reload={reload} toast={toast} editable={editable} titleOverride="Family Details" />
    </div>
  )
}

/* ── Generic 1:1 section form ── */
function SectionForm({ section, title, id, profile, reload, toast, editable }) {
  const spec = F[section]
  const [editing, setEditing] = useState(false)
  const [form, setForm] = useState({})
  const [busy, setBusy] = useState(false)
  const start = () => { const f = {}; spec.forEach(([k]) => { f[k] = profile?.[k] ?? '' }); setForm(f); setEditing(true) }
  const save = async () => {
    setBusy(true)
    try { await hrApi.employeeOnboarding.saveSection(id, section, form); toast('Saved'); setEditing(false); reload() }
    catch (e) { toast(e.response?.data?.message || 'Failed', 'error') }
    finally { setBusy(false) }
  }
  return (
    <div>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12 }}>
        <h3 style={{ fontSize: 14, fontWeight: 800, color: 'var(--text-h)', margin: 0 }}>{title}</h3>
        {editable && !editing && <button onClick={start} style={miniBtn}><Pencil size={12} /> Edit</button>}
        {editing && <div style={{ display: 'flex', gap: 6 }}><button disabled={busy} onClick={save} style={{ ...miniBtn, color: '#10b981' }}><Check size={12} /> Save</button><button onClick={() => setEditing(false)} style={miniBtn}><X size={12} /> Cancel</button></div>}
      </div>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(200px,1fr))', gap: 12 }}>
        {spec.map(([k, l, type, opts]) => (
          <FieldView key={k} k={k} l={l} type={type} opts={opts} editing={editing} value={editing ? form[k] : profile?.[k]} onChange={v => setForm(f => ({ ...f, [k]: v }))} />
        ))}
      </div>
    </div>
  )
}

/**
 * Assets during onboarding — a read of the Inventory register, not an editor.
 *
 * HR used to add asset rows here, giving the company a second asset list. Assets
 * are assigned in Inventory now; doing so advances this onboarding's IT & Asset
 * Allocation stage and shows up on the employee profile automatically.
 */
function OnboardingAssetsTab({ employeeId }) {
  const navigate = useNavigate()
  const [filter, setFilter] = useState('all')

  if (!employeeId) {
    return <p className="text-xs py-8 text-center" style={{ color: 'var(--text-muted)' }}>
      No employee record linked to this onboarding yet.
    </p>
  }

  return (
    <div>
      <div className="flex items-center justify-between gap-3 flex-wrap mb-3">
        <div>
          <h3 className="text-sm font-bold" style={{ color: 'var(--text-h)' }}>Asset Allocation</h3>
          <p className="text-[11px] mt-0.5" style={{ color: 'var(--text-muted)' }}>
            Assets are assigned in Inventory. Assigning one here completes this stage.
          </p>
        </div>
        <button onClick={() => navigate('/app/inventory/assets')}
          className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl"
          style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }}>
          <Laptop size={13} /> Request asset assignment in Inventory
        </button>
      </div>

      <EmployeeAssetsPanel employeeId={employeeId} filter={filter} onFilterChange={setFilter} />
    </div>
  )
}

/* ── Generic 1:many child table (education/experience/family) ── */
function ChildTab({ section, id, rows = [], reload, toast, editable, titleOverride }) {
  const spec = CHILD[section]
  const [form, setForm] = useState(null) // {id?, ...}
  const [busy, setBusy] = useState(false)
  const api = hrApi.employeeOnboarding
  const A = { education: ['addEducation', 'updateEducation', 'deleteEducation'], experience: ['addExperience', 'updateExperience', 'deleteExperience'], family: ['addFamily', 'updateFamily', 'deleteFamily'] }[section]
  const title = titleOverride || { education: 'Education', experience: 'Employment History', family: 'Family Details' }[section]

  const blank = () => { const f = {}; spec.forEach(([k, , t]) => f[k] = t === 'checkbox' ? false : ''); setForm(f) }
  const save = async () => {
    setBusy(true)
    try {
      if (form.id) await api[A[1]](id, form.id, form); else await api[A[0]](id, form)
      toast('Saved'); setForm(null); reload()
    } catch (e) { toast(e.response?.data?.message || 'Failed', 'error') }
    finally { setBusy(false) }
  }
  const del = async (rid) => { try { await api[A[2]](id, rid); toast('Removed'); reload() } catch (e) { toast(e.response?.data?.message || 'Failed', 'error') } }

  return (
    <div>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12 }}>
        <h3 style={{ fontSize: 14, fontWeight: 800, color: 'var(--text-h)', margin: 0 }}>{title}</h3>
        {editable && !form && <button onClick={blank} style={miniBtn}><Plus size={12} /> Add</button>}
      </div>

      {form && (
        <div style={{ padding: 14, border: '1px solid rgba(124,58,237,0.4)', borderRadius: 10, marginBottom: 14, background: 'var(--bg-input)' }}>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(180px,1fr))', gap: 10 }}>
            {spec.map(([k, l, type, opts]) => <FieldView key={k} k={k} l={l} type={type} opts={opts} editing value={form[k]} onChange={v => setForm(f => ({ ...f, [k]: v }))} />)}
          </div>
          <div style={{ display: 'flex', gap: 6, marginTop: 12 }}>
            <button disabled={busy} onClick={save} style={{ ...miniBtn, color: '#10b981' }}><Check size={12} /> Save</button>
            <button onClick={() => setForm(null)} style={miniBtn}><X size={12} /> Cancel</button>
          </div>
        </div>
      )}

      {rows.length === 0 && !form ? <p style={{ fontSize: 12.5, color: 'var(--text-muted)' }}>No entries yet.</p>
        : <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
          {rows.map(r => (
            <div key={r.id} style={{ display: 'flex', justifyContent: 'space-between', gap: 10, padding: '11px 14px', borderRadius: 9, background: 'var(--bg-input)' }}>
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(120px,1fr))', gap: 8, flex: 1 }}>
                {spec.map(([k, l, type]) => (
                  <div key={k}><div style={lbl}>{l}</div><div style={{ fontSize: 12.5, color: 'var(--text-h)', fontWeight: 600 }}>{type === 'checkbox' ? (r[k] ? 'Yes' : 'No') : type === 'date' ? fmtDate(r[k]) : (r[k] ?? '—') || '—'}</div></div>
                ))}
              </div>
              {editable && <div style={{ display: 'flex', gap: 5, alignItems: 'flex-start' }}>
                <button onClick={() => setForm({ ...r })} style={iconBtn}><Pencil size={12} /></button>
                <button onClick={() => del(r.id)} style={{ ...iconBtn, color: '#f87171' }}><Trash2 size={12} /></button>
              </div>}
            </div>
          ))}
        </div>}
    </div>
  )
}

/* ── Tasks (Orientation / Training / Checklist) ── */
function pickTasks(tasks = {}, cats) {
  const out = {}
  cats.forEach(c => { out[c] = tasks[c] || [] })
  return out
}
function TasksTab({ id, tasks, reload, toast, editable, grouped }) {
  const cats = Object.keys(tasks)
  const flat = cats.flatMap(c => tasks[c])
  if (flat.length === 0) return <HrEmpty icon={ListChecks} title="No checklist items" />
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 18 }}>
      {cats.map(cat => (tasks[cat].length > 0 || grouped) && (
        <div key={cat}>
          {grouped && <h3 style={{ fontSize: 13, fontWeight: 800, color: 'var(--text-h)', margin: '0 0 10px' }}>{TASK_CATEGORIES.find(c => c.key === cat)?.label || cat}</h3>}
          <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
            {tasks[cat].map(t => <TaskRow key={t.id} id={id} task={t} reload={reload} toast={toast} editable={editable} />)}
          </div>
        </div>
      ))}
    </div>
  )
}
function TaskRow({ id, task, reload, toast, editable }) {
  const [busy, setBusy] = useState(false)
  const c = TASK_STATUS[task.status] || TASK_STATUS.Pending
  const setStatus = async (status) => {
    setBusy(true)
    try { await hrApi.employeeOnboarding.updateTask(id, task.id, { status }); toast('Task updated'); reload() }
    catch (e) { toast(e.response?.data?.message || 'Failed', 'error'); setBusy(false) }
  }
  return (
    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 10, padding: '11px 14px', borderRadius: 9, background: 'var(--bg-input)', flexWrap: 'wrap' }}>
      <div style={{ minWidth: 0 }}>
        <p style={{ fontSize: 13, fontWeight: 700, color: 'var(--text-h)', margin: 0 }}>{task.title}{task.is_mandatory ? <span style={{ color: '#f87171', marginLeft: 4 }}>*</span> : ''}</p>
        <p style={{ fontSize: 11, color: 'var(--text-muted)', marginTop: 2 }}>Owner: {task.owner_role}{task.completed_by_name ? ` · by ${task.completed_by_name} · ${fmtDate(task.completed_at)}` : ''}</p>
      </div>
      {editable
        ? <select disabled={busy} value={task.status} onChange={e => setStatus(e.target.value)} style={{ ...inputStyle, width: 'auto', color: c.color, fontWeight: 700 }}>
            {TASK_STATUSES.map(s => <option key={s} value={s} style={{ color: 'var(--text-h)' }}>{s}</option>)}
          </select>
        : <span style={{ fontSize: 11, fontWeight: 700, padding: '3px 10px', borderRadius: 8, background: c.bg, color: c.color }}>{task.status}</span>}
    </div>
  )
}

/* ── Documents (Sprint 1: read-only) ── */
function DocumentsTab({ docs = [] }) {
  return (
    <div>
      <h3 style={{ fontSize: 14, fontWeight: 800, color: 'var(--text-h)', margin: '0 0 6px' }}>Identity & Compliance Documents</h3>
      <p style={{ fontSize: 12, color: 'var(--text-muted)', display: 'flex', alignItems: 'center', gap: 6, marginBottom: 14 }}><Info size={13} /> Upload & verification arrive in Sprint 2. Captured documents appear here.</p>
      {docs.length === 0 ? <HrEmpty icon={FileText} title="No documents uploaded yet" />
        : <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
          {docs.map(dc => (
            <div key={dc.id} style={{ display: 'flex', justifyContent: 'space-between', padding: '10px 14px', borderRadius: 9, background: 'var(--bg-input)', fontSize: 12.5 }}>
              <span style={{ color: 'var(--text-h)', fontWeight: 600 }}>{dc.doc_type || dc.category} — {dc.original_name || '—'}</span>
              <span style={{ color: 'var(--text-muted)' }}>{dc.status}</span>
            </div>
          ))}
        </div>}
    </div>
  )
}

/* ── Approvals: Background Verification · HR/Manager sign-off · Activation (Phase 4) ── */
function ApprovalsTab({ o, ov = {}, bgv, id, reload, showToast }) {
  const [form, setForm] = useState({
    vendor: bgv?.vendor || '', reference_number: bgv?.reference_number || '',
    status: bgv?.status || 'Pending', remarks: bgv?.remarks || '',
    completed_date: bgv?.completed_date ? String(bgv.completed_date).slice(0, 10) : '',
  })
  const [busy, setBusy] = useState(false)
  const sectionTitle = { fontSize: 12, fontWeight: 800, color: 'var(--text-h)', textTransform: 'uppercase', letterSpacing: '0.04em', marginBottom: 10 }
  const btn = (bg, color, border) => ({ padding: '9px 14px', borderRadius: 9, fontSize: 12.5, fontWeight: 700, cursor: 'pointer', background: bg, color, border: border || 'none', opacity: busy ? 0.6 : 1 })

  const act = async (fn, ok) => { setBusy(true); try { await fn(); showToast(ok); reload() } catch (e) { showToast(e.response?.data?.message || 'Failed', 'error') } finally { setBusy(false) } }

  const row = (label, at) => (
    <div style={{ display: 'flex', justifyContent: 'space-between', padding: '12px 14px', borderRadius: 9, background: 'var(--bg-input)' }}>
      <span style={{ fontSize: 13, fontWeight: 700, color: 'var(--text-h)' }}>{label}</span>
      <span style={{ fontSize: 12, fontWeight: 700, color: at ? '#10b981' : '#f59e0b' }}>{at ? `Approved · ${fmtDateTime(at)}` : 'Pending'}</span>
    </div>
  )
  const isActive = ov.employee_status === 'Active'

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 18 }}>
      {/* Background Verification */}
      <div>
        <p style={sectionTitle}>Background Verification</p>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10 }}>
          <div><label style={lbl}>Vendor</label><input style={inputStyle} value={form.vendor} onChange={e => setForm(f => ({ ...f, vendor: e.target.value }))} /></div>
          <div><label style={lbl}>Reference Number</label><input style={inputStyle} value={form.reference_number} onChange={e => setForm(f => ({ ...f, reference_number: e.target.value }))} /></div>
          <div><label style={lbl}>Status</label><select style={inputStyle} value={form.status} onChange={e => setForm(f => ({ ...f, status: e.target.value }))}>{BGV_STATUSES.map(s => <option key={s} value={s}>{s}</option>)}</select></div>
          <div><label style={lbl}>Completed Date</label><input type="date" style={inputStyle} value={form.completed_date} onChange={e => setForm(f => ({ ...f, completed_date: e.target.value }))} /></div>
          <div style={{ gridColumn: '1 / -1' }}><label style={lbl}>Remarks</label><textarea rows={2} style={{ ...inputStyle, resize: 'none' }} value={form.remarks} onChange={e => setForm(f => ({ ...f, remarks: e.target.value }))} /></div>
        </div>
        <button disabled={busy} onClick={() => act(() => hrApi.employeeOnboarding.backgroundVerification(id, form), 'Background verification saved')} style={{ ...btn('linear-gradient(135deg,#7C3AED,#5b21b6)', '#fff'), marginTop: 10 }}>Save Background Verification</button>
      </div>

      {/* Approvals + Activation */}
      <div>
        <p style={sectionTitle}>Approvals &amp; Activation</p>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
          {row('HR Approval', o.hr_approved_at)}
          {row('Manager Approval', o.manager_approved_at)}
          {row('Onboarding Completed', o.completed_at)}
        </div>
        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8, marginTop: 12 }}>
          {!o.hr_approved_at && <button disabled={busy} onClick={() => act(() => hrApi.employeeOnboarding.hrApprove(id, ''), 'HR approved')} style={btn('rgba(124,58,237,0.1)', '#a78bfa', '1px solid rgba(124,58,237,0.25)')}>HR Approve</button>}
          {!o.manager_approved_at && <button disabled={busy} onClick={() => act(() => hrApi.employeeOnboarding.managerApprove(id, ''), 'Manager approved')} style={btn('rgba(34,211,238,0.1)', '#22d3ee', '1px solid rgba(34,211,238,0.25)')}>Manager Approve</button>}
          {!isActive && <button disabled={busy} onClick={() => act(() => hrApi.employeeOnboarding.confirmJoining(id), 'Employee activated')} style={btn('linear-gradient(135deg,#10b981,#059669)', '#fff')}>Confirm Joining · Activate Employee</button>}
          {isActive && <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 14px', borderRadius: 9, fontSize: 12.5, fontWeight: 700, background: 'rgba(16,185,129,0.12)', color: '#10b981' }}><BadgeCheck size={14} /> Employee Active</span>}
        </div>
        {(ov.official_email || ov.employee_status) && (
          <p style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 10 }}>Official email: <b style={{ color: 'var(--text-h)' }}>{ov.official_email || '— (generated on activation)'}</b> · Employee status: <b style={{ color: isActive ? '#10b981' : '#f59e0b' }}>{ov.employee_status || '—'}</b></p>
        )}
      </div>
    </div>
  )
}

/* ── Activity (compact feed from the shared audit log) ── */
function ActivityTab({ logs = [] }) {
  if (logs.length === 0) return <HrEmpty icon={ActivityIcon} title="No activity yet" />
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
      {logs.map(l => (
        <div key={l.id} style={{ display: 'flex', justifyContent: 'space-between', gap: 10, padding: '10px 14px', borderRadius: 9, background: 'var(--bg-input)' }}>
          <div><p style={{ fontSize: 12.5, fontWeight: 700, color: 'var(--text-h)', margin: 0 }}>{l.action}</p>{l.comment && <p style={{ fontSize: 11.5, color: 'var(--text-muted)', marginTop: 2 }}>{l.comment}</p>}</div>
          <div style={{ textAlign: 'right', whiteSpace: 'nowrap' }}><p style={{ fontSize: 11, color: 'var(--text-muted)', margin: 0 }}>{l.actor_name || 'System'}</p><p style={{ fontSize: 10.5, color: 'var(--text-muted)' }}>{fmtDateTime(l.created_at)}</p></div>
        </div>
      ))}
    </div>
  )
}

/* ── Field renderer (view + edit) ── */
function FieldView({ k, l, type, opts, editing, value, onChange }) {
  if (!editing) {
    const display = type === 'checkbox' ? (value ? 'Yes' : 'No') : type === 'date' ? fmtDate(value) : (value ?? '—') || '—'
    return <div><div style={lbl}>{l}</div><div style={{ fontSize: 13, fontWeight: 600, color: 'var(--text-h)', wordBreak: 'break-word' }}>{display}</div></div>
  }
  return (
    <div>
      <label style={lbl}>{l}</label>
      {type === 'select' ? <select value={value ?? ''} onChange={e => onChange(e.target.value)} style={inputStyle}><option value="">—</option>{opts.map(o => <option key={o} value={o}>{o}</option>)}</select>
        : type === 'checkbox' ? <label style={{ display: 'flex', alignItems: 'center', gap: 7, fontSize: 12.5, color: 'var(--text-h)', paddingTop: 4 }}><input type="checkbox" checked={!!value} onChange={e => onChange(e.target.checked)} /> Yes</label>
        : type === 'textarea' ? <textarea value={value ?? ''} onChange={e => onChange(e.target.value)} rows={2} style={{ ...inputStyle, resize: 'vertical' }} />
        : <input type={type === 'number' ? 'number' : type === 'date' ? 'date' : 'text'} value={value ?? ''} onChange={e => onChange(e.target.value)} style={inputStyle} />}
    </div>
  )
}

const miniBtn = { display: 'inline-flex', alignItems: 'center', gap: 5, padding: '6px 11px', borderRadius: 8, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 11.5, fontWeight: 700 }
const iconBtn = { display: 'inline-flex', alignItems: 'center', padding: '6px', borderRadius: 7, background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-muted)', cursor: 'pointer' }
