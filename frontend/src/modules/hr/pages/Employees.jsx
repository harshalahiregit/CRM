import { useState, useEffect, useMemo, useRef } from 'react'
import { useNavigate } from 'react-router-dom'
import { GRAD } from '@/components/ui/brand'
import { useTheme } from '@/context/ThemeContext'
import { useAuth } from '@/context/AuthContext'
import { Search, Building2, Plus, X, LayoutGrid, List, Eye, Pencil } from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { useMasterData, withInactiveById } from '@/modules/hr/useMasterData'
import { canManageHrQueue, hrDateInput } from '@/modules/hr/constants'
import { readFieldErrors } from '@/services/apiError'
import { HrLoading, HrEmpty } from '@/components/ui/HrState'
import Modal from '@/components/ui/Modal'
import DirectoryGapPanel from '@/modules/hr/components/DirectoryGapPanel'

const DEPT_COLORS = { Engineering:'#3b82f6', Sales:'#10b981', HR:'#7C3AED', Operations:'#f59e0b', Product:'#ec4899', Marketing:'#f97316', Finance:'#6366f1' }
const STATUS_S = s => s==='Active'?{c:'#10b981',bg:'rgba(16,185,129,0.12)'}:s==='On Leave'?{c:'#f59e0b',bg:'rgba(245,158,11,0.12)'}:{c:'#f87171',bg:'rgba(239,68,68,0.1)'}
const initials = n => (n||'').split(' ').slice(0,2).map(x=>x[0]).join('').toUpperCase()
const fmtDate  = d => d ? new Date(d).toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'}) : '—'
const deptColor = d => DEPT_COLORS[d]||'#7C3AED'

/** The form keys backed by an <input type="date">. See hrDateInput. */
const DATE_KEYS = new Set(['dob', 'joining_date', 'probation_end_date', 'confirmation_date'])

const EMPTY_FORM = { name:'', email:'', phone:'', dob:'', gender:'', address:'', department:'', designation:'', department_id:'', designation_id:'', employment_type_id:'', grade_id:'', reporting_manager_id:'', reporting_manager_name:'', work_state:'', joining_date:'', probation_end_date:'', confirmation_date:'', notice_days:'', status:'Active',
  // #36 — probation must be set when adding an employee, or the hire explicitly exempted.
  probation_policy_id:'', skip_probation:false, probation_skip_reason:'',
  // #29 — what this person is, and the comment's explicit "option to consider
  // person in org. chart while entering in system".
  worker_type:'employee', include_in_org_chart:true,
  // Attendance-app access. Off by default — granted, never assumed.
  app_login_enabled:false }

// Avatar built from initials (no photo store) — consistent across card & list.
const Avatar = ({ name, dept, size=44 }) => {
  const dc = deptColor(dept)
  return <div className="rounded-2xl flex items-center justify-center font-black text-white flex-shrink-0" style={{ width:size, height:size, fontSize:size*0.3, background:`linear-gradient(145deg,${dc}cc,${dc})`, boxShadow:`0 6px 18px ${dc}40` }}>{initials(name)}</div>
}

// Onboarding lifecycle shown ALONGSIDE the employee status — derived from the
// employee-onboarding record, never a substitute for HrEmployee.status.
const ONB_S = (s) => ({
  Pending:     { c:'#d97706', bg:'rgba(245,158,11,0.14)' },
  In_Progress: { c:'#2563eb', bg:'rgba(37,99,235,0.12)' },
  Completed:   { c:'#059669', bg:'rgba(16,185,129,0.12)' },
}[s] || { c:'var(--text-muted)', bg:'var(--bg-input)' })

// Record origin. Only imported rows are badged — a null source means the record
// predates import tracking, which is not the same as asserting it was manual.
const SourceBadge = ({ source }) => {
  if (source !== 'sangoetrack') return null
  return (
    <span className="text-[9.5px] font-bold px-1.5 py-0.5 rounded-md whitespace-nowrap"
      title="Imported from SangoeTrack HRM"
      style={{ background:'rgba(56,189,248,0.14)', color:'#38bdf8' }}>
      via SangoeTrack
    </span>
  )
}

const OnboardingBadge = ({ status, progress, bar = false }) => {
  if (!status) return null
  const o = ONB_S(status)
  return (
    <div style={{ marginTop: 4 }}>
      <span className="text-[9.5px] font-bold px-1.5 py-0.5 rounded-md" style={{ background:o.bg, color:o.c }}>
        Onboarding: {String(status).replace('_',' ')}{progress ? ` (${progress}%)` : ''}
      </span>
      {bar && progress > 0 && (
        <div className="mt-1 rounded-full" style={{ height:3, background:'var(--bg-input)' }}>
          <div className="h-full rounded-full" style={{ width:`${progress}%`, background:o.c }}/>
        </div>
      )}
    </div>
  )
}

