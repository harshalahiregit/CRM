import { useState, useEffect } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  Settings as SettingsIcon, Loader2, Lock, Unlock, Plus, Trash2, Pencil, X,
  Building2, CalendarRange, Hash, Network, Layers, Tags, ArrowLeftRight,
  BookOpen, Percent, Barcode, Receipt, History,
} from 'lucide-react'
import { accountsApi } from '@/services/accountsApi'
import { fmtDate } from '@/modules/accounts/format'
import { useToast } from '@/hooks/useToast'
import FormField, { Input, Select } from '@/components/ui/FormField'
import { GhostButton } from '@/modules/accounts/components/Btn'
import AccountGroupsManager from '@/modules/accounts/components/AccountGroupsManager'
import VoucherTypesManager from '@/modules/accounts/components/VoucherTypesManager'
import BillCategoriesManager from '@/modules/accounts/components/BillCategoriesManager'
import TransferCategoriesManager from '@/modules/accounts/components/TransferCategoriesManager'
import ChequebooksManager from '@/modules/accounts/components/ChequebooksManager'

// Grouped left-nav — sections separate the one-off (Company, Numbering) from
// the growing list of manageable masters/categories, instead of one long row
// of same-weight tabs.
const GROUPS = [
  {
    heading: 'General',
    items: [
      { key: 'Company', label: 'Company Profile', icon: Building2 },
      { key: 'Financial Years', label: 'Financial Years', icon: CalendarRange },
      { key: 'Numbering', label: 'Numbering Series', icon: Hash },
    ],
  },
  {
    heading: 'Chart Setup',
    items: [
      { key: 'Account Groups', label: 'Account Groups', icon: Network },
      { key: 'Voucher Types', label: 'Voucher Types', icon: Layers },
    ],
  },
  {
    heading: 'Categories & Masters',
    items: [
      { key: 'Bill Categories', label: 'Bill Categories', icon: Tags },
      { key: 'Transfer Categories', label: 'Transfer Categories', icon: ArrowLeftRight },
      { key: 'Chequebooks', label: 'Chequebooks', icon: BookOpen },
    ],
  },
  {
    heading: 'Tax & Compliance',
    items: [
      { key: 'GST Rates', label: 'GST Rates', icon: Percent },
      { key: 'HSN/SAC', label: 'HSN / SAC Codes', icon: Barcode },
      { key: 'TDS', label: 'TDS Sections', icon: Receipt },
    ],
  },
  {
    heading: 'System',
    items: [
      { key: 'Audit Trail', label: 'Audit Trail', icon: History },
    ],
  },
]

const PANELS = {
  'Company': CompanyTab,
  'Financial Years': FinancialYearsTab,
  'Numbering': NumberingTab,
  'Account Groups': AccountGroupsManager,
  'Voucher Types': VoucherTypesManager,
  'Bill Categories': BillCategoriesManager,
  'Transfer Categories': TransferCategoriesManager,
  'Chequebooks': ChequebooksManager,
  'GST Rates': GstRatesTab,
  'HSN/SAC': HsnSacTab,
  'TDS': TdsTab,
  'Audit Trail': AuditTab,
}

// These panels already render their own title + description — the shell
// header would just duplicate it, so it's suppressed for these keys.
const SELF_HEADED = new Set(['Account Groups', 'Voucher Types', 'Bill Categories', 'Transfer Categories', 'Chequebooks'])

