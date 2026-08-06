import { useState, useEffect, useMemo, useCallback } from 'react'
import { useTheme } from '@/context/ThemeContext'
import {
  Building2, Tag, Layers, UserCog, Network,
  Plus, Pencil, Trash2, X, Users, AlertTriangle,
} from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { refreshMasterData } from '@/modules/hr/useMasterData'
import { HrLoading, HrEmpty } from '@/components/ui/HrState'
// #3 — the org master endpoints take no filter params, so these narrow the
// loaded rows in memory rather than growing a server contract for master lists
// that are small by nature.
import ListFilter, { applyListFilter } from '@/components/ui/ListFilter'

const ACCENT = '#7C3AED'
const GRAD = 'linear-gradient(135deg,#7C3AED,#5b21b6)'

// ── Per-master configuration. The four masters share a shape (name/code/
//    description/is_active) plus one or two entity-specific fields, so a single
//    config-driven table + form serves all of them. ──
const MASTERS = {
  departments: {
    label: 'Departments', singular: 'Department', icon: Building2,
    api: () => hrApi.organization.departments,
    columns: [
      { key: 'name',           head: 'Department' },
      { key: 'code',           head: 'Code' },
      { key: 'head_name',      head: 'Department Head' },
      { key: 'employee_count', head: 'Employees', chip: true },
    ],
    fields: [
      { key: 'name', label: 'Name', type: 'text', required: true, placeholder: 'e.g. Engineering' },
      { key: 'code', label: 'Code', type: 'text', placeholder: 'e.g. ENG' },
      { key: 'head_employee_id', label: 'Department Head', type: 'select', optsFrom: 'employees' },
      { key: 'description', label: 'Description', type: 'textarea', full: true },
      { key: 'skills', label: 'Expected Skills', type: 'skills', full: true,
        hint: 'Compared against each employee’s own skills to score their fit for this position.' },
    ],
  },
  designations: {
    label: 'Designations', singular: 'Designation', icon: Tag,
    api: () => hrApi.organization.designations,
    columns: [
      { key: 'name',           head: 'Designation' },
      { key: 'code',           head: 'Code' },
      { key: 'grade_name',     head: 'Grade' },
      { key: 'employee_count', head: 'Employees', chip: true },
    ],
    fields: [
      { key: 'name', label: 'Name', type: 'text', required: true, placeholder: 'e.g. Senior Engineer' },
      { key: 'code', label: 'Code', type: 'text', placeholder: 'e.g. SE-2' },
      { key: 'grade_id', label: 'Grade', type: 'select', optsFrom: 'grades' },
      { key: 'description', label: 'Description', type: 'textarea', full: true },
      { key: 'skills', label: 'Expected Skills', type: 'skills', full: true,
        hint: 'Compared against each employee’s own skills to score their fit for this position.' },
    ],
  },
  grades: {
    label: 'Grades', singular: 'Grade', icon: Layers,
    api: () => hrApi.organization.grades,
    columns: [
      { key: 'name',              head: 'Grade' },
      { key: 'code',              head: 'Code' },
      { key: 'level',             head: 'Level' },
      { key: 'designation_count', head: 'Designations', chip: true },
    ],
    fields: [
      { key: 'name', label: 'Name', type: 'text', required: true, placeholder: 'e.g. L3' },
      { key: 'code', label: 'Code', type: 'text', placeholder: 'e.g. G3' },
      { key: 'level', label: 'Seniority Level (1 = junior)', type: 'number' },
      { key: 'description', label: 'Description', type: 'textarea', full: true },
      { key: 'skills', label: 'Expected Skills', type: 'skills', full: true,
        hint: 'Compared against each employee’s own skills to score their fit for this position.' },
    ],
  },
  roles: {
    label: 'Roles', singular: 'Role', icon: UserCog,
    api: () => hrApi.organization.roles,
    columns: [
      { key: 'name',           head: 'Role' },
      { key: 'code',           head: 'Code' },
      { key: 'employee_count', head: 'Employees', chip: true },
    ],
    fields: [
      { key: 'name', label: 'Name', type: 'text', required: true, placeholder: 'e.g. Team Lead' },
      { key: 'code', label: 'Code', type: 'text', placeholder: 'e.g. TL' },
      { key: 'description', label: 'Description', type: 'textarea', full: true },
      { key: 'skills', label: 'Expected Skills', type: 'skills', full: true,
        hint: 'Compared against each employee’s own skills to score their fit for this position.' },
    ],
  },
}

