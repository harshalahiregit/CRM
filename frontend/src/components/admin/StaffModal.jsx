import { useState, useEffect, useMemo } from 'react'
import { X, Eye, EyeOff, RefreshCw, User, Shield, ChevronRight, ChevronDown, Check, Monitor, Activity, StickyNote, RotateCcw } from 'lucide-react'
import { AccountTab, ActivityTab, NotesTab } from './StaffRecordTabs'
import api from '@/lib/api'
import { useAuth } from '@/context/AuthContext'

// ── Timezones / Groups ──────────────────────────────────────────────────────
const TIMEZONES = [
  'System Default','UTC','Asia/Kolkata','Asia/Dubai','Asia/Singapore',
  'Asia/Tokyo','Europe/London','Europe/Paris','America/New_York',
  'America/Los_Angeles','America/Chicago','Australia/Sydney',
]

// ── Full Permissions Matrix ─────────────────────────────────────────────────
// The keys here and App\Support\Hr\StaffPermission::MODULES are ONE vocabulary
// kept in two files, and StaffPermissionModuleParityTest fails the build if they
// disagree. A key missing here can never be ticked; a key missing there can
// never be granted, because sanitise() discards it on the way in and out.
//
// They had already drifted: hr_attendance and self existed server-side with no
// row here, so the only way to grant either was to pick a role template and
// inherit it. The six rows at the bottom close that gap and add the parts of HR
// the grid could not previously say anything about.
const PERMISSION_MODULES = [
  { key:'contacts',        label:'Contacts',              actions:['view_own','view_global','create','edit','delete'] },
  { key:'deals',           label:'Deals',                 actions:['view_own','view_global','create','edit','delete'] },
  { key:'tasks',           label:'Tasks',                 actions:['view_own','view_global','create','edit','delete'] },
  { key:'projects',        label:'Projects',              actions:['view_own','view_global','create','edit','delete'] },
  { key:'invoices',        label:'Invoices',              actions:['view_own','view_global','create','edit','delete'] },
  { key:'estimates',       label:'Estimates',             actions:['view_own','view_global','create','edit','delete'] },
  { key:'expenses',        label:'Expenses',              actions:['view_own','view_global','create','edit','delete'] },
  { key:'credit_notes',    label:'Credit Notes',          actions:['view_own','view_global','create','edit','delete'] },
  { key:'customers',       label:'Customers',             actions:['view_own','view_global','create','edit','delete'] },
  { key:'vendors',         label:'Vendors',               actions:['view_own','view_global','create','edit','delete'] },
  { key:'tickets',         label:'Support Tickets',       actions:['view_own','view_global','create','edit','delete'] },
  { key:'reports',         label:'Reports',               actions:['view_global'] },
  { key:'email_templates', label:'Email Templates',       actions:['view_global','edit'] },
  { key:'inventory',       label:'Inventory',             actions:['view_global','create','edit','delete'] },
  { key:'goals',           label:'Goals',                 actions:['view_global','create','edit','delete'] },
  { key:'surveys',         label:'Surveys',               actions:['view_global','create','edit','delete'] },
  // The standard five, like every other row. This offered 'view', 'approve'
  // and 'view_reports' — none of which are capabilities, so ticking them
  // saved nothing and the box came back empty with no error shown.
  { key:'appointments',    label:'Appointments',          actions:['view_own','view_global','create','edit','delete'] },
  { key:'delivery_notes',  label:'Delivery Notes',        actions:['view_own','view_global'] },
  { key:'hr_recruitment',  label:'HR Recruitment',        actions:['view_own','view_global','create','edit','delete'] },
  { key:'hr_checklists',   label:'HR Layoff Checklists',  actions:['view_own','view_global','create','edit','delete'] },
  { key:'hr_settings',     label:'HR Settings',           actions:['view_global','create','edit','delete'] },
  { key:'affiliates',      label:'Affiliate Management',  actions:['view_global','create','edit','delete'] },
  { key:'staff_mgmt',      label:'Staff Management',      actions:['view_global','create','edit','delete'] },
  // Existed server-side with no checkbox until now.
  { key:'hr_attendance',   label:'HR Attendance',         actions:['view_own','view_global','create','edit','delete'] },
  // "My own record" — clocking yourself in, your own leave and claims. Separate
  // from every row above, which are all about other people's records.
  { key:'self',            label:'My Own Record',         actions:['view_own','create','edit'] },
  // The parts of HR that could not be described at all. Nothing reads these yet
  // — HR authority still runs through one canManageHrQueue() check — so ticking
  // them grants nothing today. They are here so a role can be written down
  // before the enforcement is moved onto it.
  { key:'hr_employees',    label:'HR Employees',          actions:['view_own','view_global','create','edit','delete'] },
  { key:'hr_payroll',      label:'HR Payroll',            actions:['view_own','view_global','create','edit','delete'] },
  { key:'hr_leave',        label:'HR Leave',              actions:['view_own','view_global','create','edit','delete'] },
  { key:'hr_exit',         label:'HR Exit',               actions:['view_own','view_global','create','edit','delete'] },
  // Narrow authorities that used to be hardcoded to role slugs. Each offers
  // view_global only: on a module this specific, "sees all of it" and "may act
  // on it" are the same statement, which is how hr_employees already works.
  { key:'hr_onboarding',   label:'Employee Onboarding',   actions:['view_global'] },
  { key:'hr_manpower_l1',  label:'Manpower Approval (L1)', actions:['view_global'] },
  { key:'hr_manpower_l2',  label:'Manpower Approval (L2)', actions:['view_global'] },
  { key:'hr_ai_jd',        label:'AI Job Descriptions',   actions:['view_global'] },
  // Raising a POSH complaint, and nothing else. It grants NO access to any
  // case — not even the one the holder just raised — so it is safe to give to
  // whoever takes complaints at the door. Deliberately not implied by admin,
  // HR Settings or the HR queue, which is why it needs its own box: without
  // one, nobody could raise a case at all.
  { key:'hr_posh_intake',  label:'POSH Complaint Intake', actions:['view_global'] },
]