export default function AccountsSettings() {
  const [tab, setTab] = useState('Company')
  const active = GROUPS.flatMap(g => g.items).find(i => i.key === tab)
  const Panel = PANELS[tab]

  return (
    <div className="animate-fade-in max-w-6xl">
      <div className="flex items-center gap-3 mb-6">
        <div className="w-10 h-10 rounded-2xl flex items-center justify-center" style={{ background: 'rgba(124,58,237,0.12)' }}>
          <SettingsIcon size={18} style={{ color: '#a78bfa' }} />
        </div>
        <div>
          <h1 className="text-xl font-black" style={{ color: 'var(--text-h)' }}>Accounts Settings</h1>
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Company details, numbering, and every manageable master in one place</p>
        </div>
      </div>

      <div className="flex flex-col md:flex-row gap-5 items-start">
        {/* Grouped section nav */}
        <nav className="w-full md:w-60 flex-shrink-0 card-3d space-y-4" style={{ padding: '14px 10px' }}>
          {GROUPS.map(group => (
            <div key={group.heading}>
              <p className="label-caps px-3 mb-1" style={{ color: 'var(--text-faint, var(--text-muted))', fontSize: 10 }}>{group.heading}</p>
              {group.items.map(({ key, label, icon: Icon }) => (
                <button key={key} onClick={() => setTab(key)}
                  className="w-full flex items-center gap-2.5 px-3 py-2.5 rounded-xl text-sm font-semibold mb-0.5 transition-colors text-left"
                  style={tab === key
                    ? { background: 'linear-gradient(135deg,#7C3AED,#6d28d9)', color: '#fff' }
                    : { color: 'var(--text-body)', background: 'transparent' }}
                  onMouseEnter={e => { if (tab !== key) e.currentTarget.style.background = 'rgba(124,58,237,0.06)' }}
                  onMouseLeave={e => { if (tab !== key) e.currentTarget.style.background = 'transparent' }}>
                  <Icon size={15} style={{ flexShrink: 0 }} /> {label}
                </button>
              ))}
            </div>
          ))}
        </nav>

        {/* Active panel — panels bring their own boxed sections (kpi-3d forms,
            table-wrapper lists), so this stays unboxed to avoid double-carding. */}
        <div className="flex-1 min-w-0 w-full">
          {!SELF_HEADED.has(tab) && (
            <div className="flex items-center gap-2 mb-4">
              {active && <active.icon size={16} style={{ color: '#a78bfa' }} />}
              <h2 className="text-sm font-black" style={{ color: 'var(--text-h)' }}>{active?.label}</h2>
            </div>
          )}
          {SELF_HEADED.has(tab)
            ? <div className="card-3d" style={{ padding: 20 }}>{Panel && <Panel />}</div>
            : Panel && <Panel />}
        </div>
      </div>
    </div>
  )
}

