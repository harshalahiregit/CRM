import { useState, useEffect } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import {
  Plus, Search, Send, FileText, Trash2, X, MoreVertical,
  ChevronDown, Tag, MessageSquare, User, MapPin,
  Eye, EyeOff, LayoutTemplate
} from 'lucide-react'
import { salesApi } from '@/services/salesApi'
import { leadApi } from '@/services/leadApi'
import { useClientOptions } from '@/hooks/useClientOptions'
import { useProjectOptions } from '@/hooks/useProjectOptions'
import StatusBadge from '../components/StatusBadge'
import LineItemsTable from '../components/LineItemsTable'
import RowMenu from '../components/RowMenu'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import { useToast } from '@/hooks/useToast'
import ListToolbar from '@/components/ui/ListToolbar'
import { useListView } from '@/hooks/useListView'
import { exportSalesList } from '@/services/salesApi'
import RichTextEditor from '@/components/ui/RichTextEditor'

const fmt = v => '₹' + Number(v || 0).toLocaleString('en-IN')
const fmtDate = d => d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'

const STATUSES = ['Open', 'Sent', 'Revised', 'Declined', 'Accepted', 'Expired']
const STAFF = ['Zafar Farooque', 'Priya Sharma', 'Rohit Verma', 'Anjali Singh', 'Karan Mehta']

const EMPTY_FORM = {
  subject: '', rel_type: 'customer', rel_id: '', project_id: '',
  date: new Date().toISOString().split('T')[0], open_till: '',
  currency: 'INR', discount_type: 'none', status: 'Open',
  assigned: '', proposal_to: '',
  address: '', city: '', state: '', country: 'India', zip: '',
  email: '', phone: '', allow_comments: false, tags: '', notes: '', terms: '',
  line_items: [], template_id: '',
}