export default function Employees() {
  const { isDark } = useTheme()
  const navigate = useNavigate()
  // PUT /hr/employees/{id} and /detail are gated on canManageHrQueue(); the same
  // helper the other nine HR screens use, now answered by the server.
  //
  // READING stays open — the directory is in everybody's sidebar on purpose, and
  // this hides only the actions that would come back 403.
  //
  // isAdmin is a separate question and belongs to "Add Employee" alone: that one
  // does not call this API at all, it navigates to Staff Management, which is
  // role:admin on the server and already hidden from the sidebar for everyone
  // else. The button was the one door still offering it.
  const { user, isAdmin } = useAuth()
  const canManageHr = canManageHrQueue(user)
  // Department / Designation / Reporting Manager all come from Org Setup master data
  // (single source of truth, active-only). No hardcoded lists; a saved-but-inactive
  // value stays visible and marked via withInactiveById().
  const { masters } = useMasterData()
  const deptNames    = (masters.departments  || []).map(d => d.name)
  const desigNames   = (masters.designations || []).map(d => d.name)
  // Chosen by ID: the employee points at the master record, not at a copy of
  // its name. The saved name is passed only so a since-retired master still
  // has something to be called in the list.
  const deptOptions    = (f) => withInactiveById(masters.departments,  f?.department_id,  f?.department)
  const desigOptions   = (f) => withInactiveById(masters.designations, f?.designation_id, f?.designation)
  // Employment type carries no name column on the employee, so a since-retired
  // master has no label to fall back on — withInactiveById prints "Current"
  // for that case rather than dropping the value and losing it on save.
  const empTypeOptions = (f) => withInactiveById(masters.employment_types, f?.employment_type_id, f?.employment_type?.name)
  const gradeOptions   = (f) => withInactiveById(masters.grades, f?.grade_id, f?.grade?.name)
  // Managers are picked by ID, not by name. masters.managers already carries
  // {id, name, employee_code}; the name was the only part being used, so the
  // hierarchy every other feature reads — org chart, advance approvals, the
  // app's approval queue — was never actually set at hire.
  //
  // The name is still stored alongside, because three read-only views render it
  // and because a manager who is not an employee record (the seeded "CEO") can
  // only ever be a name. Id where there is one, name either way.
  const managerPeople  = (masters.managers || []).filter(m => m?.id)
  const managerOptions = (f) => {
    const opts = managerPeople
      // Not yourself. The server already refuses it — "An employee cannot report
      // to themselves" — but the list was offering the one choice guaranteed to
      // fail, and the person only found out after pressing Save. Offering an
      // option the server will reject is a question you already know the answer
      // to.
      .filter(m => !editingId || String(m.id) !== String(editingId))
      .map(m => ({
        value: String(m.id),
        label: m.employee_code ? `${m.name} (${m.employee_code})` : m.name,
      }))
    // An already-set manager who has since left the master list stays visible,
    // so editing somebody else's field cannot silently clear it.
    const current = f?.reporting_manager_id
    if (current && !opts.some(o => o.value === String(current))) {
      opts.unshift({ value: String(current), label: `${f?.reporting_manager_name || 'Unknown'} (inactive)` })
    }
    return opts
  }
  const pickManager = (form, setForm, id) => {
    const picked = managerPeople.find(m => String(m.id) === String(id))
    setForm({
      ...form,
      reporting_manager_id:   id || '',
      // Kept in step so the list and detail views keep rendering a name.
      reporting_manager_name: picked?.name || (id ? form.reporting_manager_name : ''),
    })
  }
  // Work states come from the backend, not a hardcoded list, so the options here
  // and the states Professional Tax rules are keyed by can never drift apart.
  const [workStates, setWorkStates] = useState([])
  const [probationPolicies, setProbationPolicies] = useState([])
  const [employees, setEmployees] = useState([])
  const [optionsList, setOptionsList] = useState([])   // unfiltered — powers filter dropdowns
  const [stats, setStats]         = useState({ total:0, active:0, on_leave:0, by_dept:[] })
  const [loading, setLoading]     = useState(true)
  const [viewMode, setViewMode]   = useState('card')   // 'card' | 'list'

  // Filters
  const [search, setSearch]       = useState('')
  /** Sequence of the newest employee-list request; older answers are discarded. */
  const employeeRequestSeq = useRef(0)
  const [deptF, setDeptF]         = useState('All')
  const [desigF, setDesigF]       = useState('All')
  const [statusF, setStatusF]     = useState('All')
  const [joinedFrom, setJoinedFrom] = useState('')

  const [showModal, setShowModal] = useState(false)
  const [editingId, setEditingId] = useState(null)
  // The linked account's state, read-only, for the modal's Login account block.
  const [loginState, setLoginState] = useState(null)
  const [appBusy, setAppBusy] = useState(null)
  const [form, setForm]           = useState(EMPTY_FORM)
  const [saving, setSaving]       = useState(false)
  const [toast, setToast]         = useState(null)

  const showToast = (msg, type='success') => { setToast({msg,type}); setTimeout(()=>setToast(null),3000) }

  const [page, setPage] = useState(1)
  const [meta, setMeta] = useState({ current_page:1, last_page:1, total:0, per_page:25 })

  const fetchData = async () => {
    // Same guard as Staff Management, for the same reason: the search box fires
    // one request per keystroke with no debounce, several are in flight at once,
    // and they do not come back in the order they were sent. On a fast local
    // server they usually do, which is exactly why this is worth pinning — the
    // list silently showing results for two letters ago is a bug that only
    // appears on a slow connection.
    const seq = ++employeeRequestSeq.current

    setLoading(true)
    try {
      const params = {}
      if (deptF!=='All') params.department = deptF
      if (desigF!=='All') params.designation = desigF
      if (statusF!=='All') params.status = statusF
      if (joinedFrom) params.joined_from = joinedFrom
      if (search) params.search = search
      params.page = page
      const [res, st] = await Promise.all([hrApi.employees.listPaged(params), hrApi.employees.stats()])
      // Laravel paginator: { data, current_page, last_page, total, per_page }
      if (seq !== employeeRequestSeq.current) return

      const rows = Array.isArray(res) ? res : (res?.data ?? [])
      setEmployees(rows)
      setMeta({
        current_page: res?.current_page ?? 1,
        last_page:    res?.last_page ?? 1,
        total:        res?.total ?? rows.length,
        per_page:     res?.per_page ?? rows.length,
      })
      setStats(st)
    } catch (e) {
      if (seq !== employeeRequestSeq.current) return
      showToast(readFieldErrors(e).summary, 'error')
    }
    finally { if (seq === employeeRequestSeq.current) setLoading(false) }
  }
  useEffect(()=>{ fetchData() },[deptF, desigF, statusF, joinedFrom, search, page])
  useEffect(()=>{ setPage(1) },[deptF, desigF, statusF, joinedFrom, search])
  useEffect(()=>{ hrApi.employees.list({ per_page: 200 }).then(r => setOptionsList(Array.isArray(r) ? r : (r?.data ?? []))).catch(()=>{}) },[])
  useEffect(()=>{ hrApi.employees.workStates().then(setWorkStates).catch(()=>{}) },[])
  useEffect(()=>{ hrApi.probation.policies.list({ status:'Active' }).then(r=>setProbationPolicies(r?.data ?? r ?? [])).catch(()=>{}) },[])

  /*
   * These two drive the FILTER BAR, and they are deliberately derived from the
   * employees on screen rather than from the masters — filtering by a value
   * nobody holds would only ever return an empty table.
   *
   * They are NOT the designation and department masters. The form further down
   * uses those (deptOptions / desigOptions, from useMasterData), and the two
   * lists differ: this tenant has 15 designations on record while only 8 are in
   * use, so the filter legitimately shows the shorter list.
   *
   * Both controls used to be labelled plain "Department" and "Designation" on
   * the same screen, which read as one list contradicting the other — an
   * administrator checking whether "Manager" existed found it absent here and
   * concluded it could not be created, when it was already in the master and
   * already offered by the form. Hence the "Filter by …" labels below: the
   * names now say which question each control answers.
   */
  const departments = useMemo(()=>['All', ...new Set(optionsList.map(e=>e.department).filter(Boolean))], [optionsList])
  const designations = useMemo(()=>['All', ...new Set(optionsList.map(e=>e.designation).filter(Boolean))], [optionsList])

  const openEdit = (emp) => {
    setEditingId(emp.id)
    // Not part of the form — nothing here writes it. Kept beside the form so the
    // modal can show what this person's access currently is.
    setLoginState(emp.login || null)
    // #29 — the two org-chart keys fall back to the EMPTY_FORM defaults rather
    // than to '': an employee the list endpoint did not return them for would
    // otherwise open with "Show on the org chart" unticked and save it off.
    //
    // The date keys go through hrDateInput on the way in. The API serialises
    // them as full instants and <input type="date"> renders anything that is not
    // YYYY-MM-DD as blank, so these four fields opened empty on every employee —
    // Joining Date among them, beside its required marker. See constants.js.
    setForm({ ...EMPTY_FORM, ...Object.fromEntries(Object.keys(EMPTY_FORM).map(k=>[
      k, DATE_KEYS.has(k)
        ? hrDateInput(emp[k])
        : emp[k] ?? (k === 'status' ? 'Active' : (k in { worker_type:1, include_in_org_chart:1, app_login_enabled:1 } ? EMPTY_FORM[k] : '')),
    ])) })
    setShowModal(true)
  }
  const openProfile = (id) => navigate(`/app/hr/employees/${id}`)

  const handleSave = async () => {
    if (!form.name||!form.department_id||!form.designation_id||!form.joining_date) return showToast('Name, department, designation & joining date required','error')
    setSaving(true)
    try {
      if (editingId) {
        const emp = await hrApi.employees.update(editingId, form)
        setEmployees(prev=>prev.map(e=>e.id===editingId?emp:e))
        showToast('Employee updated!')
      } else {
        const emp = await hrApi.employees.create(form)
        setEmployees(prev=>[emp,...prev])
        setStats(prev=>({...prev,total:prev.total+1,active:prev.active+1}))
        showToast('Employee added!')
      }
      setShowModal(false); setForm(EMPTY_FORM); setEditingId(null); setLoginState(null)
    } catch (e) { showToast(e.response?.data?.message||'Failed','error') }
    finally { setSaving(false) }
  }

  /**
   * Grant or revoke attendance-app access from the list.
   *
   * This was only settable inside the employee form, so answering "who can use
   * the app" meant opening every record one at a time — which is a question HR
   * asks far more often than they edit an employee.
   *
   * PATCHes the one field rather than the whole form: sending the full record
   * back from a list row would resave stale values for everything else on it.
   */
  const toggleAppAccess = async (emp) => {
    const next = !emp.app_login_enabled
    setAppBusy(emp.id)
    try {
      await hrApi.employees.update(emp.id, { app_login_enabled: next })
      setEmployees(prev => prev.map(e => e.id === emp.id ? { ...e, app_login_enabled: next } : e))
      showToast(next
        ? `${emp.name} can now sign in to the attendance app`
        : `${emp.name} can no longer sign in to the attendance app`)
    } catch (e) {
      showToast(e.response?.data?.message || 'Could not change app access', 'error')
    } finally {
      setAppBusy(null)
    }
  }

  const resetFilters = () => { setDeptF('All'); setDesigF('All'); setStatusF('All'); setJoinedFrom(''); setSearch('') }
  const hasFilters = deptF!=='All'||desigF!=='All'||statusF!=='All'||joinedFrom||search

  return (
    <div className="space-y-6 animate-[tiltIn_0.35s_ease_forwards]">
      {toast && <div className="fixed top-5 right-5 z-[9999] px-5 py-3 rounded-2xl text-sm font-semibold text-white shadow-2xl" style={{ background:toast.type==='success'?'linear-gradient(135deg,#10b981,#059669)':'linear-gradient(135deg,#f87171,#ef4444)' }}>{toast.msg}</div>}

      <div className="flex items-center justify-between flex-wrap gap-3">
        <div><p className="label-caps mb-1">HR Module</p><h1 className="font-black" style={{ fontSize:'clamp(1.3rem,2vw,1.7rem)', color:'var(--text-h)', letterSpacing:'-0.02em' }}>Employee <span className="text-gradient">Management</span></h1></div>
        <div className="flex items-center gap-2">
          {/* Card / List view toggle */}
          <div className="flex rounded-xl overflow-hidden" style={{ border:'1px solid var(--border)' }}>
            {[{k:'card',I:LayoutGrid},{k:'list',I:List}].map(v=>(
              <button key={v.k} onClick={()=>setViewMode(v.k)} className="px-3 py-2.5 flex items-center" title={`${v.k} view`}
                style={{ background: viewMode===v.k ? 'linear-gradient(135deg,#7C3AED,#5b21b6)' : 'var(--bg-input)', color: viewMode===v.k ? '#fff' : 'var(--text-muted)' }}>
                <v.I size={15}/>
              </button>
            ))}
          </div>
          {/* People are created in Staff Management, never here.
              A person is one thing: a login and an employment record, made
              together. Creating from this screen produced only the second half
              — somebody on the payroll who could not sign in — and creating the
              same person in both places produced two of them, which is what
              happened the first time it was tried: a second "Kavita Dekhmukh"
              that payroll had no way to tell from the first.
              Staff Management already writes both in one transaction, so it is
              the one door in. This screen owns everything after that. */}
          {/* Shown only to an admin, because that is who Staff Management lets
              in — routes/admin.php is role:admin and the sidebar already hides
              the same destination. Offering the button to everybody else sent
              them to a screen that refuses them. */}
          {isAdmin && (
            <button
              /* `?new=1` so the destination OPENS the create form. Without it the
                 button navigated to a different screen and stopped: the person
                 pressed "Add Employee", landed on a list of existing staff, and
                 nothing on that page said what to do next or why they were there.
                 Going to the right screen is only half of sending somebody
                 somewhere. */
              onClick={() => navigate('/app/admin/staff?new=1')}
              className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold text-white"
              style={{ background: GRAD, boxShadow: '0 4px 14px rgba(124,58,237,0.4)' }}
              title="Employees are created in Staff Management, so the login and the employment record are made together">
              <Plus size={15}/> Add Employee
            </button>
          )}
        </div>
      </div>

      <div className="grid grid-cols-2 lg:grid-cols-3 gap-4">
        {[{l:'Total',v:stats.total,c:'#7C3AED'},{l:'Active',v:stats.active,c:'#10b981'},{l:'On Leave',v:stats.on_leave,c:'#f59e0b'}].map(k=>(
          <div key={k.l} className="kpi-3d"><p className="text-3xl font-black" style={{ color:k.c }}>{k.v}</p><p className="text-sm font-medium mt-1" style={{ color:'var(--text-muted)' }}>{k.l}</p></div>
        ))}
      </div>

      {/* Where this list and the staff directory disagree. Somebody added in
          one place and missing from the other is only discovered when they are
          left off a payroll run — so it is surfaced here, next to the list it
          is about. Silent when the two agree. */}
      <DirectoryGapPanel showToast={showToast} />

      {/* Search & Filters */}
      <div className="card-3d" style={{ padding:'16px' }}>
        <div className="flex gap-3 flex-wrap items-end">
          <div className="relative flex-1 min-w-[200px]">
            <label className="label">Search</label>
            <Search size={14} className="absolute left-3 top-[34px]" style={{ color:'var(--text-muted)' }}/>
            <input className="input-3d pl-9 text-sm" placeholder="Name, Employee ID, email, department…" value={search} onChange={e=>setSearch(e.target.value)}/>
          </div>
          <div className="min-w-[140px]">
            <label className="label">Filter by department</label>
            <select className="input-3d text-sm" value={deptF} onChange={e=>setDeptF(e.target.value)}>{departments.map(d=><option key={d}>{d}</option>)}</select>
          </div>
          <div className="min-w-[140px]">
            <label className="label">Filter by designation</label>
            <select className="input-3d text-sm" value={desigF} onChange={e=>setDesigF(e.target.value)}>{designations.map(d=><option key={d}>{d}</option>)}</select>
          </div>
          <div className="min-w-[120px]">
            <label className="label">Status</label>
            <select className="input-3d text-sm" value={statusF} onChange={e=>setStatusF(e.target.value)}>{['All','Active','On Leave','Inactive'].map(s=><option key={s}>{s}</option>)}</select>
          </div>
          <div className="min-w-[140px]">
            <label className="label">Joined on/after</label>
            <input type="date" className="input-3d text-sm" value={joinedFrom} onChange={e=>setJoinedFrom(e.target.value)}/>
          </div>
          {hasFilters && <button onClick={resetFilters} className="px-3 py-2.5 rounded-xl text-xs font-bold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Clear</button>}
        </div>
      </div>

      {loading ? <HrLoading label="Loading employees…" />
        : employees.length===0 ? <HrEmpty icon={Building2} title="No employees found" hint={hasFilters ? 'No employees match the current filters — try clearing them.' : 'Employees are created automatically when a candidate confirms joining.'} />
        : viewMode==='card' ? (
        /* ── CARD VIEW ── */
        <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
          {employees.map(emp=>{
            const ss = STATUS_S(emp.status)
            return(
              <div key={emp.id} className="card-3d flex flex-col" style={{ padding:'20px' }}>
                <div className="flex items-start gap-3 mb-3">
                  <Avatar name={emp.name} dept={emp.department} size={48}/>
                  <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-2 flex-wrap">
                      <p className="font-bold text-sm" style={{ color:'var(--text-h)' }}>{emp.name}</p>
                      <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:ss.bg, color:ss.c }}>{emp.status}</span>
                      <OnboardingBadge status={emp.onboarding_status} progress={emp.onboarding_progress} bar/>
                    </div>
                    <p className="text-xs mt-0.5" style={{ color:'var(--text-muted)' }}>{emp.designation}</p>
                    <span className="text-[10px] font-semibold font-mono" style={{ color:'var(--text-muted)' }}>{emp.employee_code}</span>
                  </div>
                </div>
                <div className="grid grid-cols-2 gap-2 mb-4">
                  <div className="px-2.5 py-2 rounded-xl" style={{ background:'var(--bg-input)' }}><p className="text-[10px]" style={{ color:'var(--text-muted)' }}>Department</p><p className="text-xs font-bold mt-0.5" style={{ color:deptColor(emp.department) }}>{emp.department||'—'}</p></div>
                  <div className="px-2.5 py-2 rounded-xl" style={{ background:'var(--bg-input)' }}><p className="text-[10px]" style={{ color:'var(--text-muted)' }}>Joined</p><p className="text-xs font-bold mt-0.5" style={{ color:'var(--text-h)' }}>{fmtDate(emp.joining_date)}</p></div>
                  <div className="px-2.5 py-2 rounded-xl col-span-2" style={{ background:'var(--bg-input)' }}><p className="text-[10px]" style={{ color:'var(--text-muted)' }}>Reporting Manager</p><p className="text-xs font-semibold mt-0.5" style={{ color:'var(--text-h)' }}>{emp.reporting_manager_name||'—'}</p></div>
                  {/* Same control as the list view. Whichever view somebody works in,
                      "can this person clock in on their phone" has to be answerable and
                      changeable there — a toggle that exists in only one of two views is
                      the same as missing for anybody using the other. */}
                  <div className="px-2.5 py-2 rounded-xl col-span-2 flex items-center gap-2" style={{ background:'var(--bg-input)' }}>
                    <div className="flex-1 min-w-0">
                      <p className="text-[10px]" style={{ color:'var(--text-muted)' }}>Attendance app</p>
                      <p className="text-xs font-bold mt-0.5" style={{ color: emp.app_login_enabled ? '#34d399' : 'var(--text-muted)' }}>
                        {emp.app_login_enabled ? 'Can sign in' : 'No access'}
                      </p>
                    </div>
                    {/* Granting attendance-app access writes through the same
                        gated PUT. Disabled rather than hidden, because the CURRENT
                        state is worth seeing even when you cannot change it. */}
                    <button type="button" onClick={()=>toggleAppAccess(emp)} disabled={appBusy===emp.id || !canManageHr}
                      title={!canManageHr ? 'Only HR can change attendance-app access'
                        : emp.app_login_enabled ? 'Revoke attendance-app access' : 'Grant attendance-app access'}
                      className="w-11 h-6 rounded-full relative transition-all shrink-0"
                      style={{ background: emp.app_login_enabled ? '#10b981' : 'var(--border)', opacity: appBusy===emp.id ? 0.6 : 1 }}>
                      <span className="absolute top-0.5 w-5 h-5 rounded-full bg-white shadow transition-all"
                        style={{ left: emp.app_login_enabled ? '22px' : '2px' }}/>
                    </button>
                  </div>
                </div>
                <div className="flex gap-2 mt-auto">
                  <button onClick={()=>openProfile(emp.id)} className="flex-1 flex items-center justify-center gap-1.5 py-2 rounded-xl text-xs font-bold text-white" style={{ background:'linear-gradient(135deg,#7C3AED,#5b21b6)' }}><Eye size={12}/> View Profile</button>
                  {/* The modal behind this saves through PUT /hr/employees/{id},
                      which is canManageHrQueue()-gated. Offering it to somebody
                      who cannot save is a form that fills in and then refuses. */}
                  {canManageHr && (
                    <button onClick={()=>openEdit(emp)} className="flex items-center justify-center gap-1.5 py-2 px-3 rounded-xl text-xs font-bold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}><Pencil size={12}/> Edit</button>
                  )}
                </div>
              </div>
            )
          })}
        </div>
      ) : (
        /* ── LIST VIEW ── */
        <div className="card-3d overflow-x-auto" style={{ padding:'6px' }}>
          <table className="w-full text-sm" style={{ minWidth:820 }}>
            <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{['Employee ID','Employee','Department','Designation','Status','App Access','Reporting Manager','Joining Date','Actions'].map(h=><th key={h} className="text-left px-3 py-3 label-caps whitespace-nowrap">{h}</th>)}</tr></thead>
            <tbody>
              {employees.map(emp=>{
                const ss = STATUS_S(emp.status)
                return(
                  <tr key={emp.id} className="cursor-pointer" onClick={()=>openProfile(emp.id)} style={{ borderBottom:'1px solid var(--border)' }}
                    onMouseEnter={e=>e.currentTarget.style.background='rgba(124,58,237,0.04)'} onMouseLeave={e=>e.currentTarget.style.background='transparent'}>
                    <td className="px-3 py-2.5 font-mono font-bold whitespace-nowrap" style={{ color:'#a78bfa' }}>{emp.employee_code}</td>
                    <td className="px-3 py-2.5"><div className="flex items-center gap-2.5"><Avatar name={emp.name} dept={emp.department} size={34}/><span className="font-semibold" style={{ color:'var(--text-h)' }}>{emp.name}</span><SourceBadge source={emp.source}/></div></td>
                    <td className="px-3 py-2.5"><span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:`${deptColor(emp.department)}18`, color:deptColor(emp.department) }}>{emp.department||'—'}</span></td>
                    <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{emp.designation||'—'}</td>
                    <td className="px-3 py-2.5">
                      <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:ss.bg, color:ss.c }}>{emp.status}</span>
                      <OnboardingBadge status={emp.onboarding_status} progress={emp.onboarding_progress}/>
                    </td>
                    {/* Who can clock in on their phone, answerable from the list rather
                        than by opening every record one at a time. Clicking it toggles
                        access directly — stopPropagation because the row opens a profile. */}
                    <td className="px-3 py-2.5" onClick={e=>e.stopPropagation()}>
                      <button type="button" onClick={()=>toggleAppAccess(emp)}
                        disabled={appBusy===emp.id || !canManageHr}
                        title={!canManageHr ? 'Only HR can change attendance-app access'
                          : emp.app_login_enabled ? 'Can sign in to the attendance app — click to revoke' : 'No app access — click to grant'}
                        className="text-[10px] font-bold px-2 py-0.5 rounded-lg"
                        style={{
                          background: emp.app_login_enabled ? 'rgba(52,211,153,0.14)' : 'var(--bg-input)',
                          color:      emp.app_login_enabled ? '#34d399' : 'var(--text-muted)',
                          border:     `1px solid ${emp.app_login_enabled ? 'rgba(52,211,153,0.35)' : 'var(--border)'}`,
                        }}>
                        {appBusy===emp.id ? '…' : emp.app_login_enabled ? 'Allowed' : 'Off'}
                      </button>
                    </td>
                    <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{emp.reporting_manager_name||'—'}</td>
                    <td className="px-3 py-2.5 whitespace-nowrap" style={{ color:'var(--text-muted)' }}>{fmtDate(emp.joining_date)}</td>
                    <td className="px-3 py-2.5" onClick={e=>e.stopPropagation()}>
                      <div className="flex gap-1.5">
                        <button onClick={()=>openProfile(emp.id)} title="View profile" className="p-1.5 rounded-lg" style={{ background:'rgba(124,58,237,0.1)', color:'#a78bfa' }}><Eye size={13}/></button>
                        {canManageHr && (
                          <button onClick={()=>openEdit(emp)} title="Edit" className="p-1.5 rounded-lg" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}><Pencil size={13}/></button>
                        )}
                      </div>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      )}

      {/* Department distribution */}
      <div className="card-3d" style={{ padding:'22px' }}>
        <h3 className="font-bold text-sm mb-4 flex items-center gap-2" style={{ color:'var(--text-h)' }}><Building2 size={14} style={{ color:'#a78bfa' }}/> By Department</h3>
        <div className="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-3">
          {(stats.by_dept||[]).sort((a,b)=>b.count-a.count).map(({department,count})=>{
            const color = deptColor(department)
            const pct = stats.total ? Math.round((count/stats.total)*100) : 0
            return(
              <div key={department||'—'}>
                <div className="flex justify-between mb-1.5">
                  <div className="flex items-center gap-2"><div className="w-2 h-2 rounded-full" style={{ background:color }}/><span className="text-xs font-semibold" style={{ color:'var(--text-muted)' }}>{department||'Unassigned'}</span></div>
                  <span className="text-xs font-black" style={{ color }}>{count}</span>
                </div>
                <div className="h-1.5 rounded-full" style={{ background:'var(--bg-input)' }}><div className="h-full rounded-full" style={{ width:`${pct}%`, background:color }}/></div>
              </div>
            )
          })}
        </div>
      </div>

      {/* Add / Edit Employee Modal.
          #21 — portaled to <body> via Modal. Inline, its fixed backdrop was
          trapped by this page's permanent tiltIn transform and the popup opened
          off-screen on a scrolled list. */}
      <Modal open={showModal} onClose={()=>setShowModal(false)} className="max-w-lg" style={{ maxHeight:'90vh', overflowY:'auto' }}>
          <div>
            <div className="flex items-center justify-between mb-5"><h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>Edit Employee</h2><button onClick={()=>setShowModal(false)} style={{ color:'var(--text-muted)' }}><X size={18}/></button></div>
            <div className="space-y-3">
              <div><label className="label">Full Name *</label><input className="input-3d text-sm" placeholder="Arjun Sharma" value={form.name} onChange={e=>setForm({...form,name:e.target.value})}/></div>
              <div className="grid grid-cols-2 gap-3">
                <div><label className="label">Email</label><input type="email" className="input-3d text-sm" value={form.email} onChange={e=>setForm({...form,email:e.target.value})}/></div>
                <div><label className="label">Phone</label><input className="input-3d text-sm" value={form.phone} onChange={e=>setForm({...form,phone:e.target.value})}/></div>
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div><label className="label">Date of Birth</label><input type="date" className="input-3d text-sm" value={form.dob||''} onChange={e=>setForm({...form,dob:e.target.value})}/></div>
                <div><label className="label">Gender</label>
                  <select className="input-3d text-sm" value={form.gender||''} onChange={e=>setForm({...form,gender:e.target.value})}>
                    <option value="">Select...</option>
                    {['Male','Female','Other','Prefer not to say'].map(g=><option key={g}>{g}</option>)}
                  </select>
                </div>
              </div>
              <div><label className="label">Address</label><textarea rows={2} className="input-3d text-sm resize-none" value={form.address||''} onChange={e=>setForm({...form,address:e.target.value})}/></div>
              <div className="grid grid-cols-2 gap-3">
                <div><label className="label">Department *</label>
                  <select className="input-3d text-sm" value={form.department_id||''} onChange={e=>setForm({...form,department_id:e.target.value})}>
                    <option value="">{deptNames.length ? 'Select...' : 'No departments defined yet'}</option>
                    {deptOptions(form).map(o=><option key={o.value} value={o.value}>{o.label}</option>)}
                  </select>
                  {/* Both lists come from Organization Setup, and both fields are required —
                      so an empty workspace could not create an employee at all and gave no
                      hint why. Say where they come from, and offer the way there.

                      The link is shown ALWAYS, not only when the list is empty. A
                      missing-but-wanted entry looks exactly like a full list to
                      the person who wants it: somebody checking for a designation
                      that was not there found no way to add one and concluded the
                      master was fixed. The empty case only ever needed the loudest
                      version of a signpost every case needs. */}
                  <button type="button" onClick={()=>navigate('/app/hr/organization-setup')}
                    className="text-[10px] mt-1 underline" style={{ color:'#a78bfa' }}>
                    {deptNames.length ? 'Manage departments in Organization Setup' : 'Add departments in Organization Setup'}
                  </button>
                </div>
                <div><label className="label">Designation *</label>
                  <select className="input-3d text-sm" value={form.designation_id||''} onChange={e=>setForm({...form,designation_id:e.target.value})}>
                    <option value="">{desigNames.length ? 'Select...' : 'No designations defined yet'}</option>
                    {desigOptions(form).map(o=><option key={o.value} value={o.value}>{o.label}</option>)}
                  </select>
                  <button type="button" onClick={()=>navigate('/app/hr/organization-setup')}
                    className="text-[10px] mt-1 underline" style={{ color:'#a78bfa' }}>
                    {desigNames.length ? 'Manage designations in Organization Setup' : 'Add designations in Organization Setup'}
                  </button>
                </div>
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div><label className="label">Reporting Manager</label>
                  <select className="input-3d text-sm" value={form.reporting_manager_id||''} onChange={e=>pickManager(form,setForm,e.target.value)}>
                    <option value="">Select…</option>
                    {managerOptions(form).map(o=><option key={o.value} value={o.value}>{o.label}</option>)}
                  </select>
                </div>
                <div><label className="label">Joining Date *</label><input type="date" className="input-3d text-sm" value={form.joining_date||''} onChange={e=>setForm({...form,joining_date:e.target.value})}/></div>
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div><label className="label">Probation End Date</label><input type="date" className="input-3d text-sm" value={form.probation_end_date||''} onChange={e=>setForm({...form,probation_end_date:e.target.value})}/></div>
                <div><label className="label">Confirmation Date</label><input type="date" className="input-3d text-sm" value={form.confirmation_date||''} onChange={e=>setForm({...form,confirmation_date:e.target.value})}/></div>
              </div>
              {/* A standing notice period for this person.
                  BLANK IS NOT ZERO, and the hint says so because the difference
                  is invisible otherwise: blank inherits the exit policy matched
                  to their grade and then the exit type's default, while 0 means
                  they genuinely serve none. Sending '' clears the override —
                  the field is normalised to null on save for that reason. */}
              {/* Optional: a workspace that has configured no employment types
                  must still be able to hire, so this never blocks a save. */}
              <div>
                <label className="label">Employment Type</label>
                <select className="input-3d text-sm" value={form.employment_type_id||''}
                  onChange={e=>setForm({...form,employment_type_id:e.target.value})}>
                  <option value="">{(masters.employment_types||[]).length ? 'Select…' : 'No employment types defined yet'}</option>
                  {empTypeOptions(form).map(o=><option key={o.value} value={o.value}>{o.label}</option>)}
                </select>
                <button type="button" onClick={()=>navigate('/app/hr/organization-setup')}
                  className="text-[10px] mt-1 underline" style={{ color:'#a78bfa' }}>
                  {(masters.employment_types||[]).length ? 'Manage employment types in Organization Setup' : 'Add employment types in Organization Setup'}
                </button>
              </div>
              {/* Grade. Same shape as Employment Type above, and added for the
                  same reason it is optional: a workspace with no grades must
                  still be able to hire.

                  It was missing entirely. grade_id is fillable, the employee
                  profile renders a Grade row, Organization Setup creates grades,
                  and leave policies, exit policies and the salary report all
                  target one — but no form wrote it, so every employee's grade was
                  permanently null and a grade-scoped policy could never match
                  anybody. */}
              <div>
                <label className="label">Grade</label>
                <select className="input-3d text-sm" value={form.grade_id||''}
                  onChange={e=>setForm({...form,grade_id:e.target.value})}>
                  <option value="">{(masters.grades||[]).length ? 'Select…' : 'No grades defined yet'}</option>
                  {gradeOptions(form).map(o=><option key={o.value} value={o.value}>{o.label}</option>)}
                </select>
                <button type="button" onClick={()=>navigate('/app/hr/organization-setup')}
                  className="text-[10px] mt-1 underline" style={{ color:'#a78bfa' }}>
                  {(masters.grades||[]).length ? 'Manage grades in Organization Setup' : 'Add grades in Organization Setup'}
                </button>
              </div>
              <div>
                <label className="label">Notice Period (days)</label>
                <input type="number" min="0" max="365" className="input-3d text-sm"
                  placeholder="Leave blank to inherit from grade / exit type"
                  value={form.notice_days ?? ''}
                  onChange={e=>setForm({...form,notice_days:e.target.value})}/>
                <p className="text-[10px] mt-1" style={{ color:'var(--text-muted)' }}>
                  {form.notice_days === '' || form.notice_days === null || form.notice_days === undefined
                    ? 'Inheriting — the exit policy for this grade, otherwise the exit type default.'
                    : `Overridden for this employee: ${Number(form.notice_days)} day(s).`}
                </p>
              </div>
              {/* #36 — probation must be set when adding an employee. Shown only on
                  create: an existing employee's probation is managed in its own module. */}
              {!editingId && (
                <div className="rounded-xl p-3" style={{ background:'var(--bg-input)', border:'1px solid var(--border)' }}>
                  <p className="text-[11px] font-black mb-2" style={{ color:'var(--text-h)' }}>Probation *</p>
                  {!form.skip_probation ? (
                    <>
                      <select className="input-3d text-sm" value={form.probation_policy_id||''} onChange={e=>setForm({...form,probation_policy_id:e.target.value})}>
                        <option value="">{probationPolicies.length ? 'Choose a probation policy…' : 'No probation policies defined yet'}</option>
                        {probationPolicies.map(p=><option key={p.id} value={p.id}>{p.name}</option>)}
                      </select>
                      {/* Same treatment as Department above. A fresh workspace has no
                          policies, so this required dropdown was empty with nothing to
                          pick and nothing said why — the form simply could not be
                          completed. Say where policies come from, offer the way there,
                          and point at the exemption for a hire that genuinely has none. */}
                      {!probationPolicies.length ? (
                        <>
                          <button type="button" onClick={()=>navigate('/app/hr/probation-management')}
                            className="text-[10px] mt-1 underline block" style={{ color:'#a78bfa' }}>
                            Create one in Probation Management
                          </button>
                          <p className="text-[10px] mt-1" style={{ color:'var(--text-muted)' }}>
                            Or tick “exempt” below if this hire has no probation.
                          </p>
                        </>
                      ) : (
                        <p className="text-[10px] mt-1" style={{ color:'var(--text-muted)' }}>
                          The probation record is created with the employee. If it cannot be created, the employee is not created either.
                        </p>
                      )}
                    </>
                  ) : (
                    <input className="input-3d text-sm" placeholder="Why is this hire exempt from probation?"
                      value={form.probation_skip_reason||''} onChange={e=>setForm({...form,probation_skip_reason:e.target.value})}/>
                  )}
                  <label className="flex items-center gap-2 text-xs font-semibold cursor-pointer mt-2" style={{ color:'var(--text-muted)' }}>
                    <input type="checkbox" checked={!!form.skip_probation} onChange={e=>setForm({...form,skip_probation:e.target.checked})}/>
                    This hire is exempt from probation
                  </label>
                </div>
              )}

              {/* #29 — worker type and the org-chart opt-in, captured at entry so
                  the chart is correct from the moment the person is added. */}
              <div className="grid grid-cols-2 gap-3">
                <div><label className="label">Worker Type</label>
                  <select className="input-3d text-sm" value={form.worker_type||'employee'} onChange={e=>setForm({...form,worker_type:e.target.value})}>
                    <option value="employee">Employee</option>
                    <option value="consultant">Consultant</option>
                    <option value="freelancer">Freelancer</option>
                  </select>
                </div>
                <div className="flex items-end pb-2">
                  <label className="flex items-center gap-2 text-xs font-semibold cursor-pointer" style={{ color:'var(--text-muted)' }}>
                    <input type="checkbox" checked={form.include_in_org_chart !== false}
                      onChange={e=>setForm({...form,include_in_org_chart:e.target.checked})}/>
                    Show on the org chart
                  </label>
                </div>
              </div>

              {/* Attendance-app access. HR decides who clocks in on a phone;
                  Staff Management decides what someone can do inside the CRM.
                  Two different questions, so two different screens.
                  Off by default: access is granted, never assumed. */}
              <div className="rounded-xl px-3 py-2.5" style={{ background:'var(--bg-input)', border:'1px solid var(--border)' }}>
                <label className="flex items-start gap-2.5 cursor-pointer">
                  <input type="checkbox" className="mt-0.5" checked={form.app_login_enabled === true}
                    onChange={e=>setForm({...form,app_login_enabled:e.target.checked})}/>
                  <span>
                    <span className="text-xs font-bold block" style={{ color:'var(--text-h)' }}>Can sign in to the attendance app</span>
                    <span className="text-[11px]" style={{ color:'var(--text-muted)' }}>
                      Lets this person clock in and out from their phone. Turning it off signs them out of the app; it does not affect their CRM login.
                    </span>
                  </span>
                </label>
              </div>

              {/*
                This form says who somebody IS. It does not say what they may
                OPEN — that is a staff account with a role, a module permission
                grid and a data scope, and it is edited in Staff Management.
                The two were only distinguishable by knowing already, which is
                why an administrator looking for "who can access which HR
                module" searched this screen and found employment fields.

                Admins only, from the SERVER's own answer: isAdmin is
                permissions.is_admin on the /me payload, which the backend
                computes as StaffPermissionService::bypasses() — and
                BYPASS_ROLES is exactly ['admin'], the same test role:admin
                applies to /api/admin/*. Reading the server's verdict rather
                than re-deriving one here is what keeps the link honest if that
                rule ever changes.
              */}
              {isAdmin && (
                <button type="button" onClick={()=>navigate('/app/admin/staff')}
                  className="w-full text-left rounded-xl px-3 py-2.5"
                  style={{ background:'var(--bg-input)', border:'1px dashed var(--border)' }}>
                  <span className="text-xs font-bold block" style={{ color:'var(--text-h)' }}>
                    Looking for CRM access and permissions?
                  </span>
                  <span className="text-[11px]" style={{ color:'var(--text-muted)' }}>
                    This form holds employment details. Roles, module permissions and data scope
                    live in <span className="underline" style={{ color:'#a78bfa' }}>Staff Management</span>.
                  </span>
                </button>
              )}

              {/* Work State drives Professional Tax. A saved value that is not in the
                  master list stays selectable rather than silently resetting to blank. */}
              <div><label className="label">Work State</label>
                <select className="input-3d text-sm" value={form.work_state||''} onChange={e=>setForm({...form,work_state:e.target.value})}>
                  <option value="">Not set</option>
                  {form.work_state && !workStates.some(s=>s.name===form.work_state) && <option value={form.work_state}>{form.work_state}</option>}
                  {workStates.map(s=><option key={s.code} value={s.name}>{s.name}</option>)}
                </select>
                <p className="text-[10px] mt-1" style={{ color:'var(--text-muted)' }}>
                  The state Professional Tax is levied under — not the office city. Leave blank to use the company default.
                </p>
              </div>
              {editingId && <div><label className="label">Employment Status</label><select className="input-3d text-sm" value={form.status} onChange={e=>setForm({...form,status:e.target.value})}>{['Active','On Leave','Inactive'].map(s=><option key={s}>{s}</option>)}</select>
                <p className="text-[10px] mt-1" style={{ color:'var(--text-muted)' }}>
                  Setting this to Inactive also stops the linked login from signing in.
                </p>
              </div>}

              {/* The login attached to this person — READ ONLY.
                  ────────────────────────────────────────────────────────────
                  Employment status decides whether somebody may sign in, and this
                  was the one screen that could not say so: an admin set a person
                  Inactive here and had no way to see what it did to their access.
                  Shown, never edited — the account belongs to Staff Management,
                  and a second editor for it is exactly what this whole piece of
                  work exists to remove. */}
              {editingId && (
                <div className="rounded-xl p-3" style={{ background:'var(--bg-input)', border:'1px solid var(--border)' }}>
                  <div className="flex items-start justify-between gap-3">
                    <div style={{ minWidth: 0 }}>
                      <p className="text-[10px] font-black uppercase tracking-wide" style={{ color:'var(--text-muted)' }}>
                        Login account
                      </p>
                      {loginState ? (
                        <>
                          <p className="text-xs font-semibold mt-1" style={{ color:'var(--text-h)' }}>{loginState.email}</p>
                          <p className="text-[10px] mt-1" style={{ color: loginState.can_sign_in ? '#10b981' : '#f59e0b' }}>
                            {loginState.can_sign_in
                              ? 'Can sign in to the CRM'
                              : `Cannot sign in — ${loginState.blocked_because}`}
                          </p>
                        </>
                      ) : (
                        <p className="text-[11px] mt-1" style={{ color:'var(--text-muted)' }}>
                          No login. This person cannot sign in or use the attendance app.
                        </p>
                      )}
                    </div>
                    {loginState && (
                      /* Filtered to this person. Dropping an admin onto an
                         unfiltered list and making them search again for the
                         name they were just looking at is the same dead end the
                         Add Employee button had. */
                      <button type="button" onClick={()=>navigate(`/app/admin/staff?search=${encodeURIComponent(loginState.email)}`)}
                        className="px-2.5 py-1 rounded-lg text-[10px] font-black whitespace-nowrap"
                        style={{ background:'var(--bg-card)', color:'var(--text-h)', border:'1px solid var(--border)' }}>
                        Manage account
                      </button>
                    )}
                  </div>
                </div>
              )}

              <div className="flex gap-3 pt-1">
                <button onClick={()=>setShowModal(false)} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Cancel</button>
                <button onClick={handleSave} disabled={saving} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:'linear-gradient(135deg,#7C3AED,#5b21b6)', opacity:saving?0.7:1 }}>{saving?'Saving…':editingId?'Save Changes':'Add Employee'}</button>
              </div>
            </div>
          </div>
      </Modal>

      {/* Pagination — server-driven; uses the paginator meta, no client slicing. */}
      {meta.last_page > 1 && (
        <div className="flex items-center justify-between gap-3 flex-wrap">
          <span className="text-[11px]" style={{ color:'var(--text-muted)' }}>
            Showing {(meta.current_page - 1) * meta.per_page + 1}–{Math.min(meta.current_page * meta.per_page, meta.total)} of {meta.total}
          </span>
          <div className="flex items-center gap-2">
            <button onClick={()=>setPage(p=>Math.max(1,p-1))} disabled={meta.current_page<=1}
              className="px-3 py-1.5 rounded-xl text-[11px] font-bold"
              style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)', opacity:meta.current_page<=1?0.5:1 }}>Previous</button>
            <span className="text-[11px] font-bold" style={{ color:'var(--text-h)' }}>Page {meta.current_page} of {meta.last_page}</span>
            <button onClick={()=>setPage(p=>Math.min(meta.last_page,p+1))} disabled={meta.current_page>=meta.last_page}
              className="px-3 py-1.5 rounded-xl text-[11px] font-bold"
              style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)', opacity:meta.current_page>=meta.last_page?0.5:1 }}>Next</button>
          </div>
        </div>
      )}
    </div>
  )
}
