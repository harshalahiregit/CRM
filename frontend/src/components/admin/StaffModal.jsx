import { useState, useEffect } from 'react'
import { X, Eye, EyeOff, RefreshCw, User, Shield, ChevronRight, ChevronDown, Check } from 'lucide-react'
import api from '@/lib/api'

// ── Timezones / Groups ──────────────────────────────────────────────────────
const TIMEZONES = [
  'System Default','UTC','Asia/Kolkata','Asia/Dubai','Asia/Singapore',
  'Asia/Tokyo','Europe/London','Europe/Paris','America/New_York',
  'America/Los_Angeles','America/Chicago','Australia/Sydney',
]
const PROFILE_GROUPS = [
  'Standard Staff','Senior Staff','Management','IT Admin',
  'Support Team','Sales Team','HR Team','Finance Team','Operations Team',
]
const MEMBER_DEPARTMENTS = [
  'Support','IT','Billing','Passwords','HR','Misuse',
  'Compliance','Sales','IT Projects','Marketing','Finance','Operations',
]

// ── Full Permissions Matrix ─────────────────────────────────────────────────
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
  { key:'appointments',    label:'Appointments',          actions:['view','create','edit','delete','approve','view_reports'] },
  { key:'delivery_notes',  label:'Delivery Notes',        actions:['view_own','view_global'] },
  { key:'hr_recruitment',  label:'HR Recruitment',        actions:['view_own','view_global','create','edit','delete'] },
  { key:'hr_checklists',   label:'HR Layoff Checklists',  actions:['view_own','view_global','create','edit','delete'] },
  { key:'hr_settings',     label:'HR Settings',           actions:['view_global','create','edit','delete'] },
  { key:'affiliates',      label:'Affiliate Management',  actions:['view_global','create','edit','delete'] },
  { key:'staff_mgmt',      label:'Staff Management',      actions:['view_global','create','edit','delete'] },
]

const ACTION_LABELS = {
  view_own:'View (Own)', view_global:'View (Global)', view:'View',
  create:'Create', edit:'Edit', delete:'Delete',
  approve:'Approve', view_reports:'View Reports',
}

// ── Role Templates (pre-fill permissions when role selected) ─────────────────
const ROLE_TEMPLATES = {
  employee: {
    contacts:['view_own'], deals:['view_own'], tasks:['view_own','create','edit'],
    projects:['view_own'], expenses:['view_own','create'], tickets:['view_own','create'],
    appointments:['view'],
  },
  hr_recruiter: {
    contacts:['view_own','view_global','create','edit'],
    tasks:['view_own','view_global','create','edit'],
    hr_recruitment:['view_own','view_global','create','edit','delete'],
    hr_checklists:['view_own','view_global','create','edit'],
    reports:['view_global'], appointments:['view','create','edit'],
    surveys:['view_global','create'], goals:['view_global'],
  },
  hr_executive: {
    contacts:['view_own','view_global','create','edit'],
    tasks:['view_own','view_global','create','edit'],
    hr_recruitment:['view_own','view_global','create','edit','delete'],
    hr_checklists:['view_own','view_global','create','edit','delete'],
    hr_settings:['view_global','create','edit'],
    reports:['view_global'], staff_mgmt:['view_global'],
    appointments:['view','create','edit'], surveys:['view_global','create'],
    goals:['view_global','create'],
  },
  hiring_manager: {
    contacts:['view_own'], tasks:['view_own','view_global','create','edit'],
    hr_recruitment:['view_own','view_global','create','edit'],
    hr_checklists:['view_own','view_global'],
    reports:['view_global'], appointments:['view','create','edit','approve'],
  },
  team_lead: {
    contacts:['view_own','view_global','create','edit'],
    deals:['view_own','view_global','create','edit'],
    tasks:['view_own','view_global','create','edit'],
    projects:['view_own','view_global','create','edit'],
    reports:['view_global'], appointments:['view','create','edit'],
    tickets:['view_own','view_global','create','edit'],
    goals:['view_global','create'],
  },
  senior_executive: {
    contacts:['view_own','view_global','create','edit'],
    deals:['view_own','view_global','create','edit'],
    tasks:['view_own','view_global','create','edit'],
    projects:['view_own','view_global','create','edit'],
    invoices:['view_own','view_global','create','edit'],
    estimates:['view_own','view_global','create','edit'],
    expenses:['view_own','view_global','create','edit'],
    credit_notes:['view_own','view_global'],
    customers:['view_own','view_global','create','edit'],
    reports:['view_global'], appointments:['view','create','edit'],
    tickets:['view_own','view_global','create','edit'],
  },
  department_head: {
    contacts:['view_own','view_global','create','edit','delete'],
    deals:['view_own','view_global','create','edit','delete'],
    tasks:['view_own','view_global','create','edit','delete'],
    projects:['view_own','view_global','create','edit','delete'],
    invoices:['view_own','view_global','create','edit'],
    estimates:['view_own','view_global','create','edit'],
    expenses:['view_own','view_global','create','edit'],
    credit_notes:['view_own','view_global','create'],
    customers:['view_own','view_global','create','edit'],
    vendors:['view_own','view_global','create','edit'],
    reports:['view_global'], email_templates:['view_global'],
    appointments:['view','create','edit','delete','approve','view_reports'],
    tickets:['view_own','view_global','create','edit','delete'],
    goals:['view_global','create','edit'], surveys:['view_global','create'],
    staff_mgmt:['view_global'],
  },
  project_manager: {
    contacts:['view_own','view_global','create','edit'],
    deals:['view_own','view_global'],
    tasks:['view_own','view_global','create','edit','delete'],
    projects:['view_own','view_global','create','edit','delete'],
    invoices:['view_own','view_global','create'],
    estimates:['view_own','view_global','create','edit'],
    expenses:['view_own','view_global','create','edit'],
    reports:['view_global'], appointments:['view','create','edit','approve'],
    tickets:['view_own','view_global','create','edit'],
    goals:['view_global','create','edit'],
    surveys:['view_global'],
  },
}

