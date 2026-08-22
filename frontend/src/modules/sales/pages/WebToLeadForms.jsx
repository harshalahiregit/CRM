import { useState, useEffect } from 'react'
import { Plus, Copy, Check } from 'lucide-react'
import { webToLeadApi } from '@/services/webToLeadApi'
import { leadSettingsApi } from '@/services/leadSettingsApi'
import { useToast } from '@/hooks/useToast'
import ListToolbar from '@/components/ui/ListToolbar'
import { useListView } from '@/hooks/useListView'
import ConfirmIconButton from '@/modules/customer/components/ConfirmIconButton'
import Drawer from '@/components/ui/Drawer'

const STD_FIELDS = [
  { key: 'name', label: 'Full Name' }, { key: 'email', label: 'Email' },
  { key: 'phone', label: 'Phone' }, { key: 'company', label: 'Company' },
  { key: 'title', label: 'Job Title' }, { key: 'website', label: 'Website' },
  { key: 'industry', label: 'Industry' }, { key: 'city', label: 'City' },
  { key: 'description', label: 'Message' },
]

export default function WebToLeadForms() {
  const toast = useToast()
  const [rows, setRows] = useState(null)
  const [sources, setSources] = useState([])
  const [statuses, setStatuses] = useState([])
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState(null)

  const load = () => webToLeadApi.list().then(setRows).catch(e => toast.error(e.message))
  useEffect(() => {
    load()
    leadSettingsApi.sources.list().then(setSources).catch(() => {})
    leadSettingsApi.statuses.list().then(setStatuses).catch(() => {})
  }, [])

  const openNew = () => {
    setForm({
      name: '', lead_source_id: '', lead_status_id: '', success_message: '',
      allow_duplicate: false, is_active: true,
      fields: { name: true, email: true, phone: true, company: false, title: false, website: false, industry: false, city: false, description: true },
      required: { name: true, email: true },
    })
    setOpen(true)
  }

  const save = async () => {
    if (!form.name.trim()) return toast.error('Form name required')
    const form_data = STD_FIELDS.filter(f => form.fields[f.key]).map(f => ({
      key: f.key, label: f.label, type: f.key === 'description' ? 'textarea' : 'text', required: !!form.required[f.key],
    }))
    try {
      await webToLeadApi.create({
        name: form.name, form_data,
        lead_source_id: form.lead_source_id || null, lead_status_id: form.lead_status_id || null,
        success_message: form.success_message || null, allow_duplicate: form.allow_duplicate, is_active: form.is_active,
      })
      toast.success('Form created'); setOpen(false); load()
    } catch (e) { toast.error(e.message) }
  }

  const del = async (id) => { try { await webToLeadApi.delete(id); toast.success('Deleted'); load() } catch (e) { toast.error(e.message) } }
  const copyLink = (key) => { navigator.clipboard.writeText(`${window.location.origin}/f/${key}`); toast.success('Public link copied') }

  // rows starts null while loading; the hook tolerates that and reports 0.
  const { search, setSearch, pageSize, setPageSize, visible, matched, pager } =
    useListView(rows, ['name', 'title'])

  return (
    <div className="p-4 md:p-6 max-w-[1200px] mx-auto">
      <div className="flex items-center justify-between mb-5 flex-wrap gap-3">
        <div>
          <p className="label-caps mb-1" style={{ color: '#a78bfa' }}>Sales &amp; Revenue</p>
          <h1 className="text-2xl font-black" style={{ color: 'var(--text-h)' }}>Web-to-Lead Forms</h1>
        </div>
        <button onClick={openNew} className="flex items-center gap-2 px-4 py-2.5 rounded-2xl text-sm font-bold text-white" style={{ background: 'linear-gradient(135deg,#9f67ff,#7C3AED)', boxShadow: '0 6px 20px rgba(124,58,237,0.4)' }}>
          <Plus size={16} /> New Form
        </button>
      </div>

      <ListToolbar
        search={search} onSearch={setSearch} searchPlaceholder="Search forms…"
        count={matched} total={(rows || []).length} unit="form"
        pageSize={pageSize} onPageSize={setPageSize} pager={pager} onRefresh={load} className="mb-3" />

      <div className="card-3d overflow-hidden" style={{ padding: 0 }}>
        <table className="w-full text-xs">
          <thead><tr style={{ background: 'rgba(124,58,237,0.04)', borderBottom: '1px solid var(--border)' }}>
            {['Form', 'Fields', 'Submissions', 'Active', 'Public Link', ''].map(h => <th key={h} className="py-3 px-4 text-left label-caps whitespace-nowrap">{h}</th>)}
          </tr></thead>
          <tbody>
            {rows === null ? (
              <tr><td colSpan={6} className="p-6 text-center" style={{ color: 'var(--text-muted)' }}>Loading…</td></tr>
            ) : rows.length === 0 ? (
              <tr><td colSpan={6} className="py-12 text-center" style={{ color: 'var(--text-muted)' }}>No forms yet. Create one to capture leads from your website.</td></tr>
            ) : visible.map(f => (
              <tr key={f.id} style={{ borderBottom: '1px solid var(--border)' }}>
                <td className="py-3 px-4 font-bold" style={{ color: 'var(--text-h)' }}>{f.name}</td>
                <td className="py-3 px-4" style={{ color: 'var(--text-muted)' }}>{(f.form_data || []).length} fields</td>
                <td className="py-3 px-4" style={{ color: 'var(--text-muted)' }}>{f.submissions_count}</td>
                <td className="py-3 px-4">{f.is_active ? <Check size={14} style={{ color: '#10b981' }} /> : '—'}</td>
                <td className="py-3 px-4">
                  <button onClick={() => copyLink(f.form_key)} className="flex items-center gap-1 text-xs font-bold" style={{ color: 'var(--accent)' }}><Copy size={12} /> Copy link</button>
                </td>
                <td className="py-3 px-4 text-right"><ConfirmIconButton onConfirm={() => del(f.id)} title="Delete form?" message="This form and its public link will stop working." /></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <Drawer open={open} onClose={() => setOpen(false)} title="New Web-to-Lead Form"
        footer={<div className="flex justify-end gap-2">
          <button onClick={() => setOpen(false)} className="px-4 py-2 rounded-xl text-sm font-semibold" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>Cancel</button>
          <button onClick={save} className="px-5 py-2 rounded-xl text-sm font-bold text-white" style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>Create Form</button>
        </div>}>
        {form && (
          <div className="space-y-4">
            <div><label className="label">Form Name *</label><input className="input-3d text-sm" value={form.name} onChange={e => setForm(p => ({ ...p, name: e.target.value }))} placeholder="e.g. Website Contact Form" /></div>
            <div className="grid grid-cols-2 gap-3">
              <div><label className="label">Default Source</label><select className="input-3d text-sm" value={form.lead_source_id} onChange={e => setForm(p => ({ ...p, lead_source_id: e.target.value }))}><option value="">—</option>{sources.map(s => <option key={s.id} value={s.id}>{s.name}</option>)}</select></div>
              <div><label className="label">Default Status</label><select className="input-3d text-sm" value={form.lead_status_id} onChange={e => setForm(p => ({ ...p, lead_status_id: e.target.value }))}><option value="">Default</option>{statuses.map(s => <option key={s.id} value={s.id}>{s.name}</option>)}</select></div>
            </div>
            <div>
              <label className="label">Fields to capture</label>
              <div className="space-y-1.5 mt-1">
                {STD_FIELDS.map(f => (
                  <div key={f.key} className="flex items-center gap-3 text-xs">
                    <label className="flex items-center gap-2 flex-1" style={{ color: 'var(--text-body)' }}>
                      <input type="checkbox" checked={!!form.fields[f.key]} onChange={e => setForm(p => ({ ...p, fields: { ...p.fields, [f.key]: e.target.checked } }))} />
                      {f.label}
                    </label>
                    {form.fields[f.key] && (
                      <label className="flex items-center gap-1" style={{ color: 'var(--text-muted)' }}>
                        <input type="checkbox" checked={!!form.required[f.key]} onChange={e => setForm(p => ({ ...p, required: { ...p.required, [f.key]: e.target.checked } }))} /> required
                      </label>
                    )}
                  </div>
                ))}
              </div>
            </div>
            <div><label className="label">Success Message</label><input className="input-3d text-sm" value={form.success_message} onChange={e => setForm(p => ({ ...p, success_message: e.target.value }))} placeholder="Thank you! We'll be in touch." /></div>
            <label className="flex items-center gap-2 text-xs font-semibold cursor-pointer" style={{ color: 'var(--text-muted)' }}>
              <input type="checkbox" checked={form.allow_duplicate} onChange={e => setForm(p => ({ ...p, allow_duplicate: e.target.checked }))} /> Allow duplicate email submissions
            </label>
          </div>
        )}
      </Drawer>
    </div>
  )
}