const TABS = [
  ...Object.entries(MASTERS).map(([key, m]) => ({ key, label: m.label, icon: m.icon })),
  { key: 'hierarchy', label: 'Hierarchy', icon: Network },
]

export default function OrganizationSetup() {
  useTheme()
  const [tab, setTab] = useState('departments')
  const [overview, setOverview] = useState(null)
  const [options, setOptions] = useState({ employees: [], grades: [] })
  const [toast, setToast] = useState(null)

  const showToast = (msg, type = 'success') => { setToast({ msg, type }); setTimeout(() => setToast(null), 3000) }

  const loadOverview = useCallback(() => hrApi.organization.overview().then(setOverview).catch(() => {}), [])
  useEffect(() => { loadOverview() }, [loadOverview])
  useEffect(() => { hrApi.organization.options().then(setOptions).catch(() => {}) }, [])

  const refreshOptions = () => hrApi.organization.options().then(setOptions).catch(() => {})

  const KPIS = overview ? [
    { l: 'Departments', v: overview.departments, c: '#7C3AED' },
    { l: 'Designations', v: overview.designations, c: '#3b82f6' },
    { l: 'Grades', v: overview.grades, c: '#0ea5e9' },
    { l: 'Roles', v: overview.roles, c: '#14b8a6' },
    { l: 'Employees', v: overview.employees, c: '#10b981' },
    { l: 'Unassigned', v: overview.unassigned, c: overview.unassigned > 0 ? '#f59e0b' : '#94a3b8' },
  ] : []

  return (
    <div className="space-y-6 animate-[tiltIn_0.35s_ease_forwards]">
      {toast && (
        <div className="fixed top-5 right-5 z-[9999] px-5 py-3 rounded-2xl text-sm font-semibold text-white shadow-2xl"
          style={{ background: toast.type === 'success' ? 'linear-gradient(135deg,#10b981,#059669)' : 'linear-gradient(135deg,#f87171,#ef4444)' }}>
          {toast.msg}
        </div>
      )}

      {/* Header */}
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div>
          <p className="label-caps mb-1">HR Operations</p>
          <h1 className="font-black" style={{ fontSize: 'clamp(1.3rem,2vw,1.7rem)', color: 'var(--text-h)', letterSpacing: '-0.02em' }}>
            Organization <span className="text-gradient">Setup</span>
          </h1>
        </div>
      </div>

      {/* Overview KPIs */}
      <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
        {KPIS.map(k => (
          <div key={k.l} className="kpi-3d">
            <p className="text-3xl font-black" style={{ color: k.c }}>{k.v ?? '—'}</p>
            <p className="text-xs font-medium mt-1" style={{ color: 'var(--text-muted)' }}>{k.l}</p>
          </div>
        ))}
      </div>

      {/* Tabs */}
      <div className="flex gap-1.5 flex-wrap">
        {TABS.map(t => {
          const active = tab === t.key
          return (
            <button key={t.key} onClick={() => setTab(t.key)}
              className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold transition-all"
              style={{ background: active ? GRAD : 'var(--bg-input)', color: active ? '#fff' : 'var(--text-muted)', border: active ? 'none' : '1px solid var(--border)' }}>
              <t.icon size={15} /> {t.label}
            </button>
          )
        })}
      </div>

      {tab === 'hierarchy'
        ? <HierarchyView showToast={showToast} />
        : <MasterTab key={tab} tabKey={tab} options={options} onChanged={() => { loadOverview(); refreshOptions() }} showToast={showToast} />}
    </div>
  )
}

