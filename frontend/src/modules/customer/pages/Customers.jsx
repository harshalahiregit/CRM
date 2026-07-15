import { useState, useEffect, useRef } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  Plus, Search, Edit2, Trash2, X, Upload, Download, Building2, Users,
  UserCheck, TrendingUp, ChevronDown, FileSpreadsheet, FileText, Eye, UserCog, Sliders,
} from 'lucide-react'
import { customerApi } from '@/services/customerApi'
import { useToast } from '@/hooks/useToast'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import CustomFieldInput from '../components/CustomFieldInput'
import CustomFieldsManager from '../components/CustomFieldsManager'
import ToggleSwitch from '../components/ToggleSwitch'
import { CURRENCIES, LANGUAGES } from '../components/customerFormConstants'

// Old-CRM order. Customer Admins is edit-only (you must save the customer first),
// so it's appended only when editing.
const CREATE_TABS = ['Details', 'Custom Fields', 'Billing & Shipping']
const EDIT_TABS = [...CREATE_TABS, 'Customer Admins']
const EMPTY = {
  company: '', gst_number: '', phone: '', website: '', parent_company: '', vendor_id: '',
  opening_balance: '', opening_balance_date: '', show_primary_contact: false,
  default_currency: 'INR', default_language: 'English', group_ids: [],
  address: '', city: '', state: '', zip: '', country: '',
  billing_street: '', billing_city: '', billing_state: '', billing_zip: '', billing_country: '',
  shipping_street: '', shipping_city: '', shipping_state: '', shipping_zip: '', shipping_country: '',
  social_links: { linkedin: '', facebook: '', instagram: '', twitter: '' },
  foundation_date: '', dob: '', anniversary_date: '',
  contacts: [{ first_name: '', last_name: '', email: '', phone: '', title: '', is_primary: true }],
  apply_to_previous_documents: false,
}