const ACTION_LABELS = {
  view_own:'View (Own)', view_global:'View (Global)', view:'View',
  create:'Create', edit:'Edit', delete:'Delete',
  approve:'Approve', view_reports:'View Reports',
}

// Role permission templates used to live here, keyed by internal_role, while
// the role dropdown was generated separately on the server from a DIFFERENT
// list. They are staff_roles records now — see StaffRoleTemplate.php for the
// seeded set — so adding or changing a role is data rather than a deploy.

// ── Password generator ──────────────────────────────────────────────────────
const generatePassword = () => {
  const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#$%'
  return Array.from({length:12},()=>chars[Math.floor(Math.random()*chars.length)]).join('')
}

const EMPTY_FORM = {
  first_name:'', last_name:'', email:'', phone:'', password:'',
  internal_role:'', staff_role_id:'', department:'', designation:'', status:'active',
  is_moderator:false, use_firstname_as_username:false,
  bio:'', timezone:'System Default',
  staff_signature:'',
  member_departments:[],
  permissions:{},
  // Administrator. The server ignores this from anyone who is not already an
  // admin, and the control below is not rendered for them either.
  administrator:false,
  send_welcome_email:true,
}

// `designations` is gone from the signature: roles are fetched here from
// /admin/roles now, so the parent no longer has to pass a list that came from a
// different source than the permissions did.
export default function StaffModal({ staff, departments = [], jobTitles = [], onClose, onSuccess }) {
  const [activeTab,     setActiveTab]     = useState('profile')
  const { user: actor } = useAuth()

  // Only an admin may grant or remove administrator access, so only an admin sees
  // the control. The server enforces this independently — a hidden control is a
  // courtesy, never a security boundary.
  const actorIsAdmin = actor?.role === 'admin'

  // Two things the server refuses, surfaced here so somebody is not told "no"
  // only after pressing save: you cannot remove your own access, and the founding
  // administrator stays.
  const editingSelf  = !!staff && actor?.id === staff.id
  const lockedAdmin  = !!staff && staff.role === 'admin' && editingSelf
  const [formData,      setFormData]      = useState(EMPTY_FORM)
  const [errors,        setErrors]        = useState({})
  const [loading,       setLoading]       = useState(false)
  const [showPassword,  setShowPassword]  = useState(false)
  /**
   * Roles come from the server now.
   *
   * They used to be a ROLE_TEMPLATES map in this file while the role DROPDOWN
   * was generated separately by the backend — so the two disagreed, and adding a
   * role meant editing this file and deploying. One list, fetched once.
   */
  const [roles,         setRoles]         = useState([])
  const [permSearch,    setPermSearch]    = useState('')
  const [expandedGroup, setExpandedGroup] = useState(null)

  // Grouped modules for collapsible sections.
  //
  // A key missing from every group NEVER RENDERS, whatever PERMISSION_MODULES
  // says — this is the second half of the drift that hid hr_attendance and self.
  // The parity test checks these keys too, not just the matrix above, because a
  // row nobody can see is the same as a row that does not exist.
  const MODULE_GROUPS = [
    { label:'CRM Core',     keys:['contacts','deals','tasks','projects','customers','vendors'] },
    { label:'Finance',      keys:['invoices','estimates','expenses','credit_notes','delivery_notes'] },
    { label:'Operations',   keys:['appointments','tickets','inventory','goals','surveys'] },
    { label:'HR Module',    keys:['hr_recruitment','hr_checklists','hr_settings','hr_attendance','hr_employees','hr_payroll','hr_leave','hr_exit','hr_onboarding','hr_manpower_l1','hr_manpower_l2','hr_ai_jd','hr_posh_intake'] },
    { label:'System',       keys:['reports','email_templates','affiliates','staff_mgmt'] },
    { label:'Personal',     keys:['self'] },
  ]

  useEffect(() => {
    let cancelled = false
    api.get('/admin/roles')
      // A failed fetch leaves the selector empty rather than breaking the form:
      // somebody can still create a staff member without picking a role.
      .then(r => { if (!cancelled) setRoles(r?.data?.data?.roles || []) })
      .catch(() => {})
    return () => { cancelled = true }
  }, [])

  useEffect(() => {
    if (staff) {
      const meta = staff.meta || {}
      const nameParts = (staff.name || '').split(' ')
      setFormData({
        first_name:               nameParts[0] || '',
        last_name:                nameParts.slice(1).join(' ') || '',
        email:                    staff.email || '',
        phone:                    staff.phone || '',
        password:                 '',
        internal_role:            staff.internal_role || '',
        department:               staff.department || '',
        designation:              staff.designation || '',
        status:                   staff.status || 'active',
        is_moderator:             meta.is_moderator || false,
        use_firstname_as_username:meta.use_firstname_as_username || false,
        bio:                      meta.bio || '',
        timezone:                 meta.timezone || 'System Default',
        staff_signature:          meta.staff_signature || '',
        member_departments:       meta.member_departments || [],
        permissions:              meta.permissions || {},
        administrator:            staff?.role === 'admin',
        staff_role_id:            staff.staff_role_id || '',
        send_welcome_email:       meta.send_welcome_email !== false,
      })
    }
  }, [staff])

  const set = (field, value) => {
    setFormData(prev => ({...prev, [field]:value}))
    if (errors[field]) setErrors(prev => ({...prev, [field]:null}))
  }

  // ── Permission helpers ──────────────────────────────────────────────────
  //
  // formData.permissions holds PERSONAL OVERRIDES ONLY, and is saved to
  // users.meta.permissions verbatim. It is not the effective permission set.
  //
  // The server resolves the two with array_replace(role, own) per MODULE, and
  // the three states that produces are the whole model:
  //
  //   module absent        → inherit whatever the role grants, live
  //   module present, list → this person gets exactly this, role ignored
  //   module present, []   → this person gets NOTHING here, role ignored
  //
  // The last one is why an override cannot be stored as an empty list meaning
  // "no opinion": un-ticking every box for a module is a real decision and has
  // to survive. Absent and empty are different answers.

  /** What the currently selected role grants, or {} when no role is assigned. */
  const inheritedPermissions = useMemo(() => {
    const role = roles.find(r => String(r.id) === String(formData.staff_role_id))
    return role?.permissions || {}
  }, [roles, formData.staff_role_id])

  /** Whether this module has been decided for this person specifically. */
  const isOverridden = (module) =>
    Object.prototype.hasOwnProperty.call(formData.permissions, module)

  /** The effective grant for a module — the override if there is one, else the role's. */
  const grantFor = (module) =>
    (isOverridden(module) ? formData.permissions[module] : inheritedPermissions[module]) || []

  const hasPermission = (module, action) => grantFor(module).includes(action)

  /**
   * Touching a checkbox on an INHERITED module converts it to an override,
   * seeded from what it was inheriting.
   *
   * Seeding matters: overrides replace the role at module level, so starting
   * from an empty list would silently strip every other capability the role
   * gave for that module the moment somebody added one.
   */
  const togglePermission = (module, action) => {
    setFormData(prev => {
      const base = Object.prototype.hasOwnProperty.call(prev.permissions, module)
        ? prev.permissions[module]
        : (inheritedPermissions[module] || [])
      const next = base.includes(action) ? base.filter(a => a !== action) : [...base, action]
      return { ...prev, permissions: { ...prev.permissions, [module]: next } }
    })
  }

  const toggleAllModule = (module) => {
    const mod = PERMISSION_MODULES.find(m => m.key === module)
    if (!mod) return
    const curr = grantFor(module)
    const all  = curr.length === mod.actions.length ? [] : [...mod.actions]
    setFormData(prev => ({...prev, permissions:{...prev.permissions, [module]:all}}))
  }

  /**
   * Hand a module back to the role.
   *
   * Deleting the key is the only way to express "no opinion" — setting it to []
   * would deny the module outright, which is the opposite of what an admin means
   * when they undo an override.
   */
  const resetModuleToRole = (module) => {
    setFormData(prev => {
      const next = { ...prev.permissions }
      delete next[module]
      return { ...prev, permissions: next }
    })
  }

  /**
   * Choosing a role LINKS to it. It no longer copies.
   *
   * It used to set `permissions` to the role's own grants, which looked like
   * pre-filling a form and was in fact a snapshot: those grants were written to
   * this person's meta.permissions, and from then on the role record was dead
   * weight for them. Editing "HR Executive" afterwards changed nothing for
   * anybody already holding it, because array_replace() hands the per-user copy
   * the win for every module it names.
   *
   * Leaving `permissions` alone is the entire fix. The role is read live on
   * every request, so a change to it reaches its holders immediately, and an
   * override is now something an admin has to actually make rather than
   * something that happens to them for picking a role from a dropdown.
   *
   * Existing overrides are deliberately NOT cleared here. Somebody may be on
   * "Accounts plus one extra thing", and swapping their role is not a statement
   * about the extra thing.
   */
  const applyRole = (roleId) => {
    const role = roles.find(r => String(r.id) === String(roleId))

    setFormData(prev => ({
      ...prev,
      staff_role_id: roleId ? Number(roleId) : '',
      // The slug is what the server writes to internal_role anyway; keeping the
      // form in step means the profile field never shows something stale.
      internal_role: role?.slug || prev.internal_role,
    }))
  }

  /** Override every module to everything — an explicit decision, not inheritance. */
  const selectAllPermissions = () => {
    const all = {}
    PERMISSION_MODULES.forEach(m => { all[m.key] = [...m.actions] })
    setFormData(prev => ({...prev, permissions:all}))
  }

  /**
   * Drop every override.
   *
   * With a role assigned this hands the whole grid back to it, which is why the
   * button says "Reset to role" in that case; with no role it leaves the person
   * with nothing, which is what "Clear all" always meant.
   */
  const clearAllPermissions = () => {
    setFormData(prev => ({...prev, permissions:{}}))
  }

  /** Override every module to DENY — the only way to say "this person, nothing". */
  const denyAllPermissions = () => {
    const none = {}
    PERMISSION_MODULES.forEach(m => { none[m.key] = [] })
    setFormData(prev => ({...prev, permissions:none}))
  }

  const toggleDept = (dept) => {
    setFormData(prev => ({
      ...prev,
      member_departments: prev.member_departments.includes(dept)
        ? prev.member_departments.filter(d => d !== dept)
        : [...prev.member_departments, dept],
    }))
  }

  // ── Submit ──────────────────────────────────────────────────────────────
  const handleSubmit = async (e) => {
    e.preventDefault()
    setErrors({})
    setLoading(true)
    const fullName = [formData.first_name, formData.last_name].filter(Boolean).join(' ')
    const payload  = {
      name: fullName, email: formData.email, phone: formData.phone,
      password: formData.password, internal_role: formData.internal_role,
      staff_role_id: formData.staff_role_id || null,
      department: formData.department, designation: formData.designation,
      status: formData.status,
      administrator: formData.administrator,
      meta: {
        is_moderator:              formData.is_moderator,
        use_firstname_as_username: formData.use_firstname_as_username,
        bio:                       formData.bio,
        timezone:                  formData.timezone,
        staff_signature:           formData.staff_signature,
        member_departments:        formData.member_departments,
        permissions:               formData.permissions,
        send_welcome_email:        formData.send_welcome_email,
      },
    }
    try {
      if (staff) {
        await api.put(`/admin/staff/${staff.id}`, payload)
      } else {
        await api.post('/admin/staff', payload)
      }
      onSuccess()
    } catch (error) {
      if (error.response?.data?.errors) setErrors(error.response.data.errors)
      else alert(error.response?.data?.message || 'Failed to save staff member')
    } finally { setLoading(false) }
  }

  // ── Style helpers ───────────────────────────────────────────────────────
  const inp = (field) => ({
    background: 'var(--bg-input, rgba(255,255,255,0.04))',
    border: `1px solid ${errors[field]?'#ef4444':'var(--border)'}`,
    color: 'var(--text-h)', borderRadius: '10px',
    padding: '10px 14px', width: '100%', fontSize: '13px', outline: 'none',
  })
  const lbl = {
    display:'block', fontSize:'10px', fontWeight:'700',
    letterSpacing:'0.08em', textTransform:'uppercase',
    color:'var(--text-muted)', marginBottom:'6px',
  }

  // Filtered modules for search
  const filteredGroups = MODULE_GROUPS.map(g => ({
    ...g,
    modules: g.keys
      .map(k => PERMISSION_MODULES.find(m => m.key === k))
      .filter(m => m && (!permSearch || m.label.toLowerCase().includes(permSearch.toLowerCase()))),
  })).filter(g => g.modules.length > 0)

  // Counted on the EFFECTIVE grant, not on the overrides, or the tab would read
  // "0 permissions" for somebody inheriting a full role and doing nothing wrong.
  const totalGranted    = PERMISSION_MODULES.reduce((s,m)=>s+grantFor(m.key).length,0)
  const overrideCount   = Object.keys(formData.permissions).length

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center p-4"
      style={{ background:'rgba(0,0,0,0.75)', backdropFilter:'blur(4px)' }}
    >
      <div
        className="rounded-2xl shadow-2xl w-full flex flex-col"
        style={{ background:'var(--bg-card)', border:'1px solid var(--border)', maxWidth:'720px', maxHeight:'92vh' }}
      >
        {/* Header */}
        <div className="flex items-center justify-between px-6 py-5 flex-shrink-0"
          style={{ borderBottom:'1px solid var(--border)' }}>
          <div>
            <h2 className="text-xl font-black" style={{ color:'var(--text-h)' }}>
              {staff ? 'Edit Staff Member' : 'Add New Staff Member'}
            </h2>
            <p className="text-xs mt-0.5" style={{ color:'var(--text-muted)' }}>
              Fill profile details and configure module permissions
            </p>
          </div>
          <button onClick={onClose}
            className="w-8 h-8 rounded-lg flex items-center justify-center"
            style={{ background:'var(--bg-input)', border:'1px solid var(--border)' }}
            onMouseEnter={e=>e.currentTarget.style.background='rgba(239,68,68,0.15)'}
            onMouseLeave={e=>e.currentTarget.style.background='var(--bg-input)'}
          ><X size={16} style={{ color:'var(--text-muted)' }}/></button>
        </div>

        {/* Tabs */}
        <div className="flex flex-shrink-0" style={{ borderBottom:'1px solid var(--border)' }}>
          {[
            { id:'profile', label:'Profile', icon:User },
            { id:'permissions', label:`Permissions${totalGranted?` (${totalGranted})`:''}`, icon:Shield },
            // Only for a record that exists. A new staff member has no sign-in
            // history, no audit trail and nothing to note, so offering the tabs
            // would offer three empty screens.
            ...(staff?.id ? [
              { id:'account',  label:'Account',  icon:Monitor },
              { id:'activity', label:'Activity', icon:Activity },
              { id:'notes',    label:'Notes',    icon:StickyNote },
            ] : []),
          ].map(({id,label,icon:Icon})=>(
            <button key={id} onClick={()=>setActiveTab(id)}
              className="flex items-center gap-2 px-6 py-3.5 text-sm font-bold transition-all"
              style={{
                color: activeTab===id?'#7C3AED':'var(--text-muted)',
                borderBottom: activeTab===id?'2px solid #7C3AED':'2px solid transparent',
                background:'transparent',
              }}
            ><Icon size={14}/>{label}</button>
          ))}
        </div>

        {/* Body */}
        <form onSubmit={handleSubmit} className="flex-1 overflow-y-auto min-h-0">
          <div className="p-6 space-y-5">

            {/* ═══════════════ PROFILE TAB ═══════════════ */}
            {activeTab==='profile' && (<>
              {actorIsAdmin && (
                <div className="flex items-center justify-between p-4 rounded-xl mb-4"
                  style={{ background:'rgba(16,185,129,0.06)', border:'1px solid rgba(16,185,129,0.2)' }}>
                  <label className={`flex items-center gap-3 ${lockedAdmin?'cursor-not-allowed opacity-60':'cursor-pointer'}`}>
                    <div onClick={()=>{ if(!lockedAdmin) set('administrator',!formData.administrator) }}
                      className="w-11 h-6 rounded-full relative transition-all"
                      style={{ background:formData.administrator?'#10b981':'var(--border)' }}>
                      <div className="absolute top-0.5 w-5 h-5 rounded-full bg-white shadow transition-all"
                        style={{ left:formData.administrator?'22px':'2px' }}/>
                    </div>
                    <div>
                      <p className="text-xs font-bold" style={{ color:'var(--text-h)' }}>Administrator</p>
                      <p className="text-[10px]" style={{ color:'var(--text-muted)' }}>
                        {lockedAdmin
                          ? 'You cannot remove your own administrator access.'
                          : 'Full access to everything, bypassing the permission grid below.'}
                      </p>
                    </div>
                  </label>
                </div>
              )}

              {/* Moderator + Username */}
              <div className="flex items-center justify-between p-4 rounded-xl"
                style={{ background:'rgba(124,58,237,0.06)', border:'1px solid rgba(124,58,237,0.15)' }}>
                <label className="flex items-center gap-3 cursor-pointer">
                  <div onClick={()=>set('is_moderator',!formData.is_moderator)}
                    className="w-11 h-6 rounded-full relative transition-all cursor-pointer"
                    style={{ background:formData.is_moderator?'#7C3AED':'var(--border)' }}>
                    <div className="absolute top-0.5 w-5 h-5 rounded-full bg-white shadow transition-all"
                      style={{ left:formData.is_moderator?'22px':'2px' }}/>
                  </div>
                  <div>
                    <p className="text-xs font-bold" style={{ color:'var(--text-h)' }}>Add Moderator</p>
                    <p className="text-[10px]" style={{ color:'var(--text-muted)' }}>Grant moderator privileges</p>
                  </div>
                </label>
                <label className="flex items-center gap-2 cursor-pointer ml-6">
                  <input type="checkbox" checked={formData.use_firstname_as_username}
                    onChange={e=>set('use_firstname_as_username',e.target.checked)}
                    style={{ accentColor:'#7C3AED', width:'14px', height:'14px' }}/>
                  <span className="text-xs" style={{ color:'var(--text-muted)' }}>Set First Name as Username</span>
                </label>
              </div>

              {/* First + Last Name */}
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label style={lbl}>First Name *</label>
                  <input type="text" value={formData.first_name} onChange={e=>set('first_name',e.target.value)}
                    required placeholder="Rahul" style={inp('first_name')}/>
                  {errors.name&&<p className="text-[10px] mt-1" style={{ color:'#ef4444' }}>{errors.name[0]}</p>}
                </div>
                <div>
                  <label style={lbl}>Last Name</label>
                  <input type="text" value={formData.last_name} onChange={e=>set('last_name',e.target.value)}
                    placeholder="Sharma" style={inp('last_name')}/>
                </div>
              </div>

              {/* Email */}
              <div>
                <label style={lbl}>Email Address *</label>
                <input type="email" value={formData.email} onChange={e=>set('email',e.target.value)}
                  required placeholder="rahul@sangoe.com" style={inp('email')}/>
                {errors.email&&<p className="text-[10px] mt-1" style={{ color:'#ef4444' }}>{errors.email[0]}</p>}
              </div>

              {/* Phone */}
              <div>
                <label style={lbl}>Phone</label>
                <input type="text" value={formData.phone} onChange={e=>set('phone',e.target.value)}
                  placeholder="+91 98765 43210" style={inp('phone')}/>
              </div>

              {/* Staff Signature */}
              <div>
                <label style={lbl}>Staff Signature</label>
                <textarea value={formData.staff_signature} onChange={e=>set('staff_signature',e.target.value)}
                  rows={3} placeholder="Appears at the bottom of emails sent by this staff member…"
                  style={{ ...inp('staff_signature'), resize:'vertical' }}/>
              </div>

              {/* Timezone */}
              <div>
                <label style={lbl}>Timezone</label>
                <select value={formData.timezone} onChange={e=>set('timezone',e.target.value)} style={inp('timezone')}>
                  {TIMEZONES.map(t=><option key={t}>{t}</option>)}
                </select>
              </div>

              {/* Designation + Department */}
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label style={lbl}>Role *</label>
                  {/* One selector, not two. This used to set internal_role while a separate
                      "Role Template" dropdown on the Permissions tab set the permissions —
                      from a different list, so the two disagreed. */}
                  <select value={formData.staff_role_id || ''} onChange={e=>applyRole(e.target.value)} required style={inp('internal_role')}>
                    <option value="">Select Role</option>
                    {roles.map(r=><option key={r.id} value={r.id}>{r.name}</option>)}
                  </select>
                  <p className="text-[10px] mt-1" style={{ color:'var(--text-muted)' }}>
                    Sets the permissions below. You can still change any of them for this person.
                  </p>
                  {errors.internal_role&&<p className="text-[10px] mt-1" style={{ color:'#ef4444' }}>{errors.internal_role[0]}</p>}
                </div>
                <div>
                  <label style={lbl}>Department</label>
                  <select value={formData.department} onChange={e=>set('department',e.target.value)} style={inp('department')}>
                    <option value="">Select Department</option>
                    {departments.map(d=><option key={d.id} value={d.name}>{d.name}</option>)}
                  </select>
                  <p className="text-[10px] mt-1" style={{ color:'var(--text-muted)' }}>
                    Managed under HR &rarr; Organization Setup.
                  </p>
                </div>
              </div>

              {/* Job Title — a designation record, not free text. Distinct from
                  Role above: Role decides what somebody may DO, a job title is
                  what they ARE. Two Senior Engineers can hold different roles. */}
              <div>
                <label style={lbl}>Job Title</label>
                <select value={formData.designation} onChange={e=>set('designation',e.target.value)} style={inp('designation')}>
                  <option value="">Select Job Title</option>
                  {jobTitles.map(t=><option key={t.id} value={t.name}>{t.name}</option>)}
                  {/* A title typed before designations became records would vanish
                      from the dropdown and silently clear on the next save. */}
                  {formData.designation && !jobTitles.some(t=>t.name===formData.designation) && (
                    <option value={formData.designation}>{formData.designation}</option>
                  )}
                </select>
                <p className="text-[10px] mt-1" style={{ color:'var(--text-muted)' }}>
                  Managed under HR &rarr; Organization Setup.
                </p>
              </div>

              {/* Status */}
              <div>
                <label style={lbl}>Account Status *</label>
                <div className="flex gap-3">
                  {['active','inactive','suspended'].map(s=>(
                    <label key={s} className="flex items-center gap-2 px-4 py-2.5 rounded-xl cursor-pointer transition-all"
                      style={{ border:`1px solid ${formData.status===s?'#7C3AED':'var(--border)'}`,
                        background:formData.status===s?'rgba(124,58,237,0.1)':'var(--bg-input)' }}>
                      <input type="radio" name="status" value={s} checked={formData.status===s}
                        onChange={()=>set('status',s)} className="hidden"/>
                      <div className="w-3 h-3 rounded-full"
                        style={{ background:s==='active'?'#10b981':s==='suspended'?'#ef4444':'#6b7280' }}/>
                      <span className="text-xs font-semibold capitalize"
                        style={{ color:formData.status===s?'#7C3AED':'var(--text-muted)' }}>{s}</span>
                    </label>
                  ))}
                </div>
              </div>

              {/* Password */}
              <div>
                <label style={lbl}>Password {!staff&&'*'}</label>
                <div className="flex gap-2">
                  <div className="relative flex-1">
                    <input type={showPassword?'text':'password'} value={formData.password}
                      onChange={e=>set('password',e.target.value)} required={!staff}
                      placeholder={staff?'Leave blank to keep current':'Min. 8 characters'}
                      style={{ ...inp('password'), paddingRight:'40px' }}/>
                    <button type="button" onClick={()=>setShowPassword(p=>!p)}
                      className="absolute right-3 top-1/2 -translate-y-1/2"
                      style={{ color:'var(--text-muted)', background:'none', border:'none' }}>
                      {showPassword?<EyeOff size={15}/>:<Eye size={15}/>}
                    </button>
                  </div>
                  <button type="button"
                    onClick={()=>{ const p=generatePassword(); set('password',p); setShowPassword(true) }}
                    className="px-3 rounded-xl flex items-center gap-1.5 text-xs font-bold"
                    style={{ background:'rgba(124,58,237,0.1)', color:'#7C3AED', border:'1px solid rgba(124,58,237,0.25)', whiteSpace:'nowrap' }}>
                    <RefreshCw size={13}/> Generate
                  </button>
                </div>
                {errors.password&&<p className="text-[10px] mt-1" style={{ color:'#ef4444' }}>{errors.password[0]}</p>}
              </div>
            </>)}

            {/* ═══════════════ PERMISSIONS TAB ═══════════════ */}
            {/* These three read and write on their own — they are not part of the
                profile form, so nothing here is saved by the Save button. */}
            {activeTab==='account'  && <AccountTab  staffId={staff?.id} open />}
            {activeTab==='activity' && <ActivityTab staffId={staff?.id} open />}
            {activeTab==='notes'    && <NotesTab    staffId={staff?.id} open />}

            {activeTab==='permissions' && (<>

              {/* Role template + search + actions row */}
              <div className="space-y-3">
                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <label style={lbl}>Role</label>
                    {/* Not a second selector. The role is chosen on the Profile tab; this says
                        which one is in force and offers a way back to its defaults after the
                        grid below has been edited. */}
                    {(() => {
                      const assigned = roles.find(r => String(r.id) === String(formData.staff_role_id))
                      return (
                        <div className="flex items-center gap-2 rounded-xl"
                          style={{ padding:'9px 11px', background:'var(--bg-input)', border:'1px solid var(--border)' }}>
                          <span className="text-xs font-bold" style={{ color: assigned ? 'var(--text-h)' : 'var(--text-muted)' }}>
                            {assigned ? assigned.name : 'No role selected'}
                          </span>
                          {assigned && (
                            <button type="button" onClick={()=>applyRole(assigned.id)}
                              className="ml-auto text-[11px] font-semibold"
                              style={{ color:'#7C3AED' }}>
                              Reset to defaults
                            </button>
                          )}
                        </div>
                      )
                    })()}
                  </div>
                  <div>
                    <label style={lbl}>Search Modules</label>
                    <input type="text" value={permSearch} onChange={e=>setPermSearch(e.target.value)}
                      placeholder="Filter modules…" style={inp('')}/>
                  </div>
                </div>

                {/* Quick action bar */}
                <div className="flex items-center justify-between p-3 rounded-xl"
                  style={{ background:'var(--bg-input)', border:'1px solid var(--border)' }}>
                  <span className="text-xs font-semibold" style={{ color:'var(--text-muted)' }}>
                    {totalGranted} permission{totalGranted!==1?'s':''} granted
                    {/* Said plainly, because "12 granted" is a different fact
                        depending on where the 12 came from. */}
                    {formData.staff_role_id
                      ? overrideCount > 0
                        ? ` — inherited from the role, with ${overrideCount} module${overrideCount!==1?'s':''} overridden`
                        : ' — all inherited from the role'
                      : ' — set directly on this person'}
                  </span>
                  <div className="flex gap-2">
                    <button type="button" onClick={selectAllPermissions}
                      className="px-3 py-1.5 rounded-lg text-xs font-bold"
                      style={{ background:'rgba(16,185,129,0.1)', color:'#10b981', border:'1px solid rgba(16,185,129,0.2)' }}>
                      Select All
                    </button>
                    {/* With a role, an empty override map means "inherit"; without
                        one it means "nothing". Two different acts, so two buttons
                        rather than one whose meaning silently depends on state. */}
                    {formData.staff_role_id ? (
                      <>
                        <button type="button" onClick={denyAllPermissions}
                          className="px-3 py-1.5 rounded-lg text-xs font-bold"
                          style={{ background:'rgba(239,68,68,0.1)', color:'#f87171', border:'1px solid rgba(239,68,68,0.2)' }}>
                          Deny All
                        </button>
                        <button type="button" onClick={clearAllPermissions} disabled={overrideCount===0}
                          className="px-3 py-1.5 rounded-lg text-xs font-bold disabled:opacity-40"
                          style={{ background:'rgba(124,58,237,0.1)', color:'#a78bfa', border:'1px solid rgba(124,58,237,0.2)' }}>
                          Reset to Role
                        </button>
                      </>
                    ) : (
                      <button type="button" onClick={clearAllPermissions}
                        className="px-3 py-1.5 rounded-lg text-xs font-bold"
                        style={{ background:'rgba(239,68,68,0.1)', color:'#f87171', border:'1px solid rgba(239,68,68,0.2)' }}>
                        Clear All
                      </button>
                    )}
                  </div>
                </div>
              </div>

              {/* Member Departments — the departments this person also works
                  across, beyond their own. Drawn from the department records so
                  there is one list; it used to be twelve names hardcoded in this
                  file, which is why 'Passwords' and 'Misuse' were on it. */}
              <div>
                <label style={lbl}>Member Departments</label>
                {departments.length === 0 && (
                  <p className="text-[11px] mb-2" style={{ color:'var(--text-muted)' }}>
                    No departments yet — add them under HR &rarr; Organization Setup.
                  </p>
                )}
                <div className="grid grid-cols-3 gap-2">
                  {departments.map(d=>{
                    const dept = d.name
                    const checked = formData.member_departments.includes(dept)
                    return (
                      <label key={dept} onClick={()=>toggleDept(dept)}
                        className="flex items-center gap-2.5 px-3 py-2.5 rounded-xl cursor-pointer transition-all"
                        style={{ border:`1px solid ${checked?'#7C3AED':'var(--border)'}`,
                          background:checked?'rgba(124,58,237,0.08)':'var(--bg-input)' }}>
                        <div className="w-4 h-4 rounded flex items-center justify-center flex-shrink-0"
                          style={{ background:checked?'#7C3AED':'transparent', border:`2px solid ${checked?'#7C3AED':'var(--border)'}` }}>
                          {checked&&<Check size={9} color="#fff"/>}
                        </div>
                        <span className="text-xs font-medium" style={{ color:checked?'#a78bfa':'var(--text-h)' }}>{dept}</span>
                      </label>
                    )
                  })}
                </div>
              </div>

              {/* Permissions Table — Grouped & Collapsible */}
              <div className="space-y-3">
                <label style={lbl}>Module Permissions</label>

                {filteredGroups.map(group=>{
                  const isOpen = expandedGroup===group.label || permSearch.length>0
                  // Count granted in this group
                  // Effective, matching the header count — a group of fully
                  // inherited modules is not an empty group.
                  const groupGranted = group.modules.reduce((s,m)=>s+grantFor(m.key).length,0)

                  return (
                    <div key={group.label} className="rounded-xl overflow-hidden"
                      style={{ border:'1px solid var(--border)' }}>
                      {/* Group Header */}
                      <button type="button"
                        onClick={()=>setExpandedGroup(isOpen&&!permSearch?null:group.label)}
                        className="w-full flex items-center justify-between px-4 py-3 text-left transition-all"
                        style={{ background:'rgba(124,58,237,0.04)', borderBottom: isOpen?'1px solid var(--border)':'none' }}>
                        <div className="flex items-center gap-2">
                          <span className="text-xs font-black uppercase tracking-wider" style={{ color:'var(--text-h)' }}>{group.label}</span>
                          {groupGranted>0&&(
                            <span className="px-2 py-0.5 rounded-full text-[10px] font-bold"
                              style={{ background:'rgba(124,58,237,0.15)', color:'#a78bfa' }}>{groupGranted}</span>
                          )}
                        </div>
                        <ChevronDown size={14} style={{ color:'var(--text-muted)', transform:isOpen?'rotate(180deg)':'none', transition:'transform .2s' }}/>
                      </button>

                      {/* Module Rows */}
                      {isOpen && (
                        <div>
                          {/* Column Header */}
                          <div className="grid px-4 py-2"
                            style={{ gridTemplateColumns:'1fr 1fr', borderBottom:'1px solid var(--border)', background:'rgba(0,0,0,0.02)' }}>
                            <span className="text-[9px] font-bold uppercase tracking-wider" style={{ color:'var(--text-muted)' }}>Module</span>
                            <span className="text-[9px] font-bold uppercase tracking-wider" style={{ color:'var(--text-muted)' }}>Permissions</span>
                          </div>

                          {group.modules.map((mod, i)=>{
                            const granted = formData.permissions[mod.key] || []
                            const allChecked = granted.length === mod.actions.length
                            const someChecked = granted.length > 0 && !allChecked
                            return (
                              <div key={mod.key}
                                style={{ borderBottom:i<group.modules.length-1?'1px solid var(--border)':'none' }}>
                                <div className="grid px-4 py-3 items-start gap-4"
                                  style={{ gridTemplateColumns:'1fr 1fr' }}>
                                  {/* Module Name + select all + where this grant came from */}
                                  <div className="flex items-center gap-2 pt-0.5 flex-wrap">
                                    <div onClick={()=>toggleAllModule(mod.key)}
                                      className="w-4 h-4 rounded flex items-center justify-center cursor-pointer flex-shrink-0 transition-all"
                                      style={{
                                        background: allChecked?'#7C3AED':someChecked?'rgba(124,58,237,0.3)':'transparent',
                                        border:`2px solid ${allChecked||someChecked?'#7C3AED':'var(--border)'}`,
                                      }}>
                                      {allChecked&&<Check size={9} color="#fff"/>}
                                      {someChecked&&!allChecked&&<div className="w-1.5 h-1.5 rounded-sm bg-purple-400"/>}
                                    </div>
                                    <span className="text-xs font-semibold" style={{ color:'var(--text-h)' }}>{mod.label}</span>

                                    {/* Only meaningful when a role is assigned — with
                                        no role every grant is personal by definition,
                                        and a badge on all 29 rows says nothing. */}
                                    {formData.staff_role_id && (
                                      isOverridden(mod.key) ? (
                                        <span className="flex items-center gap-1">
                                          <span className="px-1.5 py-0.5 rounded text-[9px] font-bold uppercase tracking-wide"
                                            style={{ background:'rgba(251,191,36,0.15)', color:'#fbbf24' }}>
                                            {grantFor(mod.key).length===0 ? 'Denied' : 'Custom'}
                                          </span>
                                          <button type="button" title="Hand this module back to the role"
                                            onClick={()=>resetModuleToRole(mod.key)}
                                            className="p-0.5 rounded hover:opacity-100 opacity-60"
                                            style={{ color:'var(--text-muted)' }}>
                                            <RotateCcw size={11}/>
                                          </button>
                                        </span>
                                      ) : (
                                        <span className="px-1.5 py-0.5 rounded text-[9px] font-bold uppercase tracking-wide"
                                          style={{ background:'rgba(124,58,237,0.12)', color:'#a78bfa' }}>
                                          Role
                                        </span>
                                      )
                                    )}
                                  </div>

                                  {/* Permission checkboxes */}
                                  <div className="flex flex-wrap gap-x-4 gap-y-1.5">
                                    {mod.actions.map(action=>{
                                      const checked = hasPermission(mod.key, action)
                                      return (
                                        <label key={action}
                                          className="flex items-center gap-1.5 cursor-pointer"
                                          onClick={()=>togglePermission(mod.key,action)}>
                                          <div className="w-3.5 h-3.5 rounded flex items-center justify-center flex-shrink-0 transition-all"
                                            style={{
                                              background:checked?'#7C3AED':'transparent',
                                              border:`2px solid ${checked?'#7C3AED':'var(--border)'}`,
                                            }}>
                                            {checked&&<Check size={8} color="#fff"/>}
                                          </div>
                                          <span className="text-xs" style={{ color:checked?'var(--text-h)':'var(--text-muted)' }}>
                                            {ACTION_LABELS[action]}
                                          </span>
                                        </label>
                                      )
                                    })}
                                  </div>
                                </div>
                              </div>
                            )
                          })}
                        </div>
                      )}
                    </div>
                  )
                })}
              </div>

              {/* Send Welcome Email */}
              <div className="flex items-center justify-between p-4 rounded-xl"
                style={{ background:'rgba(16,185,129,0.06)', border:'1px solid rgba(16,185,129,0.2)' }}>
                <div>
                  <p className="text-sm font-bold" style={{ color:'var(--text-h)' }}>Send Welcome Email</p>
                  <p className="text-xs mt-0.5" style={{ color:'var(--text-muted)' }}>
                    Send login credentials and welcome message to the staff member
                  </p>
                </div>
                <div onClick={()=>set('send_welcome_email',!formData.send_welcome_email)}
                  className="w-11 h-6 rounded-full relative transition-all cursor-pointer flex-shrink-0 ml-4"
                  style={{ background:formData.send_welcome_email?'#10b981':'var(--border)' }}>
                  <div className="absolute top-0.5 w-5 h-5 rounded-full bg-white shadow transition-all"
                    style={{ left:formData.send_welcome_email?'22px':'2px' }}/>
                </div>
              </div>
            </>)}
          </div>

          {/* Footer */}
          <div className="px-6 py-4 flex gap-3 flex-shrink-0"
            style={{ borderTop:'1px solid var(--border)', background:'var(--bg-card)' }}>
            <button type="button" onClick={onClose}
              className="flex-1 py-3 rounded-xl text-sm font-bold"
              style={{ background:'var(--bg-input)', border:'1px solid var(--border)', color:'var(--text-muted)' }}>
              {['profile','permissions'].includes(activeTab) ? 'Cancel' : 'Close'}
            </button>
            {activeTab==='profile' && (
              <button type="button" onClick={()=>setActiveTab('permissions')}
                className="flex-1 py-3 rounded-xl text-sm font-bold flex items-center justify-center gap-2"
                style={{ background:'rgba(124,58,237,0.1)', border:'1px solid rgba(124,58,237,0.3)', color:'#7C3AED' }}>
                Next: Permissions <ChevronRight size={15}/>
              </button>
            )}
            {/* Account, Activity and Notes write on their own. Showing Save there
                would imply it saves that tab, which it does not — it submits the
                profile form. */}
            {['profile','permissions'].includes(activeTab) && (
            <button type="submit" disabled={loading}
              className="flex-1 py-3 rounded-xl text-sm font-bold text-white"
              style={{
                background:loading?'rgba(124,58,237,0.5)':'linear-gradient(135deg,#7C3AED,#6d28d9)',
                boxShadow:loading?'none':'0 4px 14px rgba(124,58,237,0.4)',
              }}>
              {loading?'Saving…':staff?'Update Staff':'Create Staff Member'}
            </button>
            )}
          </div>
        </form>
      </div>
    </div>
  )
}