/* ────────────────────────────────────────────────────────────────────────
   Master CRUD tab (Departments / Designations / Grades / Roles)
   ──────────────────────────────────────────────────────────────────────── */
function MasterTab({ tabKey, options, onChanged, showToast }) {
  const cfg = MASTERS[tabKey]
  const api = cfg.api()
  const [rows, setRows] = useState([])
  const [loading, setLoading] = useState(true)
  const [modal, setModal] = useState(null)      // { editing, form }
  const [confirm, setConfirm] = useState(null)  // row pending delete
  const [saving, setSaving] = useState(false)

  const load = useCallback(() => {
    setLoading(true)
    api.list().then(setRows).catch(() => showToast(`Failed to load ${cfg.label.toLowerCase()}`, 'error')).finally(() => setLoading(false))
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [tabKey])

  useEffect(() => { load() }, [load])

  // #3 — searched across whichever columns this master actually shows, so the
  // same bar works for departments, designations, grades and roles without a
  // per-master field list to keep in step.
  const [search, setSearch] = useState('')
  const [statusF, setStatusF] = useState('All')
  useEffect(() => { setSearch(''); setStatusF('All') }, [tabKey])   // a tab change is a new list
  const shown = applyListFilter(rows, {
    search,
    fields: cfg.columns.map(c => c.key),
    matchers: [[statusF, (r, v) => (r.is_active === false ? 'Inactive' : 'Active') === v]],
  })

  const emptyForm = useMemo(() => Object.fromEntries(cfg.fields.map(f => [f.key, f.type === 'skills' ? [] : ''])), [cfg])

  const openCreate = () => setModal({ editing: null, form: { ...emptyForm } })
  const openEdit = (row) => setModal({
    editing: row.id,
    form: Object.fromEntries(cfg.fields.map(f => [f.key, row[f.key] ?? (f.type === 'skills' ? [] : '')])),
  })

  const save = async () => {
    const { editing, form } = modal
    const first = cfg.fields.find(f => f.required)
    if (first && !String(form[first.key] || '').trim()) return showToast(`${first.label} is required`, 'error')
    setSaving(true)
    try {
      if (editing) await api.update(editing, form)
      else await api.create(form)
      showToast(`${cfg.singular} ${editing ? 'updated' : 'created'}`)
      setModal(null); load(); onChanged(); refreshMasterData()   // every Recruitment dropdown re-reads the masters
    } catch (e) {
      showToast(e.response?.data?.message || 'Save failed', 'error')
    } finally { setSaving(false) }
  }

  const doDelete = async () => {
    try {
      await api.delete(confirm.id)
      showToast(`${cfg.singular} deleted`)
      setConfirm(null); load(); onChanged(); refreshMasterData()   // every Recruitment dropdown re-reads the masters
    } catch (e) {
      showToast(e.response?.data?.message || 'Delete failed', 'error')
      setConfirm(null)
    }
  }

  const optsFor = (name) => (name === 'employees' ? options.employees : options.grades) || []

  return (
    <div className="space-y-4">
      <ListFilter
        search={search} setSearch={setSearch} placeholder={`Search ${cfg.label.toLowerCase()}…`}
        selects={[{ key:'status', label:'Status', value:statusF, onChange:setStatusF, options:['All','Active','Inactive'] }]}
        onClear={()=>{ setSearch(''); setStatusF('All') }}
        right={
          <button onClick={openCreate} className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background: GRAD, boxShadow: '0 4px 14px rgba(124,58,237,0.4)' }}>
            <Plus size={15} /> Add {cfg.singular}
          </button>
        }
      />

      {loading ? <HrLoading label={`Loading ${cfg.label.toLowerCase()}…`} />
        : shown.length === 0 ? <HrEmpty icon={cfg.icon} title={rows.length ? `No matching ${cfg.label.toLowerCase()}` : `No ${cfg.label.toLowerCase()} yet`} hint={rows.length ? 'Nothing matches these filters.' : `Create your first ${cfg.singular.toLowerCase()} to start structuring the organization.`} />
        : (
          <div className="card-3d overflow-x-auto" style={{ padding: '6px' }}>
            <table className="w-full text-sm" style={{ minWidth: 640 }}>
              <thead>
                <tr style={{ borderBottom: '1px solid var(--border)' }}>
                  {cfg.columns.map(c => <th key={c.key} className="text-left px-3 py-3 label-caps whitespace-nowrap">{c.head}</th>)}
                  <th className="text-left px-3 py-3 label-caps">Status</th>
                  <th className="text-right px-3 py-3 label-caps">Actions</th>
                </tr>
              </thead>
              <tbody>
                {shown.map(row => (
                  <tr key={row.id} style={{ borderBottom: '1px solid var(--border)' }}>
                    {cfg.columns.map(c => (
                      <td key={c.key} className="px-3 py-2.5">
                        {c.chip
                          ? <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background: 'rgba(124,58,237,0.1)', color: '#a78bfa' }}>{row[c.key] ?? 0}</span>
                          : <span style={{ color: c.key === 'name' ? 'var(--text-h)' : 'var(--text-muted)', fontWeight: c.key === 'name' ? 700 : 400 }}>{row[c.key] || '—'}</span>}
                      </td>
                    ))}
                    <td className="px-3 py-2.5">
                      <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg"
                        style={row.is_active ? { background: 'rgba(16,185,129,0.12)', color: '#10b981' } : { background: 'var(--bg-input)', color: 'var(--text-muted)' }}>
                        {row.is_active ? 'Active' : 'Inactive'}
                      </span>
                    </td>
                    <td className="px-3 py-2.5">
                      <div className="flex gap-1.5 justify-end">
                        <button onClick={() => openEdit(row)} title="Edit" className="p-1.5 rounded-lg" style={{ background: 'rgba(124,58,237,0.1)', color: '#a78bfa' }}><Pencil size={13} /></button>
                        <button onClick={() => setConfirm(row)} title="Delete" className="p-1.5 rounded-lg" style={{ background: 'rgba(239,68,68,0.1)', color: '#f87171' }}><Trash2 size={13} /></button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

      {/* Create / Edit modal */}
      {modal && (
        <div className="modal-backdrop">
          <div className="modal-box max-w-lg" onClick={e => e.stopPropagation()} style={{ maxHeight: '90vh', overflowY: 'auto' }}>
            <div className="flex items-center justify-between mb-5">
              <h2 className="font-black text-lg" style={{ color: 'var(--text-h)' }}>{modal.editing ? `Edit ${cfg.singular}` : `Add ${cfg.singular}`}</h2>
              <button onClick={() => setModal(null)} style={{ color: 'var(--text-muted)' }}><X size={18} /></button>
            </div>
            <div className="grid grid-cols-2 gap-3">
              {cfg.fields.map(f => (
                <div key={f.key} className={f.full || f.type === 'textarea' ? 'col-span-2' : ''}>
                  <label className="label">{f.label}{f.required ? ' *' : ''}</label>
                  {f.type === 'textarea' ? (
                    <textarea rows={2} className="input-3d text-sm resize-none" value={modal.form[f.key] || ''} onChange={e => setModal(m => ({ ...m, form: { ...m.form, [f.key]: e.target.value } }))} />
                  ) : f.type === 'select' ? (
                    <select className="input-3d text-sm" value={modal.form[f.key] || ''} onChange={e => setModal(m => ({ ...m, form: { ...m.form, [f.key]: e.target.value } }))}>
                      <option value="">— None —</option>
                      {optsFor(f.optsFrom).map(o => <option key={o.id} value={o.id}>{o.name}{o.employee_code ? ` (${o.employee_code})` : ''}</option>)}
                    </select>
                  ) : f.type === 'skills' ? (
                    <SkillTagInput
                      value={modal.form[f.key] || []}
                      onChange={v => setModal(m => ({ ...m, form: { ...m.form, [f.key]: v } }))}
                      hint={f.hint}
                    />
                  ) : (
                    <input type={f.type === 'number' ? 'number' : 'text'} className="input-3d text-sm" placeholder={f.placeholder || ''} value={modal.form[f.key] ?? ''} onChange={e => setModal(m => ({ ...m, form: { ...m.form, [f.key]: e.target.value } }))} />
                  )}
                </div>
              ))}
            </div>
            <div className="flex gap-3 pt-5">
              <button onClick={() => setModal(null)} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>Cancel</button>
              <button onClick={save} disabled={saving} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background: GRAD, opacity: saving ? 0.7 : 1 }}>{saving ? 'Saving…' : modal.editing ? 'Save Changes' : `Add ${cfg.singular}`}</button>
            </div>
          </div>
        </div>
      )}

      {/* Delete confirmation */}
      {confirm && (
        <div className="modal-backdrop">
          <div className="modal-box max-w-sm" onClick={e => e.stopPropagation()}>
            <div className="flex items-center gap-3 mb-3">
              <div className="p-2 rounded-xl" style={{ background: 'rgba(239,68,68,0.12)' }}><AlertTriangle size={20} style={{ color: '#f87171' }} /></div>
              <h2 className="font-black text-base" style={{ color: 'var(--text-h)' }}>Delete {cfg.singular.toLowerCase()}?</h2>
            </div>
            <p className="text-sm mb-5" style={{ color: 'var(--text-muted)' }}>
              “<span className="font-bold" style={{ color: 'var(--text-h)' }}>{confirm.name}</span>” will be permanently removed. This is blocked if employees are still assigned to it.
            </p>
            <div className="flex gap-3">
              <button onClick={() => setConfirm(null)} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>Cancel</button>
              <button onClick={doDelete} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background: 'linear-gradient(135deg,#f87171,#ef4444)' }}>Delete</button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}

/* ────────────────────────────────────────────────────────────────────────
   Reporting Hierarchy — department-centric tree
   ──────────────────────────────────────────────────────────────────────── */
function HierarchyView({ showToast }) {
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    setLoading(true)
    hrApi.organization.hierarchy()
      .then(setData)
      .catch(() => showToast('Failed to load hierarchy', 'error'))
      .finally(() => setLoading(false))
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  if (loading) return <HrLoading label="Loading hierarchy…" />
  if (!data || (data.departments.length === 0 && data.unassigned.length === 0)) {
    return <HrEmpty icon={Network} title="No hierarchy yet" hint="Create departments and assign employees to see the reporting structure." />
  }

  const Member = ({ e }) => (
    <div className="flex items-center justify-between gap-2 px-3 py-2 rounded-xl" style={{ background: 'var(--bg-input)' }}>
      <div className="min-w-0">
        <p className="text-xs font-semibold truncate" style={{ color: 'var(--text-h)' }}>{e.name}</p>
        <p className="text-[10px] truncate" style={{ color: 'var(--text-muted)' }}>{e.designation || '—'}{e.reporting_manager_name ? ` · reports to ${e.reporting_manager_name}` : ''}</p>
      </div>
      <span className="text-[9px] font-mono font-bold whitespace-nowrap" style={{ color: '#a78bfa' }}>{e.employee_code}</span>
    </div>
  )

  return (
    <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
      {data.departments.map(d => (
        <div key={d.id} className="card-3d" style={{ padding: '18px' }}>
          <div className="flex items-center justify-between mb-3">
            <div className="flex items-center gap-2">
              <div className="p-1.5 rounded-lg" style={{ background: 'rgba(124,58,237,0.12)' }}><Building2 size={15} style={{ color: ACCENT }} /></div>
              <h3 className="font-black text-sm" style={{ color: 'var(--text-h)' }}>{d.name}</h3>
            </div>
            <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg flex items-center gap-1" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)' }}>
              <Users size={11} /> {d.members.length}
            </span>
          </div>
          <div className="mb-3 px-3 py-2 rounded-xl" style={{ background: 'rgba(124,58,237,0.06)', border: '1px dashed rgba(124,58,237,0.3)' }}>
            <p className="text-[10px]" style={{ color: 'var(--text-muted)' }}>Department Head</p>
            <p className="text-xs font-bold mt-0.5" style={{ color: d.head ? 'var(--text-h)' : 'var(--text-muted)' }}>{d.head ? `${d.head.name} · ${d.head.employee_code}` : 'Not assigned'}</p>
          </div>
          {d.members.length === 0
            ? <p className="text-xs" style={{ color: 'var(--text-muted)' }}>No employees assigned.</p>
            : <div className="space-y-1.5">{d.members.map(e => <Member key={e.id} e={e} />)}</div>}
        </div>
      ))}

      {data.unassigned.length > 0 && (
        <div className="card-3d" style={{ padding: '18px', borderColor: 'rgba(245,158,11,0.35)' }}>
          <div className="flex items-center gap-2 mb-3">
            <div className="p-1.5 rounded-lg" style={{ background: 'rgba(245,158,11,0.14)' }}><AlertTriangle size={15} style={{ color: '#f59e0b' }} /></div>
            <h3 className="font-black text-sm" style={{ color: 'var(--text-h)' }}>Unassigned</h3>
            <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background: 'rgba(245,158,11,0.12)', color: '#d97706' }}>{data.unassigned.length}</span>
          </div>
          <p className="text-[11px] mb-3" style={{ color: 'var(--text-muted)' }}>These employees have no department link yet — assign one from their profile.</p>
          <div className="space-y-1.5">{data.unassigned.map(e => <Member key={e.id} e={e} />)}</div>
        </div>
      )}
    </div>
  )
}