export default function Customers() {
  const nav = useNavigate()
  const toast = useToast()
  const fileRef = useRef(null)

  const [rows, setRows] = useState([])
  const [meta, setMeta] = useState({})
  const [stats, setStats] = useState(null)
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')

  const [drawer, setDrawer] = useState(false)
  const [tab, setTab] = useState('Details')
  const [editing, setEditing] = useState(null)
  const [form, setForm] = useState(EMPTY)
  const [customFieldDefs, setCustomFieldDefs] = useState([])
  const [groupOptions, setGroupOptions] = useState([])
  const [adminData, setAdminData] = useState({ assigned: [], assignable: [] })
  const [saving, setSaving] = useState(false)
  const [fieldsMgr, setFieldsMgr] = useState(false)

  const [confirmDel, setConfirmDel] = useState(null)
  const [exportOpen, setExportOpen] = useState(false)
  const [sampleOpen, setSampleOpen] = useState(false)

  const sf = (k, v) => setForm(p => ({ ...p, [k]: v }))
  const sfSocial = (k, v) => setForm(p => ({ ...p, social_links: { ...p.social_links, [k]: v } }))

  const load = () => {
    setLoading(true)
    customerApi.list({ search: search || undefined, per_page: 25 })
      .then(res => { setRows(res.data ?? []); setMeta(res) })
      .catch(e => toast.error(e.message))
      .finally(() => setLoading(false))
  }
  const loadStats = () => customerApi.summary().then(setStats).catch(() => {})

  useEffect(() => { loadStats() }, [])
  useEffect(() => { const t = setTimeout(load, 250); return () => clearTimeout(t) }, [search])

  const openCreate = () => {
    setEditing(null); setForm(EMPTY); setTab('Details')
    setAdminData({ assigned: [], assignable: [] })
    customerApi.customFields.list().then(setCustomFieldDefs).catch(() => setCustomFieldDefs([]))
    customerApi.groups.list().then(setGroupOptions).catch(() => setGroupOptions([]))
    setDrawer(true)
  }

  const openEdit = async (row) => {
    try {
      const full = await customerApi.get(row.id)
      setEditing(full)
      const [defs, groups, admins] = await Promise.all([
        customerApi.customFields.list().catch(() => []),
        customerApi.groups.list().catch(() => []),
        customerApi.admins(row.id).catch(() => ({ assigned: [], assignable: [] })),
      ])
      setCustomFieldDefs(defs); setGroupOptions(groups); setAdminData(admins)
      const cfMap = {}
      ;(full.custom_fields ?? []).forEach(f => { cfMap[f.id] = f.value ?? '' })
      setForm({
        ...EMPTY, ...full,
        social_links: { ...EMPTY.social_links, ...(full.social_links || {}) },
        contacts: full.contacts?.length ? full.contacts : EMPTY.contacts,
        group_ids: (full.groups ?? []).map(g => g.id),
        custom_fields: cfMap,
        apply_to_previous_documents: false,
      })
      setTab('Details'); setDrawer(true)
    } catch (e) { toast.error(e.message) }
  }

  const toggleGroup = (gid) => setForm(p => ({
    ...p,
    group_ids: p.group_ids.includes(gid) ? p.group_ids.filter(x => x !== gid) : [...p.group_ids, gid],
  }))

  const addGroup = async () => {
    const name = window.prompt('New group name')
    if (!name?.trim()) return
    try {
      const g = await customerApi.groups.create({ name: name.trim() })
      setGroupOptions(prev => [...prev, g])
      toggleGroup(g.id)
    } catch (e) { toast.error(e.message) }
  }

  const toggleAdmin = async (userId) => {
    if (!editing) return
    const assignedIds = adminData.assigned.map(a => a.id)
    const next = assignedIds.includes(userId) ? assignedIds.filter(x => x !== userId) : [...assignedIds, userId]
    try {
      const updated = await customerApi.syncAdmins(editing.id, next)
      setAdminData(d => ({ ...d, assigned: updated }))
    } catch (e) { toast.error(e.message) }
  }

  const setContact = (i, k, v) => setForm(p => {
    const contacts = p.contacts.map((c, idx) => idx === i ? { ...c, [k]: v } : c)
    return { ...p, contacts }
  })
  const addContact = () => setForm(p => ({ ...p, contacts: [...p.contacts, { first_name: '', last_name: '', email: '', phone: '', title: '', is_primary: false }] }))
  const removeContact = (i) => setForm(p => ({ ...p, contacts: p.contacts.filter((_, idx) => idx !== i) }))

  const save = async () => {
    if (!form.company.trim()) { setTab('Details'); return toast.error('Company name is required') }
    setSaving(true)
    try {
      const payload = {
        ...form,
        vendor_id: form.vendor_id || null,
        opening_balance: form.opening_balance === '' ? null : form.opening_balance,
        opening_balance_date: form.opening_balance_date || null,
      }
      if (editing) { await customerApi.update(editing.id, payload); toast.success('Customer updated') }
      else { await customerApi.create(payload); toast.success('Customer created') }
      setDrawer(false); load(); loadStats()
    } catch (e) { toast.error(e.message) } finally { setSaving(false) }
  }

  const doDelete = async () => {
    try { await customerApi.remove(confirmDel.id); toast.success('Customer deleted'); load(); loadStats() }
    catch (e) { toast.error(e.message) } finally { setConfirmDel(null) }
  }

  // One-click active/inactive toggle (same as the old CRM's customer switch).
  const toggleActive = async (c) => {
    try {
      const res = await customerApi.toggleActive(c.id)
      setRows(rows => rows.map(r => r.id === c.id ? { ...r, active: res.active } : r))
      loadStats()
      toast.success(res.active ? 'Customer activated' : 'Customer deactivated')
    } catch (e) { toast.error(e.message) }
  }

  const onImportFile = async (e) => {
    const file = e.target.files?.[0]; e.target.value = ''
    if (!file) return
    try {
      const preview = await customerApi.import(file, true)
      if (!window.confirm(`Preview: ${preview.imported} rows will import, ${preview.skipped} skipped.${preview.errors?.length ? '\n\n' + preview.errors.slice(0, 5).join('\n') : ''}\n\nProceed with import?`)) return
      const res = await customerApi.import(file, false)
      toast.success(`Imported ${res.imported} customer(s)${res.skipped ? `, ${res.skipped} skipped` : ''}`)
      load(); loadStats()
    } catch (err) { toast.error(err.message) }
  }

  // Authenticated file download (GET needs the Bearer header → fetch as blob).
  const downloadFile = (url, filename) => {
    const token = localStorage.getItem('crm_token')
    fetch(url, { headers: { Authorization: `Bearer ${token}` } })
      .then(r => r.blob())
      .then(blob => {
        const objUrl = URL.createObjectURL(blob)
        const a = document.createElement('a')
        a.href = objUrl; a.download = filename
        a.click(); URL.revokeObjectURL(objUrl)
      })
      .catch(() => toast.error('Download failed'))
  }

  const doExport = (format) => {
    setExportOpen(false)
    downloadFile(customerApi.exportUrl(format, search), `customers_${new Date().toISOString().slice(0, 10)}.${format}`)
  }

  const doSample = (format) => {
    setSampleOpen(false)
    downloadFile(customerApi.sampleUrl(format), `customers_import_template.${format}`)
  }

  const STAT_CARDS = stats ? [
    { label: 'Total Customers', value: stats.total, icon: Building2, color: '#7C3AED' },
    { label: 'Active', value: stats.active, icon: UserCheck, color: '#10b981' },
    { label: 'Contacts', value: stats.contacts, icon: Users, color: '#3b82f6' },
    { label: 'Added This Month', value: stats.added_this_month, icon: TrendingUp, color: '#f59e0b' },
  ] : []

  return (
    <>
      <div className="space-y-6 animate-[tiltIn_0.35s_ease_forwards]">
        {/* Header */}
        <div className="flex items-center justify-between flex-wrap gap-3">
          <div>
            <p className="label-caps mb-1">Customers</p>
            <h1 className="font-black" style={{ fontSize: 'clamp(1.3rem,2vw,1.8rem)', color: 'var(--text-h)', letterSpacing: '-0.02em' }}>
              Customer <span className="text-gradient">Directory</span>
            </h1>
            <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>Companies, contacts, and their linked records across the CRM</p>
          </div>
          <div className="flex items-center gap-2">
            <input ref={fileRef} type="file" accept=".csv,.txt,.xlsx,.xls" className="hidden" onChange={onImportFile} />
            {/* Import (with a sample-template split) */}
            <div className="relative flex">
              <button onClick={() => fileRef.current?.click()}
                className="flex items-center gap-2 px-4 py-2.5 rounded-l-2xl text-sm font-semibold transition-all hover:scale-[1.02]"
                style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>
                <Upload size={14} /> Import
              </button>
              <button onClick={() => setSampleOpen(o => !o)} title="Download sample template"
                className="px-2 py-2.5 rounded-r-2xl transition-all hover:scale-[1.02]"
                style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', borderTop: '1px solid var(--border)', borderRight: '1px solid var(--border)', borderBottom: '1px solid var(--border)' }}>
                <ChevronDown size={13} />
              </button>
              {sampleOpen && (
                <div className="absolute left-0 top-full mt-2 w-56 rounded-2xl overflow-hidden z-20 shadow-2xl" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
                  <p className="px-4 pt-3 pb-1 text-[10px] font-bold" style={{ color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.05em' }}>Sample template</p>
                  <button onClick={() => doSample('csv')} className="w-full flex items-center gap-2 px-4 py-2.5 text-sm hover:bg-[rgba(124,58,237,0.08)] transition-colors" style={{ color: 'var(--text-h)' }}>
                    <FileText size={14} /> CSV template
                  </button>
                  <button onClick={() => doSample('xlsx')} className="w-full flex items-center gap-2 px-4 py-2.5 text-sm hover:bg-[rgba(124,58,237,0.08)] transition-colors" style={{ color: 'var(--text-h)', borderTop: '1px solid var(--border)' }}>
                    <FileSpreadsheet size={14} /> Excel template
                  </button>
                </div>
              )}
            </div>
            <div className="relative">
              <button onClick={() => setExportOpen(o => !o)}
                className="flex items-center gap-2 px-4 py-2.5 rounded-2xl text-sm font-semibold transition-all hover:scale-[1.02]"
                style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>
                <Download size={14} /> Export <ChevronDown size={13} />
              </button>
              {exportOpen && (
                <div className="absolute right-0 mt-2 w-44 rounded-2xl overflow-hidden z-20 shadow-2xl" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
                  <button onClick={() => doExport('csv')} className="w-full flex items-center gap-2 px-4 py-3 text-sm hover:bg-[rgba(124,58,237,0.08)] transition-colors" style={{ color: 'var(--text-h)' }}>
                    <FileText size={14} /> CSV (.csv)
                  </button>
                  <button onClick={() => doExport('xlsx')} className="w-full flex items-center gap-2 px-4 py-3 text-sm hover:bg-[rgba(124,58,237,0.08)] transition-colors" style={{ color: 'var(--text-h)', borderTop: '1px solid var(--border)' }}>
                    <FileSpreadsheet size={14} /> Excel (.xlsx)
                  </button>
                </div>
              )}
            </div>
            <button onClick={() => setFieldsMgr(true)} title="Manage custom fields"
              className="flex items-center gap-2 px-4 py-2.5 rounded-2xl text-sm font-semibold transition-all hover:scale-[1.02]"
              style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>
              <Sliders size={14} /> Custom Fields
            </button>
            <button onClick={openCreate} className="flex items-center gap-2 px-5 py-2.5 rounded-2xl text-sm font-bold text-white hover:scale-[1.03] transition-all"
              style={{ background: 'linear-gradient(135deg,#9f67ff,#7C3AED,#5b21b6)', boxShadow: '0 6px 20px rgba(124,58,237,0.45)' }}>
              <Plus size={15} /> New Customer
            </button>
          </div>
        </div>

        {/* Stat cards */}
        {stats && (
          <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
            {STAT_CARDS.map(({ label, value, icon: Icon, color }) => (
              <div key={label} className="card-3d flex items-center gap-3" style={{ padding: '18px' }}>
                <div className="w-11 h-11 rounded-2xl flex items-center justify-center" style={{ background: `${color}1a` }}>
                  <Icon size={18} style={{ color }} />
                </div>
                <div>
                  <p className="text-2xl font-black" style={{ color: 'var(--text-h)' }}>{value ?? 0}</p>
                  <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>{label}</p>
                </div>
              </div>
            ))}
          </div>
        )}

        {/* Search */}
        <div className="relative max-w-md">
          <Search size={14} className="absolute left-3.5 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
          <input value={search} onChange={e => setSearch(e.target.value)} placeholder="Search company, GST, phone…" className="input-3d text-sm pl-10 w-full" />
        </div>

        {/* Table */}
        <div className="card-3d overflow-hidden" style={{ padding: 0 }}>
          <div className="overflow-x-auto">
            <table className="w-full text-xs">
              <thead>
                <tr style={{ background: 'rgba(124,58,237,0.04)', borderBottom: '1px solid var(--border)' }}>
                  {['Company', 'GST Number', 'Phone', 'Contacts', 'Groups', 'Status', ''].map(h => (
                    <th key={h} className="py-3.5 px-4 text-left label-caps whitespace-nowrap">{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {loading ? (
                  [1, 2, 3, 4].map(i => <tr key={i}><td colSpan="7" className="p-3"><div className="skeleton h-8 rounded-lg" style={{ background: 'var(--border)' }} /></td></tr>)
                ) : rows.length === 0 ? (
                  <tr><td colSpan="7" className="py-12 text-center" style={{ color: 'var(--text-muted)' }}>No customers yet. Click “New Customer” or import a list.</td></tr>
                ) : rows.map(c => (
                  <tr key={c.id} className="transition-colors cursor-pointer" style={{ borderBottom: '1px solid var(--border)' }}
                    onClick={() => nav(`/app/customers/${c.id}`)}
                    onMouseEnter={e => e.currentTarget.style.background = 'rgba(124,58,237,0.04)'}
                    onMouseLeave={e => e.currentTarget.style.background = 'transparent'}>
                    <td className="py-3.5 px-4 font-bold" style={{ color: 'var(--text-h)' }}>{c.company}</td>
                    <td className="py-3.5 px-4" style={{ color: 'var(--text-muted)' }}>{c.gst_number || '—'}</td>
                    <td className="py-3.5 px-4" style={{ color: 'var(--text-muted)' }}>{c.phone || '—'}</td>
                    <td className="py-3.5 px-4" style={{ color: 'var(--text-muted)' }}>{c.contacts_count ?? 0}</td>
                    <td className="py-3.5 px-4">
                      <div className="flex gap-1 flex-wrap">
                        {(c.groups ?? []).map(g => <span key={g.id} className="px-2 py-0.5 rounded-lg text-[10px] font-bold" style={{ background: 'rgba(124,58,237,0.1)', color: 'var(--accent)' }}>{g.name}</span>)}
                        {!(c.groups ?? []).length && <span style={{ color: 'var(--text-muted)' }}>—</span>}
                      </div>
                    </td>
                    <td className="py-3.5 px-4" onClick={e => e.stopPropagation()}>
                      <div className="flex items-center gap-2">
                        <ToggleSwitch checked={c.active} onChange={() => toggleActive(c)} title="Toggle active/inactive" />
                        <span className="text-[10px] font-bold" style={{ color: c.active ? '#10b981' : '#94a3b8' }}>{c.active ? 'Active' : 'Inactive'}</span>
                      </div>
                    </td>
                    <td className="py-3.5 px-4" onClick={e => e.stopPropagation()}>
                      <div className="flex gap-1">
                        <button onClick={() => nav(`/app/customers/${c.id}`)} className="p-1.5 rounded-lg hover:bg-[rgba(124,58,237,0.08)] transition-colors" title="View profile"><Eye size={12} style={{ color: 'var(--text-muted)' }} /></button>
                        <button onClick={() => openEdit(c)} className="p-1.5 rounded-lg hover:bg-[rgba(124,58,237,0.08)] transition-colors" title="Edit"><Edit2 size={12} style={{ color: 'var(--text-muted)' }} /></button>
                        <button onClick={() => setConfirmDel(c)} className="p-1.5 rounded-lg hover:bg-[rgba(239,68,68,0.08)] transition-colors" title="Delete"><Trash2 size={12} style={{ color: '#f87171' }} /></button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {meta.total > (meta.per_page ?? 25) && (
            <div className="px-4 py-3 text-xs" style={{ color: 'var(--text-muted)', borderTop: '1px solid var(--border)' }}>
              Showing {rows.length} of {meta.total}
            </div>
          )}
        </div>
      </div>

      {/* ── Form Drawer ── */}
      {drawer && (
        <>
          <div className="drawer-backdrop" onClick={() => setDrawer(false)} />
          <div className="drawer-panel" style={{ width: 'min(640px, 96vw)' }}>
            <div className="drawer-header">
              <div>
                <h2 className="font-black text-lg" style={{ color: 'var(--text-h)', letterSpacing: '-0.02em' }}>{editing ? 'Edit Customer' : 'New Customer'}</h2>
                <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{editing ? 'Update company, contacts & addresses' : 'Add a company and its primary contact'}</p>
              </div>
              <button onClick={() => setDrawer(false)} className="w-9 h-9 rounded-xl flex items-center justify-center hover:bg-[rgba(239,68,68,0.08)] transition-colors" style={{ border: '1px solid var(--border)' }}>
                <X size={16} style={{ color: 'var(--text-muted)' }} />
              </button>
            </div>

            {/* Tabs */}
            <div className="flex gap-1 px-5 pt-4">
              {(editing ? EDIT_TABS : CREATE_TABS).map(t => (
                <button key={t} onClick={() => setTab(t)} className="px-3.5 py-2 rounded-xl text-xs font-bold transition-all"
                  style={{ background: tab === t ? 'linear-gradient(135deg,#7C3AED,#5b21b6)' : 'transparent', color: tab === t ? '#fff' : 'var(--text-muted)' }}>
                  {t}
                </button>
              ))}
            </div>

            <div className="drawer-body">
              {tab === 'Details' && (
                <div className="space-y-4">
                  <label className="flex items-center gap-2 text-xs font-semibold cursor-pointer" style={{ color: 'var(--text-muted)' }}>
                    <input type="checkbox" checked={!!form.show_primary_contact} onChange={e => sf('show_primary_contact', e.target.checked)} />
                    Show primary contact full name on Invoices, Estimates, Payments, Credit Notes
                  </label>
                  <div>
                    <label className="label">Company / Client Name *</label>
                    <input className="input-3d text-sm" placeholder="e.g. Acme Pvt Ltd" value={form.company} onChange={e => sf('company', e.target.value)} />
                  </div>
                  <div className="grid grid-cols-2 gap-4">
                    <div><label className="label">GST Number</label><input className="input-3d text-sm" value={form.gst_number} onChange={e => sf('gst_number', e.target.value)} /></div>
                    <div><label className="label">Phone</label><input className="input-3d text-sm" value={form.phone} onChange={e => sf('phone', e.target.value)} /></div>
                  </div>
                  <div className="grid grid-cols-2 gap-4">
                    <div><label className="label">Website</label><input className="input-3d text-sm" value={form.website} onChange={e => sf('website', e.target.value)} /></div>
                    <div><label className="label">Parent Company</label><input className="input-3d text-sm" placeholder="e.g. UBCPL Group" value={form.parent_company} onChange={e => sf('parent_company', e.target.value)} /></div>
                  </div>
                  <div className="grid grid-cols-2 gap-4">
                    <div><label className="label">Opening Balance</label><input type="number" className="input-3d text-sm" placeholder="0.00" value={form.opening_balance} onChange={e => sf('opening_balance', e.target.value)} /></div>
                    <div><label className="label">Balance as of</label><input type="date" className="input-3d text-sm" value={form.opening_balance_date || ''} onChange={e => sf('opening_balance_date', e.target.value)} /></div>
                  </div>
                  <div className="grid grid-cols-2 gap-4">
                    <div>
                      <label className="label">Currency</label>
                      <select className="input-3d text-sm" value={form.default_currency} onChange={e => sf('default_currency', e.target.value)}>
                        {CURRENCIES.map(c => <option key={c} value={c}>{c}</option>)}
                      </select>
                    </div>
                    <div>
                      <label className="label">Default Language</label>
                      <select className="input-3d text-sm" value={form.default_language} onChange={e => sf('default_language', e.target.value)}>
                        {LANGUAGES.map(l => <option key={l} value={l}>{l}</option>)}
                      </select>
                    </div>
                  </div>
                  {/* Groups */}
                  <div>
                    <div className="flex items-center justify-between mb-1">
                      <label className="label mb-0">Groups</label>
                      <button type="button" onClick={addGroup} className="text-xs font-bold flex items-center gap-1" style={{ color: 'var(--accent)' }}><Plus size={12} /> New group</button>
                    </div>
                    {groupOptions.length === 0 ? (
                      <p className="text-xs" style={{ color: 'var(--text-muted)' }}>No groups yet — create one.</p>
                    ) : (
                      <div className="flex gap-1.5 flex-wrap">
                        {groupOptions.map(g => {
                          const on = form.group_ids.includes(g.id)
                          return (
                            <button key={g.id} type="button" onClick={() => toggleGroup(g.id)} className="px-2.5 py-1 rounded-lg text-[11px] font-bold transition-all"
                              style={{ background: on ? 'linear-gradient(135deg,#7C3AED,#5b21b6)' : 'var(--bg-input)', color: on ? '#fff' : 'var(--text-muted)', border: '1px solid var(--border)' }}>
                              {g.name}
                            </button>
                          )
                        })}
                      </div>
                    )}
                  </div>

                  {/* Registered address */}
                  <div className="pt-2"><p className="label-caps mb-3" style={{ color: 'var(--accent)' }}>Registered Address</p></div>
                  <div><label className="label">Address</label><textarea rows={2} className="input-3d text-sm resize-none" value={form.address} onChange={e => sf('address', e.target.value)} /></div>
                  <div className="grid grid-cols-2 gap-4">
                    <div><label className="label">City</label><input className="input-3d text-sm" value={form.city} onChange={e => sf('city', e.target.value)} /></div>
                    <div><label className="label">State</label><input className="input-3d text-sm" value={form.state} onChange={e => sf('state', e.target.value)} /></div>
                  </div>
                  <div className="grid grid-cols-2 gap-4">
                    <div><label className="label">ZIP / PIN</label><input className="input-3d text-sm" value={form.zip} onChange={e => sf('zip', e.target.value)} /></div>
                    <div><label className="label">Country</label><input className="input-3d text-sm" value={form.country} onChange={e => sf('country', e.target.value)} /></div>
                  </div>

                  {/* Key dates + social */}
                  <div className="pt-2"><p className="label-caps mb-3" style={{ color: 'var(--accent)' }}>Profile & Social</p></div>
                  <div className="grid grid-cols-3 gap-3">
                    <div><label className="label">Founded</label><input type="date" className="input-3d text-sm" value={form.foundation_date || ''} onChange={e => sf('foundation_date', e.target.value)} /></div>
                    <div><label className="label">DOB</label><input type="date" className="input-3d text-sm" value={form.dob || ''} onChange={e => sf('dob', e.target.value)} /></div>
                    <div><label className="label">Anniversary</label><input type="date" className="input-3d text-sm" value={form.anniversary_date || ''} onChange={e => sf('anniversary_date', e.target.value)} /></div>
                  </div>
                  <div className="grid grid-cols-2 gap-4">
                    <div><label className="label">LinkedIn</label><input className="input-3d text-sm" value={form.social_links.linkedin} onChange={e => sfSocial('linkedin', e.target.value)} /></div>
                    <div><label className="label">Facebook</label><input className="input-3d text-sm" value={form.social_links.facebook} onChange={e => sfSocial('facebook', e.target.value)} /></div>
                  </div>
                  <div className="grid grid-cols-2 gap-4">
                    <div><label className="label">Instagram</label><input className="input-3d text-sm" value={form.social_links.instagram} onChange={e => sfSocial('instagram', e.target.value)} /></div>
                    <div><label className="label">Twitter / X</label><input className="input-3d text-sm" value={form.social_links.twitter} onChange={e => sfSocial('twitter', e.target.value)} /></div>
                  </div>

                  {/* Contacts */}
                  <div className="pt-2 flex items-center justify-between">
                    <p className="label-caps" style={{ color: 'var(--accent)' }}>Contacts</p>
                    <button onClick={addContact} className="text-xs font-bold flex items-center gap-1" style={{ color: 'var(--accent)' }}><Plus size={12} /> Add contact</button>
                  </div>
                  {form.contacts.map((c, i) => (
                    <div key={i} className="p-3 rounded-xl space-y-3" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
                      <div className="flex items-center justify-between">
                        <label className="flex items-center gap-2 text-xs font-semibold" style={{ color: 'var(--text-muted)' }}>
                          <input type="radio" name="primary" checked={!!c.is_primary} onChange={() => setForm(p => ({ ...p, contacts: p.contacts.map((x, idx) => ({ ...x, is_primary: idx === i })) }))} />
                          Primary contact
                        </label>
                        {form.contacts.length > 1 && <button onClick={() => removeContact(i)} className="p-1 rounded hover:bg-[rgba(239,68,68,0.08)]"><Trash2 size={12} style={{ color: '#f87171' }} /></button>}
                      </div>
                      <div className="grid grid-cols-2 gap-3">
                        <input className="input-3d text-sm" placeholder="First name" value={c.first_name} onChange={e => setContact(i, 'first_name', e.target.value)} />
                        <input className="input-3d text-sm" placeholder="Last name" value={c.last_name || ''} onChange={e => setContact(i, 'last_name', e.target.value)} />
                      </div>
                      <div className="grid grid-cols-2 gap-3">
                        <input className="input-3d text-sm" placeholder="Email" value={c.email || ''} onChange={e => setContact(i, 'email', e.target.value)} />
                        <input className="input-3d text-sm" placeholder="Phone" value={c.phone || ''} onChange={e => setContact(i, 'phone', e.target.value)} />
                      </div>
                      <input className="input-3d text-sm" placeholder="Job title / position" value={c.title || ''} onChange={e => setContact(i, 'title', e.target.value)} />
                    </div>
                  ))}
                </div>
              )}

              {tab === 'Billing & Shipping' && (
                <div className="grid md:grid-cols-2 gap-6">
                  <div className="space-y-3">
                    <div className="flex items-center justify-between">
                      <p className="label-caps" style={{ color: 'var(--accent)' }}>Billing</p>
                      <button onClick={() => setForm(p => ({ ...p, billing_street: p.address, billing_city: p.city, billing_state: p.state, billing_zip: p.zip, billing_country: p.country }))}
                        className="text-[11px] font-bold" style={{ color: 'var(--accent)' }}>Same as registered</button>
                    </div>
                    <textarea rows={2} className="input-3d text-sm resize-none" placeholder="Street" value={form.billing_street} onChange={e => sf('billing_street', e.target.value)} />
                    <input className="input-3d text-sm" placeholder="City" value={form.billing_city} onChange={e => sf('billing_city', e.target.value)} />
                    <input className="input-3d text-sm" placeholder="State" value={form.billing_state} onChange={e => sf('billing_state', e.target.value)} />
                    <div className="grid grid-cols-2 gap-3">
                      <input className="input-3d text-sm" placeholder="ZIP" value={form.billing_zip} onChange={e => sf('billing_zip', e.target.value)} />
                      <input className="input-3d text-sm" placeholder="Country" value={form.billing_country} onChange={e => sf('billing_country', e.target.value)} />
                    </div>
                  </div>
                  <div className="space-y-3">
                    <div className="flex items-center justify-between">
                      <p className="label-caps" style={{ color: 'var(--accent)' }}>Shipping</p>
                      <button onClick={() => setForm(p => ({ ...p, shipping_street: p.billing_street, shipping_city: p.billing_city, shipping_state: p.billing_state, shipping_zip: p.billing_zip, shipping_country: p.billing_country }))}
                        className="text-[11px] font-bold" style={{ color: 'var(--accent)' }}>Copy billing</button>
                    </div>
                    <textarea rows={2} className="input-3d text-sm resize-none" placeholder="Street" value={form.shipping_street} onChange={e => sf('shipping_street', e.target.value)} />
                    <input className="input-3d text-sm" placeholder="City" value={form.shipping_city} onChange={e => sf('shipping_city', e.target.value)} />
                    <input className="input-3d text-sm" placeholder="State" value={form.shipping_state} onChange={e => sf('shipping_state', e.target.value)} />
                    <div className="grid grid-cols-2 gap-3">
                      <input className="input-3d text-sm" placeholder="ZIP" value={form.shipping_zip} onChange={e => sf('shipping_zip', e.target.value)} />
                      <input className="input-3d text-sm" placeholder="Country" value={form.shipping_country} onChange={e => sf('shipping_country', e.target.value)} />
                    </div>
                  </div>

                  {editing && (
                    <label className="md:col-span-2 flex items-start gap-2 p-3 rounded-xl text-xs cursor-pointer" style={{ background: 'rgba(245,158,11,0.08)', border: '1px solid rgba(245,158,11,0.25)' }}>
                      <input type="checkbox" className="mt-0.5" checked={form.apply_to_previous_documents} onChange={e => sf('apply_to_previous_documents', e.target.checked)} />
                      <span style={{ color: 'var(--text-muted)' }}>
                        <b style={{ color: '#f59e0b' }}>Apply to all previous invoices & estimates.</b> By default a new address only affects future documents. Tick to overwrite the address on every existing document (including paid) for this customer.
                      </span>
                    </label>
                  )}
                </div>
              )}

              {tab === 'Custom Fields' && (
                <div className="space-y-4">
                  {customFieldDefs.length === 0 ? (
                    <div className="py-10 text-center text-sm" style={{ color: 'var(--text-muted)' }}>
                      No custom fields defined yet.<br />Define them under Customers → Custom Fields settings.
                    </div>
                  ) : customFieldDefs.map(def => (
                    <div key={def.id}>
                      <label className="label">{def.name}{def.required ? ' *' : ''}</label>
                      <CustomFieldInput def={def}
                        value={form.custom_fields?.[def.id] ?? def.default_value ?? ''}
                        onChange={v => setForm(p => ({ ...p, custom_fields: { ...(p.custom_fields || {}), [def.id]: v } }))} />
                    </div>
                  ))}
                </div>
              )}

              {tab === 'Customer Admins' && (
                <div className="space-y-3">
                  <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Staff assigned here are the account managers for this customer.</p>
                  {adminData.assignable.length === 0 ? (
                    <div className="py-8 text-center text-sm" style={{ color: 'var(--text-muted)' }}>No staff users available to assign.</div>
                  ) : adminData.assignable.map(u => {
                    const on = adminData.assigned.some(a => a.id === u.id)
                    return (
                      <label key={u.id} className="flex items-center gap-3 p-3 rounded-xl cursor-pointer" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
                        <input type="checkbox" checked={on} onChange={() => toggleAdmin(u.id)} />
                        <div className="w-8 h-8 rounded-xl flex items-center justify-center" style={{ background: 'rgba(124,58,237,0.12)' }}>
                          <UserCog size={14} style={{ color: 'var(--accent)' }} />
                        </div>
                        <div>
                          <p className="text-sm font-semibold" style={{ color: 'var(--text-h)' }}>{u.name}</p>
                          <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>{u.email}</p>
                        </div>
                      </label>
                    )
                  })}
                </div>
              )}
            </div>

            <div className="drawer-footer">
              <button onClick={() => setDrawer(false)} className="flex-1 py-3 rounded-2xl text-sm font-semibold" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>Cancel</button>
              <button onClick={save} disabled={saving} className="flex-[2] py-3 rounded-2xl text-sm font-bold text-white hover:scale-[1.01] transition-all disabled:opacity-60"
                style={{ background: 'linear-gradient(135deg,#9f67ff,#7C3AED,#5b21b6)', boxShadow: '0 6px 20px rgba(124,58,237,0.4)' }}>
                {saving ? 'Saving…' : editing ? 'Save Changes' : 'Create Customer'}
              </button>
            </div>
          </div>
        </>
      )}

      {confirmDel && (
        <ConfirmDialog
          title="Delete customer?"
          message={`This will remove “${confirmDel.company}” and its contacts. Linked invoices/tickets are not deleted.`}
          confirmLabel="Delete"
          onConfirm={doDelete}
          onCancel={() => setConfirmDel(null)}
        />
      )}

      {fieldsMgr && <CustomFieldsManager onClose={() => setFieldsMgr(false)} />}
    </>
  )
}