function GstRatesTab() {
  const toast = useToast(); const qc = useQueryClient()
  const { data: rates = [], isLoading } = useQuery({ queryKey: ['accounts', 'gst-rates'], queryFn: accountsApi.gstRates.list })
  const [form, setForm] = useState({ name: '', rate_percent: '', cgst: '', sgst: '', igst: '', cess: 0 })
  const inv = () => qc.invalidateQueries({ queryKey: ['accounts', 'gst-rates'] })
  const create = useMutation({ mutationFn: () => accountsApi.gstRates.create(form), onSuccess: () => { toast.success('Rate added'); setForm({ name: '', rate_percent: '', cgst: '', sgst: '', igst: '', cess: 0 }); inv() }, onError: e => toast.error(e.message) })
  const remove = useMutation({ mutationFn: (id) => accountsApi.gstRates.remove(id), onSuccess: () => { toast.success('Deleted'); inv() }, onError: e => toast.error(e.message) })
  if (isLoading) return <Spin />
  const set = (k, v) => setForm(f => ({ ...f, [k]: v }))
  // convenience: auto-split when rate typed
  const onRate = (v) => setForm(f => ({ ...f, rate_percent: v, igst: v, cgst: v ? v / 2 : '', sgst: v ? v / 2 : '' }))
  return (
    <div className="space-y-4">
      <div className="kpi-3d grid grid-cols-2 sm:grid-cols-6 gap-3 items-end">
        <FormField label="Name"><Input value={form.name} onChange={e => set('name', e.target.value)} placeholder="GST 18%" /></FormField>
        <FormField label="Rate %"><Input type="number" step="0.01" value={form.rate_percent} onChange={e => onRate(e.target.value)} /></FormField>
        <FormField label="CGST"><Input type="number" step="0.01" value={form.cgst} onChange={e => set('cgst', e.target.value)} /></FormField>
        <FormField label="SGST"><Input type="number" step="0.01" value={form.sgst} onChange={e => set('sgst', e.target.value)} /></FormField>
        <FormField label="IGST"><Input type="number" step="0.01" value={form.igst} onChange={e => set('igst', e.target.value)} /></FormField>
        <button className="btn-3d" disabled={!form.name || form.rate_percent === '' || create.isPending} onClick={() => create.mutate()}>Add</button>
      </div>
      <div className="table-wrapper"><table className="table">
        <thead><tr><th>Name</th><th style={{ textAlign: 'right' }}>Rate</th><th style={{ textAlign: 'right' }}>CGST</th><th style={{ textAlign: 'right' }}>SGST</th><th style={{ textAlign: 'right' }}>IGST</th><th style={{ textAlign: 'right' }}>Cess</th><th></th></tr></thead>
        <tbody>{rates.map(r => (
          <tr key={r.id}>
            <td style={{ color: 'var(--text-h)', fontWeight: 600 }}>{r.name}</td>
            <td style={{ textAlign: 'right' }}>{r.rate_percent}%</td><td style={{ textAlign: 'right' }}>{r.cgst}</td><td style={{ textAlign: 'right' }}>{r.sgst}</td><td style={{ textAlign: 'right' }}>{r.igst}</td><td style={{ textAlign: 'right' }}>{r.cess}</td>
            <td style={{ textAlign: 'right' }}><button onClick={() => remove.mutate(r.id)} style={{ color: '#f87171' }}><Trash2 size={14} /></button></td>
          </tr>
        ))}</tbody>
      </table></div>
    </div>
  )
}