/**
 * Review comment #43 — the skill list attached to a department / designation /
 * grade / role.
 *
 * A plain tag editor: type a skill, press Enter or comma. Kept deliberately dumb —
 * the canonical vocabulary lives nowhere yet, and inventing a skills master here
 * would be a feature nobody asked for.
 */
function SkillTagInput({ value, onChange, hint }) {
  const [draft, setDraft] = useState('')
  const skills = Array.isArray(value) ? value : []

  const add = () => {
    const next = draft.trim()
    if (!next) return
    // Case-insensitive de-dup, matching how the backend compares them.
    if (!skills.some(s => s.toLowerCase() === next.toLowerCase())) onChange([...skills, next])
    setDraft('')
  }

  return (
    <div>
      <div className="flex flex-wrap gap-1.5 mb-2">
        {skills.length === 0 && <span className="text-[11px]" style={{ color: 'var(--text-muted)' }}>None set — no fit score is produced.</span>}
        {skills.map(s => (
          <span key={s} className="inline-flex items-center gap-1 px-2 py-0.5 rounded-lg text-[11px] font-semibold"
            style={{ background: 'rgba(124,58,237,0.12)', color: '#a78bfa' }}>
            {s}
            <button onClick={() => onChange(skills.filter(x => x !== s))} style={{ lineHeight: 1 }}>
              <X size={11} />
            </button>
          </span>
        ))}
      </div>
      <input
        className="input-3d text-sm"
        placeholder="Type a skill and press Enter"
        value={draft}
        onChange={e => setDraft(e.target.value)}
        onKeyDown={e => {
          if (e.key === 'Enter' || e.key === ',') { e.preventDefault(); add() }
          if (e.key === 'Backspace' && !draft && skills.length) onChange(skills.slice(0, -1))
        }}
        onBlur={add}
      />
      {hint && <p className="text-[10px] mt-1" style={{ color: 'var(--text-muted)' }}>{hint}</p>}
    </div>
  )
}
