import { useState, useEffect } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import {
  Plus, Send, Receipt, Trash2, X, MoreVertical,
  LayoutGrid, List, FileText, User, Tag, MapPin, ChevronDown
} from 'lucide-react'
import { salesApi } from '@/services/salesApi'
import { exportSalesList } from '@/services/salesApi'
import { customerApi } from '@/services/customerApi'
import { useClientOptions } from '@/hooks/useClientOptions'
import { useProjectOptions } from '@/hooks/useProjectOptions'
import StatusBadge from '../components/StatusBadge'
import RowMenu from '../components/RowMenu'
import LineItemsTable from '../components/LineItemsTable'
import ListToolbar from '@/components/ui/ListToolbar'
import { useListView } from '@/hooks/useListView'
import SaveAsTemplateButton from '../components/SaveAsTemplateButton'
import { salesDocumentTemplateApi } from '@/services/salesDocumentTemplateApi'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import RichTextEditor from '@/components/ui/RichTextEditor'
import { useToast } from '@/hooks/useToast'

const fmt = v => '₹' + Number(v || 0).toLocaleString('en-IN')
const fmtDate = d => d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'

const STATUSES = ['Draft', 'Pending Review', 'Sent', 'Viewed', 'Under Negotiation', 'Revision Requested', 'Accepted', 'Declined', 'Expired']

/**
 * Has this estimate lapsed?
 *
 * Nothing in the app ever writes the 'Expired' status — the old CRM did it from a
 * nightly cron, and without one the Expired tab could never match a row and an
 * Expired count would sit permanently at zero. So it is derived on read instead,
 * using the old CRM's exact rule: only a SENT estimate expires (a draft was never
 * put out, and an accepted or declined one already has its answer), and only once
 * the valid-until date is in the past.
 *
 * A stored 'Expired' still counts, so if a scheduled job is added later this keeps
 * working with no change here.
 */