const HSN_EMPTY = { code: '', description: '', type: 'hsn', default_gst_rate_id: '', is_active: true }
function HsnSacTab() {
  const toast = useToast(); const qc = useQueryClient()
  const [search, setSearch] = useState('')
  const { data: page, isLoading } = useQuery({ queryKey: ['accounts', 'hsn-sac', search], queryFn: () => accountsApi.hsnSac.list(search) })
  const { data: rates = [] } = useQuery({ queryKey: ['accounts', 'gst-rates'], queryFn: accountsApi.gstRates.list })
  const rows = page?.data ?? []
  const [form, setForm] = useState(HSN_EMPTY)
  const [editId, setEditId] = useState(null)
  const inv = () => qc.invalidateQueries({ queryKey: ['accounts', 'hsn-sac'] })
  const reset = () => { setForm(HSN_EMPTY); setEditId(null) }

  const payload = () => ({ ...form, default_gst_rate_id: form.default_gst_rate_id || null })
  const create = useMutation({ mutationFn: () => accountsApi.hsnSac.create(payload()), onSuccess: () => { toast.success('Code added'); reset(); inv() }, onError: e => toast.error(e.message) })
  const update = useMutation({ mutationFn: () => accountsApi.hsnSac.update(editId, payload()), onSuccess: () => { toast.success('Code updated'); reset(); inv() }, onError: e => toast.error(e.message) })
  // The update endpoint requires code+type, so a toggle resends the full row.
  const toggle = useMutation({ mutationFn: (r) => accountsApi.hsnSac.update(r.id, { code: r.code, description: r.description, type: r.type, default_gst_rate_id: r.default_gst_rate_id || null, is_active: !r.is_active }), onSuccess: inv, onError: e => toast.error(e.message) })
  const remove = useMutation({ mutationFn: (id) => accountsApi.hsnSac.remove(id), onSuccess: () => { toast.success('Deleted'); inv() }, onError: e => toast.error(e.message) })
  if (isLoading) return <Spin />
  const set = (k, v) => setForm(f => ({ ...f, [k]: v }))
  const startEdit = (r) => { setEditId(r.id); setForm({ code: r.code, description: r.description || '', type: r.type, default_gst_rate_id: r.default_gst_rate_id || '', is_active: r.is_active ?? true }) }
  const saving = create.isPending || update.isPending

  return (
    <div className="space-y-4">
      <p className="text-xs" style={{ color: 'var(--text-muted)' }}>The HSN (goods) / SAC (services) master. Codes here appear as suggestions on document line items, and a code's Default GST auto-fills the line's tax.</p>
      <div className="kpi-3d grid grid-cols-2 sm:grid-cols-5 gap-3 items-end">
        <FormField label="Code"><Input value={form.code} onChange={e => set('code', e.target.value)} placeholder="e.g. 998314" /></FormField>
        <FormField label="Description"><Input value={form.description} onChange={e => set('description', e.target.value)} /></FormField>
        <FormField label="Type"><Select value={form.type} onChange={e => set('type', e.target.value)}><option value="hsn">HSN (goods)</option><option value="sac">SAC (services)</option></Select></FormField>
        <FormField label="Default GST"><Select value={form.default_gst_rate_id} onChange={e => set('default_gst_rate_id', e.target.value)}><option value="">—</option>{rates.map(r => <option key={r.id} value={r.id}>{r.name}</option>)}</Select></FormField>
        <div className="flex gap-2">
          <button className="btn-3d flex-1" disabled={!form.code || saving} onClick={() => (editId ? update.mutate() : create.mutate())}>{editId ? 'Update' : 'Add'}</button>
          {editId && <button className="px-2 rounded-lg" style={{ border: '1px solid var(--border)', color: 'var(--text-muted)' }} onClick={reset} title="Cancel edit"><X size={15} /></button>}
        </div>
      </div>
      <Input value={search} onChange={e => setSearch(e.target.value)} placeholder="Search code / description…" style={{ maxWidth: 280 }} />
      <div className="table-wrapper"><table className="table">
        <thead><tr><th>Code</th><th>Description</th><th>Type</th><th>Default GST</th><th style={{ textAlign: 'center' }}>Active</th><th></th></tr></thead>
        <tbody>{rows.length === 0 && <tr><td colSpan={6} style={{ color: 'var(--text-muted)' }}>No HSN/SAC codes yet.</td></tr>}
        {rows.map(r => (
          <tr key={r.id} style={{ opacity: r.is_active === false ? 0.55 : 1 }}>
            <td style={{ color: 'var(--text-h)', fontWeight: 600 }}>{r.code}</td>
            <td style={{ color: 'var(--text-muted)' }}>{r.description || '—'}</td>
            <td className="uppercase text-xs">{r.type}</td>
            <td>{r.default_rate?.name || '—'}</td>
            <td style={{ textAlign: 'center' }}>
              <input type="checkbox" checked={r.is_active ?? true} onChange={() => toggle.mutate(r)} title="Active" />
            </td>
            <td style={{ textAlign: 'right', whiteSpace: 'nowrap' }}>
              <button onClick={() => startEdit(r)} style={{ color: '#a78bfa', marginRight: 10 }} title="Edit"><Pencil size={13} /></button>
              <button onClick={() => remove.mutate(r.id)} style={{ color: '#f87171' }} title="Delete"><Trash2 size={14} /></button>
            </td>
          </tr>
        ))}</tbody>
      </table></div>
    </div>
  )
}