export default function Proposals() {
  const navigate = useNavigate()
  const toast_ = useToast()
  const clientOptions = useClientOptions()
  const projectOptions = useProjectOptions()
  const [leadOptions, setLeadOptions] = useState([])
  const [searchParams] = useSearchParams()
  const [data, setData]       = useState([])
  const [templates, setTemplates] = useState([])
  const [loading, setLoading] = useState(true)
  const [filter, setFilter]   = useState('All')
  const [search, setSearch]   = useState('')
  const [showDrawer, setShowDrawer] = useState(false)
  const [openMenu, setOpenMenu] = useState(null)
  const [form, setForm]       = useState(EMPTY_FORM)
  const [showAddr, setShowAddr] = useState(false)
  const [confirmDelete, setConfirmDelete] = useState(null)

  // Routed through the shared Toast so every module notifies identically
  // (and error toasts get the per-field validation detail + tip).
  const toast = useToast()
  const showToast = (msg, type = 'success') =>
    type === 'error' ? toast.error(msg) : type === 'info' ? toast.info(msg) : toast.success(msg)

  const load = (f = filter, s = search) => {
    setLoading(true)
    salesApi.proposals.list({
      status: f !== 'All' ? f : undefined,
      search: s || undefined,
    }).then(d => { setData(d); setLoading(false) })
  }

  useEffect(() => { load() }, [filter, search])
  useEffect(() => { salesApi.proposalTemplates.list().then(setTemplates).catch(() => {}) }, [])
  useEffect(() => {
    leadApi.list().then(r => setLeadOptions((r?.data ?? r ?? []).map(l => ({ id: l.id, name: l.name || l.company })))).catch(() => {})
  }, [])

  // Preselect the customer + open the create drawer when arriving from the
  // customer profile (?client_id=…&new=1).
  useEffect(() => {
    if (searchParams.get('new') === '1') {
      const cid = searchParams.get('client_id') || ''
      navigate(`/app/sales/proposals/new${cid ? `?client_id=${cid}` : ''}`)
    }
  }, [searchParams])

  const applyTemplate = (templateId) => {
    sf('template_id', templateId)
    const t = templates.find(t => String(t.id) === String(templateId))
    if (t) sf('notes', t.content || '')
  }

  const handleCreate = async () => {
    if (!form.subject || !form.rel_id) return showToast('Subject & recipient required', 'error')
    await salesApi.proposals.create({ ...form, rel_id: Number(form.rel_id), project_id: form.project_id ? Number(form.project_id) : null })
    showToast('Proposal created!')
    setShowDrawer(false)
    setForm(EMPTY_FORM)
    load()
  }

  const handleSend = async (p) => {
    try {
      await salesApi.proposals.send(p.id)
      toast_.success('Proposal sent!')
      load()
    } catch (e) {
      toast_.error(e.message)
    }
  }

  const handleDelete = async () => {
    try {
      await salesApi.proposals.delete(confirmDelete.id)
      toast_.success('Proposal deleted')
      setConfirmDelete(null)
      load()
    } catch (e) {
      toast_.error(e.message)
    }
  }

  const sf = (k, v) => setForm(p => ({ ...p, [k]: v }))

  const stats = {
    total: data.length,
    open: data.filter(p => p.status === 'Open').length,
    sent: data.filter(p => p.status === 'Sent').length,
    accepted: data.filter(p => p.status === 'Accepted').length,
    totalVal: data.reduce((s, p) => s + Number(p.total || 0), 0),
  }

  // Paging only: this list's search is server-side, so the hook isn't given
  // search fields — it just caps the rows and reports the count.
  const { pageSize, setPageSize, visible, matched } = useListView(data, [])

  return (
    <>
      {/* Toast */}

      <div className="space-y-6 animate-[tiltIn_0.35s_ease]" onClick={() => setOpenMenu(null)}>

      {/* Header */}
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div>
          <p className="label-caps mb-1">Sales & Revenue</p>
          <h1 className="font-black" style={{ fontSize: 'clamp(1.3rem,2vw,1.8rem)', color: 'var(--text-h)', letterSpacing: '-0.02em' }}>
            <span className="text-gradient">Proposals</span>
          </h1>
          <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>Manage and track all your sales proposals</p>
        </div>
        <button
          onClick={e => { e.stopPropagation(); navigate('/app/sales/proposals/new') }}
          className="flex items-center gap-2 px-5 py-2.5 rounded-2xl text-sm font-bold text-white transition-all hover:scale-[1.03]"
          style={{ background: 'linear-gradient(135deg,#9f67ff,#7C3AED,#5b21b6)', boxShadow: '0 6px 20px rgba(124,58,237,0.45)' }}>
          <Plus size={15} /> New Proposal
        </button>
      </div>

      {/* KPIs */}
      <div className="grid grid-cols-2 lg:grid-cols-5 gap-3">
        {[
          { l: 'Total', v: stats.total, c: '#7C3AED' },
          { l: 'Open', v: stats.open, c: '#3b82f6' },
          { l: 'Sent', v: stats.sent, c: '#a78bfa' },
          { l: 'Accepted', v: stats.accepted, c: '#10b981' },
          { l: 'Pipeline Value', v: fmt(stats.totalVal), c: '#7C3AED' },
        ].map(k => (
          <div key={k.l} className="kpi-3d py-4 px-5">
            <p className="text-2xl font-black" style={{ color: k.c }}>{k.v}</p>
            <p className="text-xs font-semibold mt-1" style={{ color: 'var(--text-muted)' }}>{k.l}</p>
          </div>
        ))}
      </div>

      {/* Toolbar: search · status tabs · count · rows-per-page · refresh · export */}
      <ListToolbar
        search={search} onSearch={setSearch} searchPlaceholder="Search proposals or clients…"
        count={matched} total={data.length} unit="record"
        pageSize={pageSize} onPageSize={setPageSize} onRefresh={() => load()}
        onExport={() => exportSalesList('proposals', { status: filter !== 'All' ? filter : undefined, search: search || undefined })
          .catch(e => toast.error(e.message))}
      >
        <div className="flex gap-1.5 flex-wrap p-1 rounded-2xl" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
          {['All', ...STATUSES].map(f => (
            <button key={f} onClick={() => setFilter(f)}
              className="px-3 py-1.5 rounded-xl text-xs font-bold transition-all"
              style={{
                background: filter === f ? 'linear-gradient(135deg,#7C3AED,#5b21b6)' : 'transparent',
                color: filter === f ? '#fff' : 'var(--text-muted)',
                boxShadow: filter === f ? '0 3px 10px rgba(124,58,237,0.3)' : 'none',
              }}>
              {f}
            </button>
          ))}
        </div>
      </ListToolbar>

      {/* Table */}
      {loading ? (
        <div className="space-y-2">
          {[1,2,3,4].map(i => <div key={i} className="skeleton h-14 rounded-xl" style={{ background: 'var(--border)' }} />)}
        </div>
      ) : (
        <div className="card-3d overflow-hidden" style={{ padding: 0, borderRadius: '20px' }}>
          <div className="overflow-x-auto">
            <table className="w-full text-xs">
              <thead>
                <tr style={{ background: 'rgba(124,58,237,0.04)', borderBottom: '1px solid var(--border)' }}>
                  {['#', 'Subject', 'Client', 'Type', 'Amount', 'Tracking', 'Expires', 'Assigned', 'Status', ''].map(h => (
                    <th key={h} className="py-3.5 px-4 text-left label-caps whitespace-nowrap">{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {visible.map((p) => (
                  <tr key={p.id}
                    className="cursor-pointer transition-colors"
                    style={{ borderBottom: '1px solid var(--border)' }}
                    onClick={() => navigate(`/app/sales/proposals/${p.id}`)}
                    onMouseEnter={e => e.currentTarget.style.background = 'rgba(124,58,237,0.04)'}
                    onMouseLeave={e => e.currentTarget.style.background = 'transparent'}>
                    <td className="py-3.5 px-4 font-bold" style={{ color: '#a78bfa' }}>
                      PRO-{String(p.id).padStart(3,'0')}
                    </td>
                    <td className="py-3.5 px-4 font-semibold max-w-[200px]" style={{ color: 'var(--text-h)' }}>
                      <span className="truncate block">{p.subject}</span>
                    </td>
                    <td className="py-3.5 px-4 whitespace-nowrap" style={{ color: 'var(--text-muted)' }}>{p.client}</td>
                    <td className="py-3.5 px-4">
                      <span className="px-2 py-0.5 rounded-lg text-[10px] font-bold" style={{ background: 'rgba(59,130,246,0.1)', color: '#3b82f6' }}>
                        {p.rel_type || 'Customer'}
                      </span>
                    </td>
                    <td className="py-3.5 px-4 font-bold whitespace-nowrap" style={{ color: 'var(--text-h)' }}>{fmt(p.total)}</td>
                    <td className="py-3.5 px-4 whitespace-nowrap">
                      {p.email_opened_count > 0 ? (
                        <span className="inline-flex items-center gap-1 text-[10px] font-bold" style={{ color: '#10b981' }}>
                          <Eye size={11} /> Seen · {fmtDate(p.email_opened_at)}
                        </span>
                      ) : (
                        <span className="inline-flex items-center gap-1 text-[10px]" style={{ color: 'var(--text-muted)' }}>
                          <EyeOff size={11} /> Not opened
                        </span>
                      )}
                    </td>
                    <td className="py-3.5 px-4 whitespace-nowrap" style={{ color: 'var(--text-muted)' }}>{fmtDate(p.open_till)}</td>
                    <td className="py-3.5 px-4" style={{ color: 'var(--text-muted)' }}>{p.assigned || '—'}</td>
                    <td className="py-3.5 px-4"><StatusBadge status={p.status} /></td>
                    <td className="py-3.5 px-4" onClick={e => e.stopPropagation()}>
                      <RowMenu width={188}>
                        {[
                          { icon: Send, label: 'Send to Client', action: () => handleSend(p) },
                          { icon: Trash2, label: 'Delete', action: () => setConfirmDelete(p), danger: true },
                        ].map(a => (
                          <button key={a.label}
                            onClick={() => a.action()}
                            className="w-full flex items-center gap-2.5 px-3 py-2 rounded-xl text-xs font-medium transition-colors"
                            onMouseEnter={e => e.currentTarget.style.background = a.danger ? 'rgba(239,68,68,0.06)' : 'rgba(124,58,237,0.06)'}
                            onMouseLeave={e => e.currentTarget.style.background = 'transparent'}
                            style={{ color: a.danger ? '#f87171' : 'var(--text-h)' }}>
                            <a.icon size={13} /> {a.label}
                          </button>
                        ))}
                      </RowMenu>
                    </td>
                  </tr>
                ))}
                {visible.length === 0 && (
                  <tr><td colSpan="10" className="py-16 text-center">
                    <div className="flex flex-col items-center gap-3">
                      <div className="w-14 h-14 rounded-2xl flex items-center justify-center text-2xl"
                        style={{ background: 'rgba(124,58,237,0.08)', border: '1px solid rgba(124,58,237,0.15)' }}>📋</div>
                      <p className="text-sm font-semibold" style={{ color: 'var(--text-muted)' }}>No proposals found</p>
                      <button onClick={() => navigate('/app/sales/proposals/new')} className="text-xs font-bold" style={{ color: '#a78bfa' }}>+ Create your first proposal</button>
                    </div>
                  </td></tr>
                )}
              </tbody>
            </table>
          </div>
        </div>
      )}

      </div>

      {/* ── Side Drawer ────────────────────────────────────────── */}
      {showDrawer && (
        <>
          <div className="drawer-backdrop" />
          <div className="drawer-panel" style={{ width: 'min(1080px, 96vw)' }}>
            {/* Drawer Header */}
            <div className="drawer-header">
              <div>
                <div className="flex items-center gap-2.5">
                  <div className="w-8 h-8 rounded-xl flex items-center justify-center"
                    style={{ background: 'linear-gradient(135deg,#9f67ff,#7C3AED)', boxShadow: '0 4px 12px rgba(124,58,237,0.4)' }}>
                    <FileText size={14} className="text-white" />
                  </div>
                  <h2 className="font-black text-lg" style={{ color: 'var(--text-h)', letterSpacing: '-0.02em' }}>New Proposal</h2>
                </div>
                <p className="text-xs mt-1 ml-[42px]" style={{ color: 'var(--text-muted)' }}>Fill in the details to create a new proposal</p>
              </div>
              <button onClick={() => setShowDrawer(false)}
                className="w-9 h-9 rounded-xl flex items-center justify-center transition-colors hover:bg-[rgba(239,68,68,0.08)]"
                style={{ border: '1px solid var(--border)' }}>
                <X size={16} style={{ color: 'var(--text-muted)' }} />
              </button>
            </div>

            {/* Drawer Body — scrollable */}
            <div className="drawer-body">

              {/* Section: Template */}
              {templates.length > 0 && (
                <div>
                  <p className="label-caps mb-4" style={{ color: '#a78bfa' }}><LayoutTemplate size={11} className="inline mr-1" />Start from a Template (optional)</p>
                  <div className="grid grid-cols-2 gap-2">
                    {templates.map(t => (
                      <button key={t.id} onClick={() => applyTemplate(t.id)}
                        className="p-3 rounded-xl text-left text-xs font-semibold transition-all"
                        style={{
                          background: String(form.template_id) === String(t.id) ? 'rgba(124,58,237,0.1)' : 'var(--bg-input)',
                          border: `1px solid ${String(form.template_id) === String(t.id) ? 'rgba(124,58,237,0.4)' : 'var(--border)'}`,
                          color: String(form.template_id) === String(t.id) ? '#a78bfa' : 'var(--text-h)',
                        }}>
                        {t.name}
                      </button>
                    ))}
                  </div>
                </div>
              )}

              {/* Section: Basic Info */}
              <div>
                <p className="label-caps mb-4" style={{ color: '#a78bfa' }}>Basic Information</p>
                <div className="space-y-4">
                  <div>
                    <label className="label">Subject *</label>
                    <input className="input-3d text-sm" placeholder="e.g. Enterprise Web Portal Development" value={form.subject} onChange={e => sf('subject', e.target.value)} />
                  </div>
                  <div className="grid grid-cols-2 gap-4">
                    <div>
                      <label className="label">For (Relation Type)</label>
                      <select className="input-3d text-sm" value={form.rel_type} onChange={e => { sf('rel_type', e.target.value); sf('rel_id', '') }}>
                        <option value="customer">Customer</option>
                        <option value="lead">Lead</option>
                      </select>
                    </div>
                    <div>
                      <label className="label">{form.rel_type === 'lead' ? 'Lead' : 'Customer'} Name *</label>
                      <select className="input-3d text-sm" value={form.rel_id} onChange={e => sf('rel_id', e.target.value)}>
                        <option value="">Select {form.rel_type}…</option>
                        {(form.rel_type === 'lead' ? leadOptions : clientOptions).map(o => <option key={o.id} value={o.id}>{o.name || o.company}</option>)}
                      </select>
                    </div>
                  </div>
                  <div className="grid grid-cols-2 gap-4">
                    <div>
                      <label className="label">Proposal To (Recipient Name)</label>
                      <input className="input-3d text-sm" placeholder="Contact person name" value={form.proposal_to} onChange={e => sf('proposal_to', e.target.value)} />
                    </div>
                    <div>
                      <label className="label">Link to Project</label>
                      <select className="input-3d text-sm" value={form.project_id} onChange={e => sf('project_id', e.target.value)}>
                        <option value="">No project</option>
                        {projectOptions.map(p => <option key={p.id} value={p.id}>{p.name}</option>)}
                      </select>
                    </div>
                  </div>
                  <div className="grid grid-cols-2 gap-4">
                    <div>
                      <label className="label">Proposal Date</label>
                      <input type="date" className="input-3d text-sm" value={form.date} onChange={e => sf('date', e.target.value)} />
                    </div>
                    <div>
                      <label className="label">Open Until (Expiry)</label>
                      <input type="date" className="input-3d text-sm" value={form.open_till} onChange={e => sf('open_till', e.target.value)} />
                    </div>
                  </div>
                </div>
              </div>

              {/* Section: Settings */}
              <div>
                <p className="label-caps mb-4" style={{ color: '#a78bfa' }}>Settings</p>
                <div className="space-y-4">
                  <div className="grid grid-cols-2 gap-4">
                    <div>
                      <label className="label">Status</label>
                      <select className="input-3d text-sm" value={form.status} onChange={e => sf('status', e.target.value)}>
                        {STATUSES.map(s => <option key={s} value={s}>{s}</option>)}
                      </select>
                    </div>
                    <div>
                      <label className="label">Discount Type</label>
                      <select className="input-3d text-sm" value={form.discount_type} onChange={e => sf('discount_type', e.target.value)}>
                        <option value="none">No Discount</option>
                        <option value="before_tax">Before Tax</option>
                        <option value="after_tax">After Tax</option>
                      </select>
                    </div>
                  </div>
                  <div className="grid grid-cols-2 gap-4">
                    <div>
                      <label className="label">Assigned Staff</label>
                      <select className="input-3d text-sm" value={form.assigned} onChange={e => sf('assigned', e.target.value)}>
                        <option value="">Unassigned</option>
                        {STAFF.map(s => <option key={s} value={s}>{s}</option>)}
                      </select>
                    </div>
                    <div>
                      <label className="label">Currency</label>
                      <select className="input-3d text-sm" value={form.currency} onChange={e => sf('currency', e.target.value)}>
                        {['INR', 'USD', 'EUR', 'GBP', 'AED'].map(c => <option key={c} value={c}>{c}</option>)}
                      </select>
                    </div>
                  </div>

                  {/* Allow Comments Toggle */}
                  <div className="flex items-center justify-between p-4 rounded-2xl"
                    style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
                    <div className="flex items-center gap-2.5">
                      <div className="w-8 h-8 rounded-xl flex items-center justify-center" style={{ background: 'rgba(124,58,237,0.1)' }}>
                        <MessageSquare size={14} style={{ color: '#a78bfa' }} />
                      </div>
                      <div>
                        <p className="text-sm font-semibold" style={{ color: 'var(--text-h)' }}>Allow Client Comments</p>
                        <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Clients can comment on this proposal</p>
                      </div>
                    </div>
                    <button
                      onClick={() => sf('allow_comments', !form.allow_comments)}
                      className="relative w-12 h-6 rounded-full transition-all duration-300 flex-shrink-0"
                      style={{ background: form.allow_comments ? 'linear-gradient(135deg,#7C3AED,#5b21b6)' : 'rgba(255,255,255,0.1)' }}>
                      <span className="absolute top-0.5 h-5 w-5 bg-white rounded-full shadow transition-all duration-300"
                        style={{ left: form.allow_comments ? '26px' : '2px' }} />
                    </button>
                  </div>
                </div>
              </div>

              {/* Section: Contact */}
              <div>
                <p className="label-caps mb-4" style={{ color: '#a78bfa' }}>Contact Details</p>
                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <label className="label">Email</label>
                    <input type="email" className="input-3d text-sm" placeholder="client@company.com" value={form.email} onChange={e => sf('email', e.target.value)} />
                  </div>
                  <div>
                    <label className="label">Phone</label>
                    <input className="input-3d text-sm" placeholder="+91 98765 43210" value={form.phone} onChange={e => sf('phone', e.target.value)} />
                  </div>
                </div>
              </div>

              {/* Section: Address (collapsible) */}
              <div>
                <button
                  onClick={() => setShowAddr(a => !a)}
                  className="flex items-center gap-2 w-full text-left"
                  style={{ color: '#a78bfa' }}>
                  <MapPin size={13} />
                  <span className="label-caps" style={{ color: '#a78bfa' }}>Billing Address</span>
                  <ChevronDown size={13} className={`ml-auto transition-transform ${showAddr ? 'rotate-180' : ''}`} />
                </button>
                {showAddr && (
                  <div className="mt-4 space-y-4">
                    <div>
                      <label className="label">Address</label>
                      <input className="input-3d text-sm" placeholder="Street address" value={form.address} onChange={e => sf('address', e.target.value)} />
                    </div>
                    <div className="grid grid-cols-2 gap-4">
                      <div><label className="label">City</label><input className="input-3d text-sm" placeholder="Mumbai" value={form.city} onChange={e => sf('city', e.target.value)} /></div>
                      <div><label className="label">State</label><input className="input-3d text-sm" placeholder="Maharashtra" value={form.state} onChange={e => sf('state', e.target.value)} /></div>
                    </div>
                    <div className="grid grid-cols-2 gap-4">
                      <div><label className="label">Country</label><input className="input-3d text-sm" placeholder="India" value={form.country} onChange={e => sf('country', e.target.value)} /></div>
                      <div><label className="label">ZIP / PIN</label><input className="input-3d text-sm" placeholder="400001" value={form.zip} onChange={e => sf('zip', e.target.value)} /></div>
                    </div>
                  </div>
                )}
              </div>

              {/* Section: Line Items */}
              <div>
                <p className="label-caps mb-4" style={{ color: '#a78bfa' }}>Line Items</p>
                <LineItemsTable
                  items={form.line_items}
                  onChange={rows => sf('line_items', rows)}
                />
              </div>

              {/* Section: Tags & Notes */}
              <div>
                <p className="label-caps mb-4" style={{ color: '#a78bfa' }}>Tags & Notes</p>
                <div className="space-y-4">
                  <div>
                    <label className="label"><Tag size={10} className="inline mr-1" />Tags (comma separated)</label>
                    <input className="input-3d text-sm" placeholder="e.g. enterprise, q3-2026, priority" value={form.tags} onChange={e => sf('tags', e.target.value)} />
                  </div>
                  <div>
                    <label className="label">Notes</label>
                    <RichTextEditor value={form.notes} onChange={v => sf('notes', v)} placeholder="Additional notes visible on the proposal…" minHeight={110} />
                    <label className="label mt-3">Terms &amp; Conditions</label>
                    <RichTextEditor value={form.terms} onChange={v => sf('terms', v)} placeholder="Payment terms, validity, conditions…" minHeight={120} />
                  </div>
                </div>
              </div>

            </div>

            {/* Drawer Footer */}
            <div className="drawer-footer">
              <button onClick={() => setShowDrawer(false)}
                className="flex-1 py-3 rounded-2xl text-sm font-semibold transition-all hover:scale-[1.01]"
                style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>
                Cancel
              </button>
              <button onClick={handleCreate}
                className="flex-[2] py-3 rounded-2xl text-sm font-bold text-white transition-all hover:scale-[1.01]"
                style={{ background: 'linear-gradient(135deg,#9f67ff,#7C3AED,#5b21b6)', boxShadow: '0 6px 20px rgba(124,58,237,0.4)' }}>
                Create Proposal
              </button>
            </div>
          </div>
        </>
      )}

      {confirmDelete && (
        <ConfirmDialog
          title="Delete this proposal?"
          message={`This will permanently delete PRO-${String(confirmDelete.id).padStart(3, '0')}. This cannot be undone.`}
          confirmLabel="Delete"
          tone="danger"
          onConfirm={handleDelete}
          onCancel={() => setConfirmDelete(null)}
        />
      )}
    </>
  )
}
