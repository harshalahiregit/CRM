import { useState, useEffect } from 'react'
import { Plus, Send, Check, Trash2, X, Truck, MapPin, ChevronDown } from 'lucide-react'
import { salesApi } from '@/services/salesApi'
import { useClientOptions } from '@/hooks/useClientOptions'
import StatusBadge from '../components/StatusBadge'
import RowMenu from '../components/RowMenu'
import { useToast } from '@/hooks/useToast'
import ListToolbar from '@/components/ui/ListToolbar'
import { useListView } from '@/hooks/useListView'
import { exportSalesList } from '@/services/salesApi'
import RichTextEditor from '@/components/ui/RichTextEditor'

const fmtDate = d => d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'

const STATUSES = ['Draft', 'Sent', 'Delivered', 'Cancelled']

const EMPTY_FORM = {
  clientid: '',
  invoiceid: '',
  date: new Date().toISOString().split('T')[0],
  status: 'Draft',
  shipping_address: '',
  shipping_city: '',
  shipping_state: '',
  shipping_country: 'India',
  shipping_zip: '',
  notes: '',
}

export default function DeliveryNotes() {
  const clientOptions = useClientOptions()
  const [data, setData]           = useState([])
  const [loading, setLoading]     = useState(true)
  const [filter, setFilter]       = useState('All')
  const [showDrawer, setShowDrawer] = useState(false)
  const [showAddr, setShowAddr]   = useState(false)
  const [openMenu, setOpenMenu]   = useState(null)
  const [form, setForm]           = useState(EMPTY_FORM)

  // Routed through the shared Toast so every module notifies identically
  // (and error toasts get the per-field validation detail + tip).
  const toast = useToast()
  const showToast = (msg, type = 'success') =>
    type === 'error' ? toast.error(msg) : type === 'info' ? toast.info(msg) : toast.success(msg)
  const sf = (k, v) => setForm(p => ({ ...p, [k]: v }))

  const load = () => {
    setLoading(true)
    salesApi.deliveryNotes.list({ status: filter !== 'All' ? filter : undefined })
      .then(d => { setData(d); setLoading(false) })
  }
  useEffect(() => { load() }, [filter])

  const handleCreate = async () => {
    if (!form.clientid) return showToast('Customer is required', 'error')
    try {
      // Map form → backend field names (client_id / delivery_date / note).
      await salesApi.deliveryNotes.create({
        client_id:        Number(form.clientid),
        delivery_date:    form.date,
        shipping_address: form.shipping_address || null,
        shipping_city:    form.shipping_city || null,
        shipping_state:   form.shipping_state || null,
        shipping_country: form.shipping_country || null,
        shipping_zip:     form.shipping_zip || null,
        note:             form.notes || null,
      })
      showToast('Delivery note created!')
      setShowDrawer(false)
      setForm(EMPTY_FORM)
      load()
    } catch (e) { showToast(e.message || 'Failed to create delivery note', 'error') }
  }

  const stats = {
    total: data.length,
    draft: data.filter(d => d.status === 'Draft').length,
    sent: data.filter(d => d.status === 'Sent').length,
    delivered: data.filter(d => d.status === 'Delivered').length,
  }

  // Search + rows-per-page over the (server status-filtered) list. Client-side
  // because the endpoint returns everything unpaginated, so this costs no request.
  const { search, setSearch, pageSize, setPageSize, visible, matched } =
    useListView(data, ['number', 'client', 'status'])

  return (
    <>
      <div className="space-y-6 animate-[tiltIn_0.35s_ease]" onClick={() => setOpenMenu(null)}>

        {/* Header */}
        <div className="flex items-center justify-between flex-wrap gap-3">
          <div>
            <p className="label-caps mb-1">Sales &amp; Revenue</p>
            <h1 className="font-black" style={{ fontSize: 'clamp(1.3rem,2vw,1.8rem)', color: 'var(--text-h)', letterSpacing: '-0.02em' }}>
              Delivery <span className="text-gradient">Notes</span>
            </h1>
            <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>Physical delivery documents sent with goods to customers</p>
          </div>
          <button
            onClick={e => { e.stopPropagation(); setShowDrawer(true) }}
            className="flex items-center gap-2 px-5 py-2.5 rounded-2xl text-sm font-bold text-white transition-all hover:scale-[1.03]"
            style={{ background: 'linear-gradient(135deg,#9f67ff,#7C3AED,#5b21b6)', boxShadow: '0 6px 20px rgba(124,58,237,0.45)' }}>
            <Plus size={15} /> New Delivery Note
          </button>
        </div>

        {/* KPIs */}
        <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
          {[
            { l: 'Total', v: stats.total, c: '#7C3AED' },
            { l: 'Draft', v: stats.draft, c: '#94a3b8' },
            { l: 'Sent', v: stats.sent, c: '#a78bfa' },
            { l: 'Delivered', v: stats.delivered, c: '#10b981' },
          ].map(k => (
            <div key={k.l} className="kpi-3d py-4 px-5">
              <p className="text-2xl font-black" style={{ color: k.c }}>{k.v}</p>
              <p className="text-xs font-semibold mt-1" style={{ color: 'var(--text-muted)' }}>{k.l}</p>
            </div>
          ))}
        </div>

        {/* Toolbar: search · status tabs · count · rows-per-page · refresh · export */}
        <ListToolbar
          search={search} onSearch={setSearch} searchPlaceholder="Search delivery notes…"
          count={matched} total={data.length} unit="record"
          pageSize={pageSize} onPageSize={setPageSize} onRefresh={load}
          onExport={() => exportSalesList('delivery-notes', { status: filter !== 'All' ? filter : undefined, search: search || undefined })
            .catch(e => showToast(e.message, 'error'))}
        >
        <div className="flex gap-1.5 p-1 rounded-2xl w-fit" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
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

        {/* Table */}
        {loading ? (
          <div className="space-y-2">{[1,2,3].map(i => <div key={i} className="skeleton h-14 rounded-xl" style={{ background: 'var(--border)' }} />)}</div>
        ) : (
          <div className="card-3d overflow-hidden" style={{ padding: 0 }}>
            <div className="overflow-x-auto">
              <table className="w-full text-xs">
                <thead>
                  <tr style={{ background: 'rgba(124,58,237,0.04)', borderBottom: '1px solid var(--border)' }}>
                    {['DN #', 'Invoice', 'Client', 'Items', 'Delivery Date', 'Status', ''].map(h => (
                      <th key={h} className="py-3.5 px-4 text-left label-caps whitespace-nowrap">{h}</th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {visible.map(dn => (
                    <tr key={dn.id} className="transition-colors" style={{ borderBottom: '1px solid var(--border)' }}
                      onMouseEnter={e => e.currentTarget.style.background = 'rgba(124,58,237,0.04)'}
                      onMouseLeave={e => e.currentTarget.style.background = 'transparent'}>
                      <td className="py-3.5 px-4 font-bold" style={{ color: '#a78bfa' }}>{dn.number}</td>
                      <td className="py-3.5 px-4 font-semibold" style={{ color: 'var(--text-h)' }}>{dn.invoice_number || '—'}</td>
                      <td className="py-3.5 px-4" style={{ color: 'var(--text-muted)' }}>{dn.client}</td>
                      <td className="py-3.5 px-4 text-center font-bold" style={{ color: 'var(--text-h)' }}>{dn.items_count}</td>
                      <td className="py-3.5 px-4 whitespace-nowrap" style={{ color: 'var(--text-muted)' }}>{fmtDate(dn.delivery_date)}</td>
                      <td className="py-3.5 px-4"><StatusBadge status={dn.status} /></td>
                      <td className="py-3.5 px-4" onClick={e => e.stopPropagation()}>
                        <RowMenu width={188}>
                          {[
                            { icon: Send, label: 'Send to Customer', action: () => showToast('Delivery note sent!') },
                            { icon: Check, label: 'Mark as Delivered', action: () => showToast('Marked as Delivered!') },
                            { icon: Trash2, label: 'Delete', action: () => showToast('Deleted!', 'error'), danger: true },
                          ].map(a => (
                            <button key={a.label} onClick={() => a.action()}
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
                    <tr><td colSpan="7" className="py-16 text-center">
                      <div className="flex flex-col items-center gap-3">
                        <div className="w-14 h-14 rounded-2xl flex items-center justify-center text-2xl" style={{ background: 'rgba(124,58,237,0.08)' }}>🚚</div>
                        <p className="text-sm font-semibold" style={{ color: 'var(--text-muted)' }}>No delivery notes found</p>
                        <button onClick={() => setShowDrawer(true)} className="text-xs font-bold" style={{ color: '#a78bfa' }}>+ Create first delivery note</button>
                      </div>
                    </td></tr>
                  )}
                </tbody>
              </table>
            </div>
          </div>
        )}

      </div>

      {/* ── Create Drawer ── */}
      {showDrawer && (
        <>
          <div className="drawer-backdrop" />
          <div className="drawer-panel" style={{ width: 'min(820px, 95vw)' }}>

            {/* Header */}
            <div className="drawer-header">
              <div>
                <div className="flex items-center gap-2.5">
                  <div className="w-8 h-8 rounded-xl flex items-center justify-center"
                    style={{ background: 'linear-gradient(135deg,#9f67ff,#7C3AED)', boxShadow: '0 4px 12px rgba(124,58,237,0.4)' }}>
                    <Truck size={14} className="text-white" />
                  </div>
                  <h2 className="font-black text-lg" style={{ color: 'var(--text-h)', letterSpacing: '-0.02em' }}>New Delivery Note</h2>
                </div>
                <p className="text-xs mt-1 ml-[42px]" style={{ color: 'var(--text-muted)' }}>Create a delivery document for goods shipped to a customer</p>
              </div>
              <button onClick={() => setShowDrawer(false)}
                className="w-9 h-9 rounded-xl flex items-center justify-center transition-colors hover:bg-[rgba(239,68,68,0.08)]"
                style={{ border: '1px solid var(--border)' }}>
                <X size={16} style={{ color: 'var(--text-muted)' }} />
              </button>
            </div>

            {/* Body */}
            <div className="drawer-body">

              {/* Basic Info */}
              <div>
                <p className="label-caps mb-4" style={{ color: '#a78bfa' }}>Basic Information</p>
                <div className="space-y-4">
                  <div className="grid grid-cols-2 gap-4">
                    <div>
                      <label className="label">Customer *</label>
                      <select className="input-3d text-sm" value={form.clientid} onChange={e => sf('clientid', e.target.value)}>
                        <option value="">Select customer…</option>
                        {clientOptions.map(c => <option key={c.id} value={c.id}>{c.company || c.name}</option>)}
                      </select>
                    </div>
                    <div>
                      <label className="label">Link to Invoice</label>
                      <input className="input-3d text-sm" placeholder="INV-2026-001 (optional)"
                        value={form.invoiceid} onChange={e => sf('invoiceid', e.target.value)} />
                    </div>
                  </div>
                  <div className="grid grid-cols-2 gap-4">
                    <div>
                      <label className="label">Delivery Date</label>
                      <input type="date" className="input-3d text-sm" value={form.date} onChange={e => sf('date', e.target.value)} />
                    </div>
                    <div>
                      <label className="label">Status</label>
                      <select className="input-3d text-sm" value={form.status} onChange={e => sf('status', e.target.value)}>
                        {STATUSES.map(s => <option key={s} value={s}>{s}</option>)}
                      </select>
                    </div>
                  </div>
                </div>
              </div>

              {/* Shipping Address */}
              <div>
                <button
                  onClick={() => setShowAddr(a => !a)}
                  className="flex items-center gap-2 w-full text-left"
                  style={{ color: '#a78bfa' }}>
                  <MapPin size={13} />
                  <span className="label-caps" style={{ color: '#a78bfa' }}>Shipping Address</span>
                  <ChevronDown size={13} className={`ml-auto transition-transform ${showAddr ? 'rotate-180' : ''}`} />
                </button>
                {showAddr && (
                  <div className="mt-4 space-y-4">
                    <div>
                      <label className="label">Street Address</label>
                      <input className="input-3d text-sm" placeholder="Street address"
                        value={form.shipping_address} onChange={e => sf('shipping_address', e.target.value)} />
                    </div>
                    <div className="grid grid-cols-2 gap-4">
                      <div><label className="label">City</label><input className="input-3d text-sm" placeholder="Mumbai" value={form.shipping_city} onChange={e => sf('shipping_city', e.target.value)} /></div>
                      <div><label className="label">State</label><input className="input-3d text-sm" placeholder="Maharashtra" value={form.shipping_state} onChange={e => sf('shipping_state', e.target.value)} /></div>
                    </div>
                    <div className="grid grid-cols-2 gap-4">
                      <div><label className="label">Country</label><input className="input-3d text-sm" placeholder="India" value={form.shipping_country} onChange={e => sf('shipping_country', e.target.value)} /></div>
                      <div><label className="label">ZIP / PIN</label><input className="input-3d text-sm" placeholder="400001" value={form.shipping_zip} onChange={e => sf('shipping_zip', e.target.value)} /></div>
                    </div>
                  </div>
                )}
              </div>

              {/* Notes */}
              <div>
                <p className="label-caps mb-4" style={{ color: '#a78bfa' }}>Notes</p>
                <div>
                  <label className="label">Delivery Notes</label>
                  <RichTextEditor value={form.notes} onChange={v => sf('notes', v)} placeholder="Delivery instructions, packing details…" minHeight={100} />
                </div>
              </div>

            </div>

            {/* Footer */}
            <div className="drawer-footer">
              <button onClick={() => setShowDrawer(false)}
                className="flex-1 py-3 rounded-2xl text-sm font-semibold transition-all"
                style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>
                Cancel
              </button>
              <button onClick={handleCreate}
                className="flex-[2] py-3 rounded-2xl text-sm font-bold text-white transition-all hover:scale-[1.01]"
                style={{ background: 'linear-gradient(135deg,#9f67ff,#7C3AED,#5b21b6)', boxShadow: '0 6px 20px rgba(124,58,237,0.4)' }}>
                Create Delivery Note
              </button>
            </div>
          </div>
        </>
      )}
    </>
  )
}