function TdsTab() {
  const toast = useToast(); const qc = useQueryClient()
  const { data: rows = [], isLoading } = useQuery({ queryKey: ['accounts', 'tds-sections'], queryFn: accountsApi.tdsSections.list })
  const [form, setForm] = useState({ section_code: '', description: '', rate_percent: '', threshold: 0 })
  const inv = () => qc.invalidateQueries({ queryKey: ['accounts', 'tds-sections'] })
  const create = useMutation({ mutationFn: () => accountsApi.tdsSections.create(form), onSuccess: () => { toast.success('Saved'); setForm({ section_code: '', description: '', rate_percent: '', threshold: 0 }); inv() }, onError: e => toast.error(e.message) })
  const remove = useMutation({ mutationFn: (id) => accountsApi.tdsSections.remove(id), onSuccess: () => { toast.success('Deleted'); inv() }, onError: e => toast.error(e.message) })
  if (isLoading) return <Spin />
  const set = (k, v) => setForm(f => ({ ...f, [k]: v }))
  return (
    <div className="space-y-4">
      <div className="kpi-3d grid grid-cols-2 sm:grid-cols-5 gap-3 items-end">
        <FormField label="Section"><Input value={form.section_code} onChange={e => set('section_code', e.target.value)} placeholder="194J" /></FormField>
        <FormField label="Description"><Input value={form.description} onChange={e => set('description', e.target.value)} /></FormField>
        <FormField label="Rate %"><Input type="number" step="0.01" value={form.rate_percent} onChange={e => set('rate_percent', e.target.value)} /></FormField>
        <FormField label="Threshold"><Input type="number" step="0.01" value={form.threshold} onChange={e => set('threshold', e.target.value)} /></FormField>
        <button className="btn-3d" disabled={!form.section_code || form.rate_percent === '' || create.isPending} onClick={() => create.mutate()}>Add</button>
      </div>
      <div className="table-wrapper"><table className="table">
        <thead><tr><th>Section</th><th>Description</th><th style={{ textAlign: 'right' }}>Rate</th><th style={{ textAlign: 'right' }}>Threshold</th><th></th></tr></thead>
        <tbody>{rows.map(r => (
          <tr key={r.id}><td style={{ color: 'var(--text-h)', fontWeight: 600 }}>{r.section_code}</td><td style={{ color: 'var(--text-muted)' }}>{r.description || '—'}</td><td style={{ textAlign: 'right' }}>{r.rate_percent}%</td><td style={{ textAlign: 'right' }}>{Number(r.threshold).toLocaleString('en-IN')}</td>
          <td style={{ textAlign: 'right' }}><button onClick={() => remove.mutate(r.id)} style={{ color: '#f87171' }}><Trash2 size={14} /></button></td></tr>
        ))}</tbody>
      </table></div>
    </div>
  )
}

function Spin() {
  return <div className="flex justify-center py-10"><Loader2 className="animate-spin" style={{ color: 'var(--text-muted)' }} /></div>
}