const isExpired = (e) => {
  if (e.status === 'Expired') return true
  if (e.status !== 'Sent' || !e.valid_until) return false

  // Compared as plain YYYY-MM-DD strings (which sort chronologically), NOT as
  // Date objects. The API serialises valid_until as UTC midnight, so
  // `new Date(...)` lands on the previous day for anyone west of UTC and would
  // expire their estimates a day early. Slicing the date part sidesteps the
  // timezone entirely, and keeps "valid until today" valid.
  const d = new Date()
  const today = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`

  return String(e.valid_until).slice(0, 10) < today
}

const effectiveStatus = (e) => (isExpired(e) ? 'Expired' : e.status)
const STAFF = ['Zafar Farooque', 'Priya Sharma', 'Rohit Verma', 'Anjali Singh', 'Karan Mehta']

const EMPTY_FORM = {
  subject: '', client_id: '', project_id: '',
  date: new Date().toISOString().split('T')[0],
  valid_until: '', currency: 'INR',
  discount_type: 'before_tax', discount_mode: 'fixed', discount_value: 0,
  sale_agent: '', status: 'Draft',
  adminnote: '', clientnote: '', terms: '', tags: '',
  address: '', city: '', state: '', country: 'India', zip: '',
  line_items: [],
}

const PIPE_COLS = [
  { key: 'Draft',    color: '#94a3b8', bg: 'rgba(100,116,139,0.08)' },
  { key: 'Sent',     color: '#a78bfa', bg: 'rgba(124,58,237,0.08)' },
  { key: 'Accepted', color: '#10b981', bg: 'rgba(16,185,129,0.08)' },
  { key: 'Declined', color: '#f87171', bg: 'rgba(239,68,68,0.08)'  },
  { key: 'Expired',  color: '#fbbf24', bg: 'rgba(245,158,11,0.08)' },
]

export default function Estimates({ docType = 'proforma' }) {
  const isEstimate = docType === 'estimate'
  const DOC_LABEL = isEstimate ? 'Estimate' : 'Proforma Invoice'
  const DOC_LABEL_PLURAL = isEstimate ? 'Estimates' : 'Proforma Invoices'

  // Estimates start on the chooser page (blank vs a saved template), matching
  // proposals. Proforma invoices share this component but have no chooser route,
  // so they keep opening the form directly.
  const startNew = () => isEstimate ? navigate('/app/sales/estimates/new') : setShowDrawer(true)
  const navigate = useNavigate()
  const clientOptions = useClientOptions()
  const projectOptions = useProjectOptions()
  const [data, setData]         = useState([])
  const [loading, setLoading]   = useState(true)
  const [filter, setFilter]     = useState('All')
  const [viewMode, setViewMode] = useState('table')   // table | pipeline
  const [showDrawer, setShowDrawer] = useState(false)
  const [showAddr, setShowAddr]  = useState(false)
  const [openMenu, setOpenMenu] = useState(null)
  const [form, setForm]         = useState(EMPTY_FORM)
  const [confirmDelete, setConfirmDelete] = useState(null)

  // Routed through the shared Toast so every module notifies identically
  // (and error toasts get the per-field validation detail + tip).
  const toast = useToast()
  const showToast = (msg, type = 'success') =>
    type === 'error' ? toast.error(msg) : type === 'info' ? toast.info(msg) : toast.success(msg)

  const load = () => {
    setLoading(true)
    // Unfiltered on purpose: the KPI boxes must count every estimate of this type,
    // and "Expired" is derived client-side (see isExpired) so the server can't
    // filter on it. The endpoint isn't paginated, so this is one request either way
    // and switching tabs no longer refetches.
    salesApi.estimates.list({ type: docType }).then(d => { setData(d); setLoading(false) })
  }
  useEffect(() => { load() }, [docType])

  const handleClientChange = async (cid) => {
    sf('client_id', cid)
    if (!cid) return
    try {
      const cust = await customerApi.get(cid)
      if (cust) {
        setForm(p => ({
          ...p,
          client_id: cid,
          address: cust.address || cust.billing_street || p.address || '',
          city: cust.city || cust.billing_city || p.city || '',
          state: cust.state || cust.billing_state || p.state || '',
          country: cust.country || cust.billing_country || p.country || 'India',
          zip: cust.zip || cust.billing_zip || p.zip || '',
        }))
        setShowAddr(true)
      }
    } catch {
      // ignore fetch error
    }
  }

  // Arriving from a customer profile's "New Proforma Invoice" button.
  const [searchParams, setSearchParams] = useSearchParams()
  useEffect(() => {
    if (searchParams.get('new') === '1') {
      const cid = searchParams.get('client_id') || ''
      const tpl = searchParams.get('template')
      if (tpl) {
        salesDocumentTemplateApi.get(tpl)
          .then(t => { applyTemplate(t); showToast(`Started from "${t.name}"`) })
          .catch(e => showToast(e.message, 'error'))
      }
      if (cid) {
        handleClientChange(cid)
      } else {
        setForm(p => ({ ...p, client_id: '' }))
      }
      setShowDrawer(true)
      setSearchParams({}, { replace: true })
    }
  }, [])

  const sf = (k, v) => setForm(p => ({ ...p, [k]: v }))


  /**
   * Turn a chosen template into form values.
   *
   * Applying happens here rather than inside the form: the template is chosen on
   * the "New" page before this drawer opens. A template only overwrites a default
   * it actually carries, so choosing one never blanks something already set
   * (e.g. the client_id carried in from a customer profile).
   */
  const applyTemplate = (t) => setForm(p => ({
    ...p,
    line_items: (t.line_items || []).map(i => ({
      item_id: i.item_id ?? null,
      item_name: i.item_name,
      description: i.description ?? '',
      hsn_sac_code: i.hsn_sac_code ?? '',
      qty: Number(i.qty) || 1,
      unit: i.unit || 'pcs',
      rate: Number(i.rate) || 0,
      tax: Number(i.tax) || 0,
      taxes: i.taxes ?? null,
      discount: Number(i.discount) || 0,
      discount_mode: i.discount_mode || 'fixed',
    })),
    ...(t.terms ? { terms: t.terms } : {}),
    ...(t.adminnote ? { adminnote: t.adminnote } : {}),
    ...(t.clientnote ? { clientnote: t.clientnote } : {}),
    ...(t.currency ? { currency: t.currency } : {}),
    ...(t.discount_type ? { discount_type: t.discount_type } : {}),
    ...(t.discount_mode ? { discount_mode: t.discount_mode } : {}),
    ...(Number(t.discount_value) ? { discount_value: Number(t.discount_value) } : {}),
  }))

  const handleCreate = async () => {
    if (!form.subject || !form.client_id) return showToast('Subject & customer required', 'error')
    await salesApi.estimates.create({ estimate_type: docType, ...form, client_id: Number(form.client_id), project_id: form.project_id ? Number(form.project_id) : null })
    showToast(`${DOC_LABEL} created!`)
    setShowDrawer(false)
    setForm(EMPTY_FORM)
    load()
  }

  const countBy = (status) => data.filter(e => effectiveStatus(e) === status).length

  const stats = {
    total: data.length,
    draft: countBy('Draft'),
    sent: countBy('Sent'),
    accepted: countBy('Accepted'),
    declined: countBy('Declined'),
    expired: countBy('Expired'),
    totalVal: data.reduce((s, e) => s + Number(e.total || 0), 0),
  }

  // Status tabs and the table both read the derived status, so a Sent-but-lapsed
  // estimate is counted, filtered and badged as Expired consistently.
  const byStatus = filter === 'All' ? data : data.filter(e => effectiveStatus(e) === filter)

  // Search + rows-per-page on top of the status filter. The KPI boxes above stay
  // on the FULL set (`data`) — they're a summary of the workspace, not of the
  // current search.
  const { search, setSearch, pageSize, setPageSize, visible, matched } =
    useListView(byStatus, ['reference', 'subject', 'client', 'status'])

  const handleConvertToProforma = async (estimate) => {
    try {
      const pf = await salesApi.estimates.convertToProforma(estimate.id)
      showToast(`Converted to ${pf.reference}`)
      load()
    } catch (e) {
      showToast(e.message || 'Failed to convert', 'error')
    }
  }

  const handleConvert = async (estimate) => {
    try {
      const { invoice_number } = await salesApi.estimates.convertToInvoice(estimate.id)
      showToast(`Converted to Tax Invoice ${invoice_number}!`)
      load()
    } catch (e) {
      showToast(e.message || 'Failed to convert', 'error')
    }
  }

  const handleDelete = async () => {
    try {
      await salesApi.estimates.delete(confirmDelete.id)
      showToast(`${DOC_LABEL} deleted`)
      setConfirmDelete(null)
      load()
    } catch (e) {
      showToast(e.message || 'Failed to delete', 'error')
    }
  }

  return (
    <>

      <div className="space-y-6 animate-[tiltIn_0.35s_ease]" onClick={() => setOpenMenu(null)}>

      {/* Header */}
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div>
          <p className="label-caps mb-1">Sales & Revenue</p>
          <h1 className="font-black" style={{ fontSize: 'clamp(1.3rem,2vw,1.8rem)', color: 'var(--text-h)', letterSpacing: '-0.02em' }}>
            <span className="text-gradient">{DOC_LABEL_PLURAL}</span>
          </h1>
          <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>Price quotes sent to customers</p>
        </div>
        <div className="flex items-center gap-2">
          {/* View Toggle */}
          <div className="flex rounded-2xl overflow-hidden p-1" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
            {[{ k: 'table', Icon: List }, { k: 'pipeline', Icon: LayoutGrid }].map(({ k, Icon }) => (
              <button key={k} onClick={() => setViewMode(k)}
                className="px-3 py-2 rounded-xl flex items-center gap-1.5 text-xs font-bold transition-all"
                style={{
                  background: viewMode === k ? 'linear-gradient(135deg,#7C3AED,#5b21b6)' : 'transparent',
                  color: viewMode === k ? '#fff' : 'var(--text-muted)',
                  boxShadow: viewMode === k ? '0 3px 10px rgba(124,58,237,0.3)' : 'none',
                }}>
                <Icon size={13} />
                <span className="hidden sm:inline">{k === 'table' ? 'Table' : 'Pipeline'}</span>
              </button>
            ))}
          </div>
          <button onClick={() => startNew()}
            className="flex items-center gap-2 px-5 py-2.5 rounded-2xl text-sm font-bold text-white transition-all hover:scale-[1.03]"
            style={{ background: 'linear-gradient(135deg,#9f67ff,#7C3AED,#5b21b6)', boxShadow: '0 6px 20px rgba(124,58,237,0.45)' }}>
            <Plus size={15} /> New {DOC_LABEL}
          </button>
        </div>
      </div>

      {/* KPIs */}
      <div className="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-7 gap-3">
        {[
          { l: 'Total', v: stats.total, c: '#7C3AED' },
          { l: 'Draft', v: stats.draft, c: '#94a3b8' },
          { l: 'Sent', v: stats.sent, c: '#a78bfa' },
          { l: 'Accepted', v: stats.accepted, c: '#10b981' },
          { l: 'Declined', v: stats.declined, c: '#ef4444' },
          { l: 'Expired', v: stats.expired, c: '#f59e0b' },
          { l: 'Total Value', v: fmt(stats.totalVal), c: '#7C3AED' },
        ].map(k => (
          <div key={k.l} className="kpi-3d py-4 px-5">
            <p className="text-2xl font-black" style={{ color: k.c }}>{k.v}</p>
            <p className="text-xs font-semibold mt-1" style={{ color: 'var(--text-muted)' }}>{k.l}</p>
          </div>
        ))}
      </div>

      {/* Toolbar: search · status tabs · count · rows-per-page · refresh · export */}
      {viewMode === 'table' && (
        <ListToolbar
          search={search} onSearch={setSearch}
          searchPlaceholder={`Search ${DOC_LABEL.toLowerCase()}s…`}
          count={matched} total={data.length} unit="record"
          pageSize={pageSize} onPageSize={setPageSize}
          onRefresh={load}
          onExport={() => exportSalesList('estimates', { type: docType, status: filter !== 'All' ? filter : undefined, search: search || undefined })
            .catch(e => showToast(e.message, 'error'))}
        >
          <div className="flex gap-1.5 p-1 rounded-2xl" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
            {['All', ...STATUSES].map(f => (
              <button key={f} onClick={() => setFilter(f)}
                className="px-3 py-1.5 rounded-xl text-xs font-bold transition-all"
                style={{
                  background: filter === f ? 'linear-gradient(135deg,#7C3AED,#5b21b6)' : 'transparent',
                  color: filter === f ? '#fff' : 'var(--text-muted)',
                }}>
                {f}
              </button>
            ))}
          </div>
        </ListToolbar>
      )}

      {/* ── Table View ── */}
      {viewMode === 'table' && (
        loading ? (
          <div className="space-y-2">{[1,2,3].map(i => <div key={i} className="skeleton h-14 rounded-xl" style={{ background: 'var(--border)' }} />)}</div>
        ) : (
          <div className="card-3d overflow-hidden" style={{ padding: 0 }}>
            <div className="overflow-x-auto">
              <table className="w-full text-xs">
                <thead>
                  <tr style={{ background: 'rgba(124,58,237,0.04)', borderBottom: '1px solid var(--border)' }}>
                    {['Ref', 'Subject', 'Client', 'Sale Agent', 'Amount', 'Valid Until', 'Status', ''].map(h => (
                      <th key={h} className="py-3.5 px-4 text-left label-caps whitespace-nowrap">{h}</th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {visible.map(e => (
                    <tr key={e.id} className="cursor-pointer transition-colors" style={{ borderBottom: '1px solid var(--border)' }}
                      onClick={() => navigate(`/app/sales/estimates/${e.id}`)}
                      onMouseEnter={ev => ev.currentTarget.style.background = 'rgba(124,58,237,0.04)'}
                      onMouseLeave={ev => ev.currentTarget.style.background = 'transparent'}>
                      <td className="py-3.5 px-4 font-bold whitespace-nowrap" style={{ color: '#a78bfa' }}>
                        {e.reference}
                        {e.payment_received && <span className="ml-1.5 px-1.5 py-0.5 rounded text-[9px] font-bold" style={{ background: 'rgba(16,185,129,0.15)', color: '#10b981' }}>PAID</span>}
                      </td>
                      <td className="py-3.5 px-4 font-semibold max-w-[200px]" style={{ color: 'var(--text-h)' }}>
                        <span className="truncate block">{e.subject}</span>
                      </td>
                      <td className="py-3.5 px-4 whitespace-nowrap" style={{ color: 'var(--text-muted)' }}>{e.client}</td>
                      <td className="py-3.5 px-4 whitespace-nowrap" style={{ color: 'var(--text-muted)' }}>{e.sale_agent || '—'}</td>
                      <td className="py-3.5 px-4 font-bold whitespace-nowrap" style={{ color: 'var(--text-h)' }}>{fmt(e.total)}</td>
                      <td className="py-3.5 px-4 whitespace-nowrap" style={{ color: 'var(--text-muted)' }}>{fmtDate(e.valid_until)}</td>
                      <td className="py-3.5 px-4"><StatusBadge status={effectiveStatus(e)} /></td>
                      <td className="py-3.5 px-4" onClick={ev => ev.stopPropagation()}>
                        <RowMenu width={188}>
                          {[
                            // Email-out for estimates lands with the estimate mail flow — no fake success toast.
                            { icon: Send, label: 'Send to Client (soon)', action: () => showToast('Estimate emailing is coming soon', 'error'), disabled: true },
                            isEstimate
                              ? { icon: Receipt, label: 'Convert to Proforma', action: () => handleConvertToProforma(e) }
                              : { icon: Receipt, label: 'Convert to Tax Invoice', action: () => handleConvert(e) },
                            { icon: Trash2, label: 'Delete', action: () => setConfirmDelete(e), danger: true },
                          ].map(a => (
                            <button key={a.label} onClick={() => a.action()}
                              className="w-full flex items-center gap-2.5 px-3 py-2 rounded-xl text-xs font-medium transition-colors"
                              onMouseEnter={ev => ev.currentTarget.style.background = a.danger ? 'rgba(239,68,68,0.06)' : 'rgba(124,58,237,0.06)'}
                              onMouseLeave={ev => ev.currentTarget.style.background = 'transparent'}
                              style={{ color: a.danger ? '#f87171' : 'var(--text-h)' }}>
                              <a.icon size={13} />{a.label}
                            </button>
                          ))}
                        </RowMenu>
                      </td>
                    </tr>
                  ))}
                  {visible.length === 0 && (
                    <tr><td colSpan="8" className="py-16 text-center">
                      <div className="flex flex-col items-center gap-3">
                        <div className="w-14 h-14 rounded-2xl flex items-center justify-center text-2xl" style={{ background: 'rgba(124,58,237,0.08)' }}>📋</div>
                        <p className="text-sm font-semibold" style={{ color: 'var(--text-muted)' }}>No estimates found</p>
                        <button onClick={() => startNew()} className="text-xs font-bold" style={{ color: '#a78bfa' }}>+ Create first estimate</button>
                      </div>
                    </td></tr>
                  )}
                </tbody>
              </table>
            </div>
          </div>
        )
      )}

      {/* ── Pipeline / Kanban View ── */}
      {viewMode === 'pipeline' && (
        <div className="overflow-x-auto pb-4">
          <div className="flex gap-4" style={{ minWidth: `${PIPE_COLS.length * 292}px` }}>
            {PIPE_COLS.map(col => {
              const cards = data.filter(e => effectiveStatus(e) === col.key)
              const colTotal = cards.reduce((s, e) => s + Number(e.total || 0), 0)
              return (
                <div key={col.key} className="kanban-col">
                  {/* Column Header */}
                  <div className="flex items-center justify-between px-4 py-3 rounded-2xl"
                    style={{ background: col.bg, border: `1px solid ${col.color}25` }}>
                    <div className="flex items-center gap-2">
                      <div className="w-2.5 h-2.5 rounded-full" style={{ background: col.color }} />
                      <span className="text-xs font-black" style={{ color: col.color }}>{col.key}</span>
                    </div>
                    <div className="text-right">
                      <p className="text-[10px] font-bold" style={{ color: col.color }}>{cards.length}</p>
                      <p className="text-[10px]" style={{ color: 'var(--text-muted)' }}>{fmt(colTotal)}</p>
                    </div>
                  </div>

                  {/* Cards */}
                  <div className="space-y-3">
                    {cards.map(e => (
                      <div key={e.id} className="card-3d cursor-pointer transition-all"
                        style={{ padding: '16px', borderRadius: '16px' }}
                        onMouseEnter={ev => ev.currentTarget.style.borderColor = col.color + '40'}
                        onMouseLeave={ev => ev.currentTarget.style.borderColor = ''}>
                        <div className="flex items-start justify-between mb-2">
                          <span className="text-[10px] font-bold" style={{ color: col.color }}>
                            {e.reference}
                            {e.payment_received && <span className="ml-1 px-1 py-0.5 rounded text-[8px] font-bold" style={{ background: 'rgba(16,185,129,0.15)', color: '#10b981' }}>PAID</span>}
                          </span>
                          <span className="text-[10px]" style={{ color: 'var(--text-muted)' }}>{fmtDate(e.valid_until)}</span>
                        </div>
                        <p className="text-xs font-bold mb-1 line-clamp-2" style={{ color: 'var(--text-h)' }}>{e.subject}</p>
                        <p className="text-[11px] mb-3" style={{ color: 'var(--text-muted)' }}>{e.client}</p>
                        <div className="flex items-center justify-between">
                          <span className="text-sm font-black" style={{ color: '#a78bfa' }}>{fmt(e.total)}</span>
                          <div className="flex gap-1">
                            <button onClick={() => showToast('Estimate emailing is coming soon', 'error')} title="Coming soon" className="p-1.5 rounded-lg opacity-50 transition-colors">
                              <Send size={11} style={{ color: 'var(--text-muted)' }} />
                            </button>
                            <button onClick={() => showToast('Converted!')} className="p-1.5 rounded-lg hover:bg-[rgba(124,58,237,0.08)] transition-colors">
                              <Receipt size={11} style={{ color: 'var(--text-muted)' }} />
                            </button>
                          </div>
                        </div>
                      </div>
                    ))}
                    {cards.length === 0 && (
                      <div className="flex items-center justify-center py-8 rounded-2xl"
                        style={{ border: `2px dashed ${col.color}20`, background: col.bg }}>
                        <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>No estimates</p>
                      </div>
                    )}
                  </div>
                </div>
              )
            })}
          </div>
        </div>
      )}
      
      </div>

      {/* ── Side Drawer ── */}
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
                  <h2 className="font-black text-lg" style={{ color: 'var(--text-h)', letterSpacing: '-0.02em' }}>New {DOC_LABEL}</h2>
                </div>
                <p className="text-xs mt-1 ml-[42px]" style={{ color: 'var(--text-muted)' }}>Create a formal price quote for a customer</p>
              </div>
              <button onClick={() => setShowDrawer(false)}
                className="w-9 h-9 rounded-xl flex items-center justify-center transition-colors hover:bg-[rgba(239,68,68,0.08)]"
                style={{ border: '1px solid var(--border)' }}>
                <X size={16} style={{ color: 'var(--text-muted)' }} />
              </button>
            </div>

            {/* Drawer Body */}
            <div className="drawer-body">

              {/* Basic Info */}
              <div>
                <p className="label-caps mb-4" style={{ color: '#a78bfa' }}>Basic Information</p>
                <div className="space-y-4">
                  <div>
                    <label className="label">Subject *</label>
                    <input className="input-3d text-sm" placeholder="Estimate subject" value={form.subject} onChange={e => sf('subject', e.target.value)} />
                  </div>
                  <div className="grid grid-cols-2 gap-4">
                    <div>
                      <label className="label">Customer *</label>
                      <select className="input-3d text-sm" value={form.client_id} onChange={e => handleClientChange(e.target.value)}>
                        <option value="">Select customer…</option>
                        {clientOptions.map(c => <option key={c.id} value={c.id}>{c.company}</option>)}
                      </select>
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
                      <label className="label">Estimate Date</label>
                      <input type="date" className="input-3d text-sm" value={form.date} onChange={e => sf('date', e.target.value)} />
                    </div>
                    <div>
                      <label className="label">Valid Until</label>
                      <input type="date" className="input-3d text-sm" value={form.valid_until} onChange={e => sf('valid_until', e.target.value)} />
                    </div>
                  </div>
                </div>
              </div>

              {/* Settings */}
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
                      <label className="label"><User size={10} className="inline mr-1" />Sale Agent</label>
                      <select className="input-3d text-sm" value={form.sale_agent} onChange={e => sf('sale_agent', e.target.value)}>
                        <option value="">Not assigned</option>
                        {STAFF.map(s => <option key={s} value={s}>{s}</option>)}
                      </select>
                    </div>
                    <div>
                      <label className="label">Currency</label>
                      <select className="input-3d text-sm" value={form.currency} onChange={e => sf('currency', e.target.value)}>
                        {['INR','USD','EUR','GBP','AED'].map(c => <option key={c} value={c}>{c}</option>)}
                      </select>
                    </div>
                  </div>
                </div>
              </div>

              {/* Billing Address */}
              <div>
                <button
                  onClick={() => setShowAddr(a => !a)}
                  className="flex items-center gap-2 w-full text-left"
                  style={{ color: '#a78bfa' }}>
                  <MapPin size={13} />
                  <span className="label-caps" style={{ color: '#a78bfa' }}>Billing / Shipping Address</span>
                  <ChevronDown size={13} className={`ml-auto transition-transform ${showAddr ? 'rotate-180' : ''}`} />
                </button>
                {showAddr && (
                  <div className="mt-4 space-y-4">
                    <div>
                      <label className="label">Street Address</label>
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

              {/* Line Items */}
              <div>
                <p className="label-caps mb-4" style={{ color: '#a78bfa' }}>Line Items</p>
                <LineItemsTable
                  items={form.line_items}
                  onChange={rows => sf('line_items', rows)}
                  discount={{ type: form.discount_type, mode: form.discount_mode, value: form.discount_value }}
                  onDiscountChange={d => setForm(p => ({ ...p, discount_type: d.type ?? p.discount_type, discount_mode: d.mode ?? p.discount_mode, discount_value: d.value ?? p.discount_value }))}
                />
              </div>

              {/* Notes section */}
              <div>
                <p className="label-caps mb-4" style={{ color: '#a78bfa' }}>Notes & Terms</p>
                <div className="space-y-4">
                  <div>
                    <label className="label flex items-center gap-1">
                      <span className="px-1.5 py-0.5 rounded text-[9px] font-bold" style={{ background: 'rgba(239,68,68,0.1)', color: '#f87171' }}>🔒 INTERNAL</span>
                      Admin Note (not visible to customer)
                    </label>
                    <RichTextEditor value={form.adminnote} onChange={v => sf('adminnote', v)} placeholder="Internal notes for your team…" minHeight={100} />
                  </div>
                  <div>
                    <label className="label flex items-center gap-1">
                      <span className="px-1.5 py-0.5 rounded text-[9px] font-bold" style={{ background: 'rgba(16,185,129,0.1)', color: '#10b981' }}>VISIBLE</span>
                      Client Note (shown on estimate)
                    </label>
                    <RichTextEditor value={form.clientnote} onChange={v => sf('clientnote', v)} placeholder="Note visible to the customer…" minHeight={100} />
                  </div>
                  <div>
                    <label className="label">Terms & Conditions</label>
                    <RichTextEditor value={form.terms} onChange={v => sf('terms', v)} placeholder="Terms and conditions for this estimate…" minHeight={120} />
                  </div>
                  <div>
                    <label className="label"><Tag size={10} className="inline mr-1" />Tags (comma separated)</label>
                    <input className="input-3d text-sm" placeholder="e.g. q3-2026, enterprise, priority"
                      value={form.tags} onChange={e => sf('tags', e.target.value)} />
                  </div>
                </div>
              </div>
            </div>

            {/* Drawer Footer */}
            <div className="drawer-footer">
              <button onClick={() => setShowDrawer(false)}
                className="flex-1 py-3 rounded-2xl text-sm font-semibold transition-all"
                style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>
                Cancel
              </button>
              <SaveAsTemplateButton docType="estimate" form={form} />
              <button onClick={handleCreate}
                className="flex-[2] py-3 rounded-2xl text-sm font-bold text-white transition-all hover:scale-[1.01]"
                style={{ background: 'linear-gradient(135deg,#9f67ff,#7C3AED,#5b21b6)', boxShadow: '0 6px 20px rgba(124,58,237,0.4)' }}>
                Create {DOC_LABEL}
              </button>
            </div>
          </div>
        </>
      )}

      {confirmDelete && (
        <ConfirmDialog
          title="Delete this proforma invoice?"
          message={`This will permanently delete ${confirmDelete.reference}. This cannot be undone.`}
          confirmLabel="Delete"
          tone="danger"
          onConfirm={handleDelete}
          onCancel={() => setConfirmDelete(null)}
        />
      )}
    </>
  )
}