// ── Password generator ──────────────────────────────────────────────────────
const generatePassword = () => {
  const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#$%'
  return Array.from({length:12},()=>chars[Math.floor(Math.random()*chars.length)]).join('')
}

const EMPTY_FORM = {
  first_name:'', last_name:'', email:'', phone:'', password:'',
  internal_role:'', department:'', designation:'', status:'active',
  is_moderator:false, use_firstname_as_username:false,
  profile_group:'Standard Staff', priority:1,
  specialty:'', bio:'', nb_type:'',
  jabber_language:'System Default', timezone:'System Default',
  staff_signature:'',
  member_departments:[],
  permissions:{},
  send_welcome_email:true,
}

export default function StaffModal({ staff, designations, departments, onClose, onSuccess }) {
  const [activeTab,     setActiveTab]     = useState('profile')
  const [formData,      setFormData]      = useState(EMPTY_FORM)
  const [errors,        setErrors]        = useState({})
  const [loading,       setLoading]       = useState(false)
  const [showPassword,  setShowPassword]  = useState(false)
  const [roleTemplate,  setRoleTemplate]  = useState('')
  const [permSearch,    setPermSearch]    = useState('')
  const [expandedGroup, setExpandedGroup] = useState(null)

  // Grouped modules for collapsible sections
  const MODULE_GROUPS = [
    { label:'CRM Core',     keys:['contacts','deals','tasks','projects','customers','vendors'] },
    { label:'Finance',      keys:['invoices','estimates','expenses','credit_notes','delivery_notes'] },
    { label:'Operations',   keys:['appointments','tickets','inventory','goals','surveys'] },
    { label:'HR Module',    keys:['hr_recruitment','hr_checklists','hr_settings'] },
    { label:'System',       keys:['reports','email_templates','affiliates','staff_mgmt'] },
  ]

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
        profile_group:            meta.profile_group || 'Standard Staff',
        priority:                 meta.priority || 1,
        specialty:                meta.specialty || '',
        bio:                      meta.bio || '',
        nb_type:                  meta.nb_type || '',
        jabber_language:          meta.jabber_language || 'System Default',
        timezone:                 meta.timezone || 'System Default',
        staff_signature:          meta.staff_signature || '',
        member_departments:       meta.member_departments || [],
        permissions:              meta.permissions || {},
        send_welcome_email:       meta.send_welcome_email !== false,
      })
    }
  }, [staff])

  const set = (field, value) => {
    setFormData(prev => ({...prev, [field]:value}))
    if (errors[field]) setErrors(prev => ({...prev, [field]:null}))
  }

  // ── Permission helpers ──────────────────────────────────────────────────
  const hasPermission = (module, action) =>
    (formData.permissions[module] || []).includes(action)

  const togglePermission = (module, action) => {
    setFormData(prev => {
      const current = prev.permissions[module] || []
      const next    = current.includes(action)
        ? current.filter(a => a !== action)
        : [...current, action]
      return { ...prev, permissions: { ...prev.permissions, [module]: next } }
    })
  }

  const toggleAllModule = (module) => {
    const mod  = PERMISSION_MODULES.find(m => m.key === module)
    if (!mod) return
    const curr = formData.permissions[module] || []
    const all  = curr.length === mod.actions.length ? [] : [...mod.actions]
    setFormData(prev => ({...prev, permissions:{...prev.permissions, [module]:all}}))
  }

  const applyRoleTemplate = (role) => {
    setRoleTemplate(role)
    if (role && ROLE_TEMPLATES[role]) {
      setFormData(prev => ({...prev, permissions: ROLE_TEMPLATES[role]}))
    } else if (!role) {
      setFormData(prev => ({...prev, permissions:{}}))
    }
  }

  const selectAllPermissions = () => {
    const all = {}
    PERMISSION_MODULES.forEach(m => { all[m.key] = [...m.actions] })
    setFormData(prev => ({...prev, permissions:all}))
  }

  const clearAllPermissions = () => {
    setFormData(prev => ({...prev, permissions:{}}))
    setRoleTemplate('')
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
      department: formData.department, designation: formData.designation,
      status: formData.status,
      meta: {
        is_moderator:              formData.is_moderator,
        use_firstname_as_username: formData.use_firstname_as_username,
        profile_group:             formData.profile_group,
        priority:                  formData.priority,
        specialty:                 formData.specialty,
        bio:                       formData.bio,
        nb_type:                   formData.nb_type,
        jabber_language:           formData.jabber_language,
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

  const totalGranted = Object.values(formData.permissions).reduce((s,a)=>s+a.length,0)

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

              {/* Profile Group */}
              <div>
                <label style={lbl}>Profile Group</label>
                <select value={formData.profile_group} onChange={e=>set('profile_group',e.target.value)} style={inp('profile_group')}>
                  {PROFILE_GROUPS.map(g=><option key={g}>{g}</option>)}
                </select>
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

              {/* Priority */}
              <div>
                <label style={lbl}>Priority (1–10)</label>
                <div className="flex items-center gap-3">
                  <button type="button" onClick={()=>set('priority',Math.max(1,formData.priority-1))}
                    className="w-8 h-8 rounded-lg flex items-center justify-center font-bold text-lg"
                    style={{ background:'var(--bg-input)', border:'1px solid var(--border)', color:'var(--text-h)' }}>−</button>
                  <input type="range" min="1" max="10" value={formData.priority}
                    onChange={e=>set('priority',Number(e.target.value))}
                    className="flex-1" style={{ accentColor:'#7C3AED' }}/>
                  <button type="button" onClick={()=>set('priority',Math.min(10,formData.priority+1))}
                    className="w-8 h-8 rounded-lg flex items-center justify-center font-bold text-lg"
                    style={{ background:'var(--bg-input)', border:'1px solid var(--border)', color:'var(--text-h)' }}>+</button>
                  <span className="w-10 h-8 rounded-lg flex items-center justify-center text-sm font-black"
                    style={{ background:'rgba(124,58,237,0.12)', color:'#7C3AED' }}>{formData.priority}</span>
                </div>
              </div>

              {/* Phone */}
              <div>
                <label style={lbl}>Phone</label>
                <input type="text" value={formData.phone} onChange={e=>set('phone',e.target.value)}
                  placeholder="+91 98765 43210" style={inp('phone')}/>
              </div>

              {/* Specialty + NB Type */}
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label style={lbl}>Specialty</label>
                  <input type="text" value={formData.specialty} onChange={e=>set('specialty',e.target.value)}
                    placeholder="e.g., Recruitment" style={inp('specialty')}/>
                </div>
                <div>
                  <label style={lbl}>NB Type</label>
                  <input type="text" value={formData.nb_type} onChange={e=>set('nb_type',e.target.value)}
                    placeholder="e.g., Full-time" style={inp('nb_type')}/>
                </div>
              </div>

              {/* Jabber Language */}
              <div>
                <label style={lbl}>Jabber Language / Locale</label>
                <select value={formData.jabber_language} onChange={e=>set('jabber_language',e.target.value)} style={inp('jabber_language')}>
                  {['System Default','English','Hindi','Marathi','Gujarati','Tamil','Telugu','Bengali'].map(l=><option key={l}>{l}</option>)}
                </select>
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
                  <label style={lbl}>Designation / Role *</label>
                  <select value={formData.internal_role} onChange={e=>set('internal_role',e.target.value)} required style={inp('internal_role')}>
                    <option value="">Select Role</option>
                    {designations.map(d=><option key={d.value} value={d.value}>{d.label}</option>)}
                  </select>
                  {errors.internal_role&&<p className="text-[10px] mt-1" style={{ color:'#ef4444' }}>{errors.internal_role[0]}</p>}
                </div>
                <div>
                  <label style={lbl}>Department</label>
                  <select value={formData.department} onChange={e=>set('department',e.target.value)} style={inp('department')}>
                    <option value="">Select Department</option>
                    {departments.map(d=><option key={d} value={d}>{d}</option>)}
                  </select>
                </div>
              </div>

              {/* Job Title */}
              <div>
                <label style={lbl}>Job Title</label>
                <input type="text" value={formData.designation} onChange={e=>set('designation',e.target.value)}
                  placeholder="e.g., Senior HR Executive" style={inp('designation')}/>
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
            {activeTab==='permissions' && (<>

              {/* Role template + search + actions row */}
              <div className="space-y-3">
                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <label style={lbl}>Role Template</label>
                    <select value={roleTemplate} onChange={e=>applyRoleTemplate(e.target.value)} style={inp('role_template')}>
                      <option value="">— Select to auto-fill —</option>
                      <option value="employee">Employee</option>
                      <option value="hr_recruiter">HR Recruiter</option>
                      <option value="hr_executive">HR Executive</option>
                      <option value="hiring_manager">Hiring Manager</option>
                      <option value="team_lead">Team Lead</option>
                      <option value="senior_executive">Senior Executive</option>
                      <option value="department_head">Department Head</option>
                      <option value="project_manager">Project Manager</option>
                    </select>
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
                  </span>
                  <div className="flex gap-2">
                    <button type="button" onClick={selectAllPermissions}
                      className="px-3 py-1.5 rounded-lg text-xs font-bold"
                      style={{ background:'rgba(16,185,129,0.1)', color:'#10b981', border:'1px solid rgba(16,185,129,0.2)' }}>
                      Select All
                    </button>
                    <button type="button" onClick={clearAllPermissions}
                      className="px-3 py-1.5 rounded-lg text-xs font-bold"
                      style={{ background:'rgba(239,68,68,0.1)', color:'#f87171', border:'1px solid rgba(239,68,68,0.2)' }}>
                      Clear All
                    </button>
                  </div>
                </div>
              </div>

              {/* Member Departments */}
              <div>
                <label style={lbl}>Member Departments</label>
                <div className="grid grid-cols-3 gap-2">
                  {MEMBER_DEPARTMENTS.map(dept=>{
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
                  const groupGranted = group.modules.reduce((s,m)=>s+(formData.permissions[m.key]||[]).length,0)

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
                                  {/* Module Name + select all */}
                                  <div className="flex items-center gap-2 pt-0.5">
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
              Cancel
            </button>
            {activeTab==='profile' && (
              <button type="button" onClick={()=>setActiveTab('permissions')}
                className="flex-1 py-3 rounded-xl text-sm font-bold flex items-center justify-center gap-2"
                style={{ background:'rgba(124,58,237,0.1)', border:'1px solid rgba(124,58,237,0.3)', color:'#7C3AED' }}>
                Next: Permissions <ChevronRight size={15}/>
              </button>
            )}
            <button type="submit" disabled={loading}
              className="flex-1 py-3 rounded-xl text-sm font-bold text-white"
              style={{
                background:loading?'rgba(124,58,237,0.5)':'linear-gradient(135deg,#7C3AED,#6d28d9)',
                boxShadow:loading?'none':'0 4px 14px rgba(124,58,237,0.4)',
              }}>
              {loading?'Saving…':staff?'Update Staff':'Create Staff Member'}
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}