function CompanyTab() {
  const toast = useToast()
  const qc = useQueryClient()
  const { data, isLoading } = useQuery({ queryKey: ['accounts', 'company-profile'], queryFn: accountsApi.companyProfile.get })
  const [form, setForm] = useState(null)
  useEffect(() => {
    setForm({
      legal_name: data?.legal_name || '', trade_name: data?.trade_name || '',
      pan: data?.pan || '', gstin: data?.gstin || '', tan: data?.tan || '',
      state_code: data?.state_code || '', entity_type: data?.entity_type || 'proprietorship',
      books_begin_date: data?.books_begin_date?.slice(0, 10) || '', base_currency: data?.base_currency || 'INR',
    })
  }, [data])

  const save = useMutation({
    mutationFn: (f) => accountsApi.companyProfile.save(f),
    onSuccess: () => { toast.success('Company profile saved'); qc.invalidateQueries({ queryKey: ['accounts', 'company-profile'] }) },
    onError: (e) => toast.error(e.message),
  })

  if (isLoading || !form) return <div className="flex justify-center py-10"><Loader2 className="animate-spin" style={{ color: 'var(--text-muted)' }} /></div>
  const set = (k, v) => setForm(f => ({ ...f, [k]: v }))

  return (
    <div className="kpi-3d space-y-4">
      <div className="grid gap-4 sm:grid-cols-2">
        <FormField label="Legal name" required><Input value={form.legal_name} onChange={e => set('legal_name', e.target.value)} /></FormField>
        <FormField label="Trade name"><Input value={form.trade_name} onChange={e => set('trade_name', e.target.value)} /></FormField>
        <FormField label="PAN"><Input value={form.pan} onChange={e => set('pan', e.target.value)} /></FormField>
        <FormField label="GSTIN"><Input value={form.gstin} onChange={e => set('gstin', e.target.value)} /></FormField>
        <FormField label="TAN"><Input value={form.tan} onChange={e => set('tan', e.target.value)} /></FormField>
        <FormField label="State code" hint="GST place-of-supply"><Input value={form.state_code} onChange={e => set('state_code', e.target.value)} maxLength={2} /></FormField>
        <FormField label="Entity type">
          <Select value={form.entity_type} onChange={e => set('entity_type', e.target.value)}>
            {['company', 'llp', 'partnership', 'proprietorship', 'trust'].map(t => <option key={t} value={t}>{t}</option>)}
          </Select>
        </FormField>
        <FormField label="Books begin date"><Input type="date" value={form.books_begin_date} onChange={e => set('books_begin_date', e.target.value)} /></FormField>
      </div>
      <div className="flex justify-end">
        <button className="btn-3d flex items-center gap-2" disabled={!form.legal_name.trim() || save.isPending} onClick={() => save.mutate(form)}>
          {save.isPending && <Loader2 size={15} className="animate-spin" />} Save
        </button>
      </div>
    </div>
  )
}

function FinancialYearsTab() {
  const toast = useToast()
  const qc = useQueryClient()
  const { data: years = [], isLoading } = useQuery({ queryKey: ['accounts', 'financial-years'], queryFn: accountsApi.financialYears.list })
  const [form, setForm] = useState({ start_date: '', end_date: '' })

  const invalidate = () => qc.invalidateQueries({ queryKey: ['accounts', 'financial-years'] })
  const create = useMutation({
    mutationFn: () => accountsApi.financialYears.create(form),
    onSuccess: () => { toast.success('Financial year added'); setForm({ start_date: '', end_date: '' }); invalidate() },
    onError: (e) => toast.error(e.message),
  })
  const lock = useMutation({
    mutationFn: ({ id, closed }) => accountsApi.financialYears.lock(id, closed),
    onSuccess: () => { toast.success('Updated'); invalidate() },
    onError: (e) => toast.error(e.message),
  })

  if (isLoading) return <div className="flex justify-center py-10"><Loader2 className="animate-spin" style={{ color: 'var(--text-muted)' }} /></div>

  return (
    <div className="space-y-4">
      <div className="kpi-3d flex flex-wrap items-end gap-3">
        <FormField label="Start date"><Input type="date" value={form.start_date} onChange={e => setForm(f => ({ ...f, start_date: e.target.value }))} /></FormField>
        <FormField label="End date"><Input type="date" value={form.end_date} onChange={e => setForm(f => ({ ...f, end_date: e.target.value }))} /></FormField>
        <button className="btn-3d flex items-center gap-2 mb-0.5" disabled={!form.start_date || !form.end_date || create.isPending} onClick={() => create.mutate()}>
          <Plus size={15} /> Add
        </button>
      </div>

      <div className="table-wrapper">
        <table className="table">
          <thead><tr><th>Period</th><th>Status</th><th style={{ textAlign: 'right' }}>Action</th></tr></thead>
          <tbody>
            {years.map(y => (
              <tr key={y.id}>
                <td style={{ color: 'var(--text-h)', fontWeight: 600 }}>{fmtDate(y.start_date)} – {fmtDate(y.end_date)}</td>
                <td style={{ color: y.is_closed ? '#f87171' : '#10b981', fontWeight: 700 }}>{y.is_closed ? 'Closed' : 'Open'}</td>
                <td style={{ textAlign: 'right' }}>
                  <GhostButton className="inline-flex items-center gap-1.5 !py-1.5 !px-3" onClick={() => lock.mutate({ id: y.id, closed: !y.is_closed })}>
                    {y.is_closed ? <><Unlock size={13} /> Reopen</> : <><Lock size={13} /> Close</>}
                  </GhostButton>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}

function NumberingTab() {
  const toast = useToast()
  const qc = useQueryClient()
  const { data: series = [], isLoading } = useQuery({ queryKey: ['accounts', 'numbering-series'], queryFn: accountsApi.numberingSeries.list })

  const update = useMutation({
    mutationFn: ({ id, data }) => accountsApi.numberingSeries.update(id, data),
    onSuccess: () => { toast.success('Series updated'); qc.invalidateQueries({ queryKey: ['accounts', 'numbering-series'] }) },
    onError: (e) => toast.error(e.message),
  })

  if (isLoading) return <div className="flex justify-center py-10"><Loader2 className="animate-spin" style={{ color: 'var(--text-muted)' }} /></div>

  return (
    <div className="table-wrapper">
      <table className="table">
        <thead><tr><th>Voucher type</th><th>Prefix</th><th>Next #</th><th>Reset</th><th style={{ textAlign: 'right' }}></th></tr></thead>
        <tbody>
          {series.map(s => <NumberingRow key={s.id} s={s} onSave={(data) => update.mutate({ id: s.id, data })} saving={update.isPending} />)}
        </tbody>
      </table>
    </div>
  )
}

function NumberingRow({ s, onSave, saving }) {
  const [prefix, setPrefix] = useState(s.prefix || '')
  const [reset, setReset] = useState(s.reset_frequency)
  const dirty = prefix !== (s.prefix || '') || reset !== s.reset_frequency
  return (
    <tr>
      <td style={{ color: 'var(--text-h)', fontWeight: 600 }}>{s.voucher_type_code}</td>
      <td><Input value={prefix} onChange={e => setPrefix(e.target.value)} style={{ maxWidth: 120 }} /></td>
      <td style={{ color: 'var(--text-muted)' }}>{s.next_number}</td>
      <td>
        <Select value={reset} onChange={e => setReset(e.target.value)} style={{ maxWidth: 120 }}>
          <option value="yearly">Yearly</option>
          <option value="never">Never</option>
        </Select>
      </td>
      <td style={{ textAlign: 'right' }}>
        <GhostButton className="!py-1.5 !px-3" disabled={!dirty || saving} onClick={() => onSave({ prefix, reset_frequency: reset })}>Save</GhostButton>
      </td>
    </tr>
  )
}

function AuditTab() {
  const { data: page, isLoading } = useQuery({ queryKey: ['accounts', 'audit-logs'], queryFn: () => accountsApi.auditLogs({ per_page: 100 }) })
  const logs = page?.data ?? []
  if (isLoading) return <div className="flex justify-center py-10"><Loader2 className="animate-spin" style={{ color: 'var(--text-muted)' }} /></div>

  return (
    <div className="space-y-3">
      <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Append-only statutory trail — every create / cancel / reverse on the books. Cannot be edited or disabled.</p>
      <div className="table-wrapper">
        <table className="table">
          <thead><tr><th>When</th><th>Entity</th><th>Action</th><th>User</th></tr></thead>
          <tbody>
            {logs.length === 0 && <tr><td colSpan={4} style={{ color: 'var(--text-muted)' }}>No activity yet.</td></tr>}
            {logs.map(l => (
              <tr key={l.id}>
                <td style={{ color: 'var(--text-muted)' }}>{fmtDate(l.occurred_at)}</td>
                <td style={{ color: 'var(--text-h)' }}>{l.entity_type} #{l.entity_id}</td>
                <td className="capitalize" style={{ fontWeight: 700, color: '#a78bfa' }}>{l.action}</td>
                <td style={{ color: 'var(--text-muted)' }}>{l.user?.name || '—'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}
