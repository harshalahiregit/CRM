import { useState, useEffect } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { Plus, Send, CreditCard, Trash2, X, MoreVertical, Bell, RefreshCw, Tag, User } from 'lucide-react'
import { salesApi } from '@/services/salesApi'
import { useClientOptions } from '@/hooks/useClientOptions'
import { useProjectOptions } from '@/hooks/useProjectOptions'
import StatusBadge from '../components/StatusBadge'
import LineItemsTable from '../components/LineItemsTable'
import RowMenu from '../components/RowMenu'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import RichTextEditor from '@/components/ui/RichTextEditor'
import { useToast } from '@/hooks/useToast'

const fmt = v => '₹' + Number(v||0).toLocaleString('en-IN')
const fmtDate = d => d ? new Date(d).toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'}) : '—'
const STAFF = ['Zafar Farooque','Priya Sharma','Rohit Verma','Anjali Singh','Karan Mehta']
const PAY_MODES = ['Bank Transfer','Cash','Cheque','Stripe','Razorpay','PayPal','UPI']
const STATUSES = ['Draft','Unpaid','Partially Paid','Paid','Overdue','Cancelled']

const EMPTY = {
  client_id:'', project_id:'', date: new Date().toISOString().split('T')[0],
  due_date:'', currency:'INR', sale_agent:'', discount_type:'before_tax',
  discount_mode:'fixed', discount_value: 0,
  recurring: false, recur_interval:'1', recur_type:'month', cycles:'0',
  allowed_modes: ['Bank Transfer','UPI','Razorpay'],
  cancel_overdue_reminders: false,
  adminnote:'', clientnote:'', terms:'', tags:'',
  line_items: [],
}

const EMPTY_PAY = { amount:'', date: new Date().toISOString().split('T')[0], mode:'Bank Transfer', transaction_id:'', tds_amount:'', tds_section:'' }

export default function Invoices() {
  const navigate = useNavigate()
  const clientOptions = useClientOptions()
  const projectOptions = useProjectOptions()
  const [data, setData]         = useState([])
  const [loading, setLoading]   = useState(true)
  const [filter, setFilter]     = useState('All')
  const [showDrawer, setShowDrawer] = useState(false)
  const [showPayModal, setShowPayModal] = useState(false)
  const [selectedInv, setSelectedInv] = useState(null)
  const [openMenu, setOpenMenu] = useState(null)
  const [form, setForm]         = useState(EMPTY)
  const [payForm, setPayForm]   = useState(EMPTY_PAY)
  const [confirmDelete, setConfirmDelete] = useState(null)
  const [showTds, setShowTds]   = useState(false)

  // Routed through the shared Toast so every module notifies identically
  // (and error toasts get the per-field validation detail + tip).
  const toast = useToast()
  const showToast = (msg, type = 'success') =>
    type === 'error' ? toast.error(msg) : type === 'info' ? toast.info(msg) : toast.success(msg)
  const sf = (k,v) => setForm(p=>({...p,[k]:v}))
  const toggleMode = (m) => sf('allowed_modes', form.allowed_modes.includes(m) ? form.allowed_modes.filter(x=>x!==m) : [...form.allowed_modes,m])

  const load = () => {
    setLoading(true)
    salesApi.invoices.list({status: filter!=='All'?filter:undefined}).then(d=>{setData(d);setLoading(false)})
  }
  useEffect(()=>{ load() },[filter])

  // Arriving from a customer profile's "New Invoice" button: open the create
  // drawer with that customer preselected.
  const [searchParams, setSearchParams] = useSearchParams()
  useEffect(() => {
    if (searchParams.get('new') === '1') {
      const cid = searchParams.get('client_id') || ''
      setForm(p => ({ ...p, client_id: cid }))
      setShowDrawer(true)
      setSearchParams({}, { replace: true })
    }
  }, [])

  const handleCreate = async () => {
    if(!form.client_id) return showToast('Customer required','error')
    await salesApi.invoices.create({...form, client_id: Number(form.client_id), project_id: form.project_id ? Number(form.project_id) : null})
    showToast('Invoice created!'); setShowDrawer(false); setForm(EMPTY); load()
  }
  const handlePay = async () => {
    if(!payForm.amount) return showToast('Amount required','error')
    await salesApi.invoices.recordPayment(selectedInv.id, payForm)
    showToast('Payment recorded!'); setShowPayModal(false); setPayForm(EMPTY_PAY); setShowTds(false); load()
  }
  const handleSendReminder = async (inv) => {
    try {
      await salesApi.invoices.sendPaymentReminder(inv.id)
      showToast('Payment reminder sent!')
    } catch (e) {
      showToast(e.message || 'Failed to send reminder', 'error')
    }
  }
  const handleToggleGst = async (inv) => {
    try {
      await salesApi.invoices.update(inv.id, { gst_paid: !inv.gst_paid })
      showToast(inv.gst_paid ? 'Marked GST not paid' : 'Marked GST paid')
      load()
    } catch (e) {
      showToast(e.message || 'Failed to update', 'error')
    }
  }
  const handleDelete = async () => {
    try {
      await salesApi.invoices.delete(confirmDelete.id)
      showToast('Invoice deleted')
      setConfirmDelete(null)
      load()
    } catch (e) {
      showToast(e.message || 'Failed to delete', 'error')
    }
  }

  const stats = {
    total: data.length,
    unpaid: data.filter(i=>i.status==='Unpaid').length,
    overdue: data.filter(i=>i.status==='Overdue').length,
    paid: data.filter(i=>i.status==='Paid').length,
    totalAmt: data.reduce((s,i)=>s+Number(i.total||0),0),
    outstanding: data.reduce((s,i)=>s+Number(i.balance||0),0),
  }

  return (
    <>
      <div className="space-y-6 animate-[tiltIn_0.35s_ease]" onClick={()=>setOpenMenu(null)}>

      {/* Header */}
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div>
          <p className="label-caps mb-1">Sales & Revenue</p>
          <h1 className="font-black" style={{fontSize:'clamp(1.3rem,2vw,1.8rem)',color:'var(--text-h)',letterSpacing:'-0.02em'}}><span className="text-gradient">Invoices</span></h1>
          <p className="text-xs mt-0.5" style={{color:'var(--text-muted)'}}>Billing documents sent to customers</p>
        </div>
        <button onClick={()=>setShowDrawer(true)} className="flex items-center gap-2 px-5 py-2.5 rounded-2xl text-sm font-bold text-white hover:scale-[1.03] transition-all" style={{background:'linear-gradient(135deg,#9f67ff,#7C3AED,#5b21b6)',boxShadow:'0 6px 20px rgba(124,58,237,0.45)'}}>
          <Plus size={15}/> New Invoice
        </button>
      </div>

      {/* KPIs */}
      <div className="grid grid-cols-2 lg:grid-cols-6 gap-3">
        {[
          {l:'Total Invoiced', v:fmt(stats.totalAmt), c:'#7C3AED'},
          {l:'Outstanding', v:fmt(stats.outstanding), c:'#f87171'},
          {l:'Total', v:stats.total, c:'#7C3AED'},
          {l:'Unpaid', v:stats.unpaid, c:'#a78bfa'},
          {l:'Overdue', v:stats.overdue, c:'#f87171'},
          {l:'Paid', v:stats.paid, c:'#10b981'},
        ].map(k=>(
          <div key={k.l} className="kpi-3d py-4 px-4">
            <p className="text-xl font-black" style={{color:k.c}}>{k.v}</p>
            <p className="text-xs font-semibold mt-1" style={{color:'var(--text-muted)'}}>{k.l}</p>
          </div>
        ))}
      </div>

      {/* Filter tabs */}
      <div className="flex gap-1.5 p-1 rounded-2xl w-fit" style={{background:'var(--bg-input)',border:'1px solid var(--border)'}}>
        {['All',...STATUSES].map(f=>(
          <button key={f} onClick={()=>setFilter(f)} className="px-3 py-1.5 rounded-xl text-xs font-bold transition-all"
            style={{background:filter===f?'linear-gradient(135deg,#7C3AED,#5b21b6)':'transparent',color:filter===f?'#fff':'var(--text-muted)'}}>
            {f}
          </button>
        ))}
      </div>

      {/* Table */}
      {loading ? <div className="space-y-2">{[1,2,3].map(i=><div key={i} className="skeleton h-14 rounded-xl" style={{background:'var(--border)'}}/>)}</div> : (
        <div className="card-3d overflow-hidden" style={{padding:0}}>
          <div className="overflow-x-auto">
            <table className="w-full text-xs">
              <thead>
                <tr style={{background:'rgba(124,58,237,0.04)',borderBottom:'1px solid var(--border)'}}>
                  {['Invoice','Client','Date','Due Date','GST','Amount','Balance','Status',''].map(h=>(
                    <th key={h} className="py-3.5 px-4 text-left label-caps whitespace-nowrap">{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {data.map(inv=>(
                  <tr key={inv.id} className="cursor-pointer transition-colors" style={{borderBottom:'1px solid var(--border)'}}
                    onClick={()=>navigate(`/app/sales/invoices/${inv.id}`)}
                    onMouseEnter={e=>e.currentTarget.style.background='rgba(124,58,237,0.04)'}
                    onMouseLeave={e=>e.currentTarget.style.background='transparent'}>
                    <td className="py-3.5 px-4 font-bold" style={{color:'#a78bfa'}}>{inv.number}{inv.recurring&&<span className="ml-1 text-[10px]">🔄</span>}</td>
                    <td className="py-3.5 px-4 font-semibold" style={{color:'var(--text-h)'}}>{inv.client}</td>
                    <td className="py-3.5 px-4 whitespace-nowrap" style={{color:'var(--text-muted)'}}>{fmtDate(inv.date)}</td>
                    <td className="py-3.5 px-4 whitespace-nowrap" style={{color:inv.status==='Overdue'?'#f87171':'var(--text-muted)'}}>{fmtDate(inv.due_date)}</td>
                    <td className="py-3.5 px-4" onClick={e=>{e.stopPropagation();handleToggleGst(inv)}}>
                      <span className="px-2 py-0.5 rounded-lg text-[10px] font-bold cursor-pointer" style={{background:inv.gst_paid?'rgba(16,185,129,0.12)':'rgba(245,158,11,0.12)',color:inv.gst_paid?'#10b981':'#fbbf24'}}>
                        {inv.gst_paid?'GST Paid':'GST Due'}
                      </span>
                    </td>
                    <td className="py-3.5 px-4 font-bold whitespace-nowrap" style={{color:'var(--text-h)'}}>{fmt(inv.total)}</td>
                    <td className="py-3.5 px-4 font-bold whitespace-nowrap" style={{color:inv.balance>0?'#f87171':'#10b981'}}>{fmt(inv.balance)}</td>
                    <td className="py-3.5 px-4"><StatusBadge status={inv.status}/></td>
                    <td className="py-3.5 px-4" onClick={e=>e.stopPropagation()}>
                      <RowMenu width={188}>
                        {[
                          {icon:CreditCard, label:'Record Payment', action:()=>{setSelectedInv(inv);setShowPayModal(true)}},
                          {icon:Send, label:'Send Invoice', action:()=>showToast('Invoice sent!')},
                          {icon:Bell, label:'Send Reminder', action:()=>handleSendReminder(inv)},
                          {icon:Trash2, label:'Delete', action:()=>setConfirmDelete(inv), danger:true},
                        ].map(a=>(
                          <button key={a.label} onClick={()=>a.action()}
                            className="w-full flex items-center gap-2.5 px-3 py-2 rounded-xl text-xs font-medium transition-colors"
                            onMouseEnter={e=>e.currentTarget.style.background=a.danger?'rgba(239,68,68,0.06)':'rgba(124,58,237,0.06)'}
                            onMouseLeave={e=>e.currentTarget.style.background='transparent'}
                            style={{color:a.danger?'#f87171':'var(--text-h)'}}>
                            <a.icon size={13}/>{a.label}
                          </button>
                        ))}
                      </RowMenu>
                    </td>
                  </tr>
                ))}
                {data.length===0 && <tr><td colSpan="8" className="py-16 text-center">
                  <div className="flex flex-col items-center gap-3">
                    <div className="w-14 h-14 rounded-2xl flex items-center justify-center text-2xl" style={{background:'rgba(124,58,237,0.08)'}}>🧾</div>
                    <p className="text-sm font-semibold" style={{color:'var(--text-muted)'}}>No invoices found</p>
                    <button onClick={()=>setShowDrawer(true)} className="text-xs font-bold" style={{color:'#a78bfa'}}>+ Create first invoice</button>
                  </div>
                </td></tr>}
              </tbody>
            </table>
          </div>
        </div>
      )}

      </div>

      {/* ── New Invoice Drawer ── */}
      {showDrawer && (
        <>
          <div className="drawer-backdrop" />
          <div className="drawer-panel" style={{ width: 'min(1080px, 96vw)' }}>
            {/* Header */}
            <div className="drawer-header">
              <div className="flex items-center gap-2.5">
                <div className="w-8 h-8 rounded-xl flex items-center justify-center" style={{ background: 'linear-gradient(135deg,#9f67ff,#7C3AED)', boxShadow: '0 4px 12px rgba(124,58,237,0.4)' }}>
                  <CreditCard size={14} className="text-white" />
                </div>
                <div>
                  <h2 className="font-black text-lg" style={{ color: 'var(--text-h)', letterSpacing: '-0.02em' }}>New Invoice</h2>
                  <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Create a billing document for a customer</p>
                </div>
              </div>
              <button onClick={() => setShowDrawer(false)} className="w-9 h-9 rounded-xl flex items-center justify-center hover:bg-[rgba(239,68,68,0.08)] transition-colors" style={{ border: '1px solid var(--border)' }}>
                <X size={16} style={{ color: 'var(--text-muted)' }} />
              </button>
            </div>

            {/* Body */}
            <div className="drawer-body">
              {/* Basic */}
              <div>
                <p className="label-caps mb-4" style={{ color: '#a78bfa' }}>Basic Information</p>
                <div className="space-y-4">
                  <div className="grid grid-cols-2 gap-4">
                    <div>
                      <label className="label">Customer *</label>
                      <select className="input-3d text-sm" value={form.client_id} onChange={e => sf('client_id', e.target.value)}>
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
                      <label className="label">Invoice Date</label>
                      <input type="date" className="input-3d text-sm" value={form.date} onChange={e => sf('date', e.target.value)} />
                    </div>
                    <div>
                      <label className="label">Due Date</label>
                      <input type="date" className="input-3d text-sm" value={form.due_date} onChange={e => sf('due_date', e.target.value)} />
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
                      <label className="label">Discount Type</label>
                      <select className="input-3d text-sm" value={form.discount_type} onChange={e => sf('discount_type', e.target.value)}>
                        <option value="none">No Discount</option>
                        <option value="before_tax">Before Tax</option>
                        <option value="after_tax">After Tax</option>
                      </select>
                    </div>
                  </div>
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
              </div>

              {/* Recurring */}
              <div>
                <p className="label-caps mb-4" style={{ color: '#a78bfa' }}>Recurring Settings</p>
                <div className="p-4 rounded-2xl space-y-4" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
                  <div className="flex items-center justify-between">
                    <div className="flex items-center gap-2.5">
                      <div className="w-8 h-8 rounded-xl flex items-center justify-center" style={{ background: 'rgba(59,130,246,0.1)' }}>
                        <RefreshCw size={14} style={{ color: '#3b82f6' }} />
                      </div>
                      <div>
                        <p className="text-sm font-semibold" style={{ color: 'var(--text-h)' }}>Recurring Invoice</p>
                        <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Auto-create new invoice on schedule</p>
                      </div>
                    </div>
                    <button onClick={() => sf('recurring', !form.recurring)} className="relative w-12 h-6 rounded-full transition-all duration-300" style={{ background: form.recurring ? 'linear-gradient(135deg,#3b82f6,#2563eb)' : 'rgba(255,255,255,0.1)' }}>
                      <span className="absolute top-0.5 h-5 w-5 bg-white rounded-full shadow transition-all duration-300" style={{ left: form.recurring ? '26px' : '2px' }} />
                    </button>
                  </div>
                  {form.recurring && (
                    <div className="grid grid-cols-3 gap-3 pt-1">
                      <div>
                        <label className="label">Every</label>
                        <input type="number" min="1" className="input-3d text-sm" value={form.recur_interval} onChange={e => sf('recur_interval', e.target.value)} />
                      </div>
                      <div>
                        <label className="label">Period</label>
                        <select className="input-3d text-sm" value={form.recur_type} onChange={e => sf('recur_type', e.target.value)}>
                          {['day', 'week', 'month', 'year'].map(t => <option key={t} value={t}>{t}</option>)}
                        </select>
                      </div>
                      <div>
                        <label className="label">Cycles (0=∞)</label>
                        <input type="number" min="0" className="input-3d text-sm" value={form.cycles} onChange={e => sf('cycles', e.target.value)} />
                      </div>
                    </div>
                  )}
                </div>
              </div>

              {/* Payment Modes */}
              <div>
                <p className="label-caps mb-4" style={{ color: '#a78bfa' }}>Allowed Payment Modes</p>
                <div className="flex flex-wrap gap-2">
                  {PAY_MODES.map(m => {
                    const active = form.allowed_modes.includes(m)
                    return (
                      <button key={m} onClick={() => toggleMode(m)}
                        className="px-3 py-2 rounded-xl text-xs font-bold transition-all"
                        style={{
                          background: active ? 'linear-gradient(135deg,#7C3AED,#5b21b6)' : 'var(--bg-input)',
                          color: active ? '#fff' : 'var(--text-muted)',
                          border: `1px solid ${active ? 'transparent' : 'var(--border)'}`,
                          boxShadow: active ? '0 3px 10px rgba(124,58,237,0.3)' : 'none',
                        }}>
                        {m}
                      </button>
                    )
                  })}
                </div>
              </div>

              {/* Overdue Reminder */}
              <div className="flex items-center justify-between p-4 rounded-2xl" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
                <div>
                  <p className="text-sm font-semibold" style={{ color: 'var(--text-h)' }}>Cancel Overdue Reminders</p>
                  <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Stop automatic overdue emails for this invoice</p>
                </div>
                <button onClick={() => sf('cancel_overdue_reminders', !form.cancel_overdue_reminders)}
                  className="relative w-12 h-6 rounded-full transition-all duration-300"
                  style={{ background: form.cancel_overdue_reminders ? 'linear-gradient(135deg,#f87171,#ef4444)' : 'rgba(255,255,255,0.1)' }}>
                  <span className="absolute top-0.5 h-5 w-5 bg-white rounded-full shadow transition-all duration-300" style={{ left: form.cancel_overdue_reminders ? '26px' : '2px' }} />
                </button>
              </div>

              {/* Notes */}
              <div>
                <p className="label-caps mb-4" style={{ color: '#a78bfa' }}>Notes & Terms</p>
                <div className="space-y-4">
                  <div>
                    <label className="label flex items-center gap-1">
                      <span className="px-1.5 py-0.5 rounded text-[9px] font-bold" style={{ background: 'rgba(239,68,68,0.1)', color: '#f87171' }}>🔒 INTERNAL</span>
                      Admin Note
                    </label>
                    <textarea className="input-3d text-sm resize-none" rows={2} placeholder="Internal notes (not visible to customer)…" value={form.adminnote} onChange={e => sf('adminnote', e.target.value)} />
                  </div>
                  <div>
                    <label className="label flex items-center gap-1">
                      <span className="px-1.5 py-0.5 rounded text-[9px] font-bold" style={{ background: 'rgba(16,185,129,0.1)', color: '#10b981' }}>VISIBLE</span>
                      Client Note
                    </label>
                    <textarea className="input-3d text-sm resize-none" rows={2} placeholder="Note visible on the invoice…" value={form.clientnote} onChange={e => sf('clientnote', e.target.value)} />
                  </div>
                  <div>
                    <label className="label">Terms & Conditions</label>
                    <RichTextEditor value={form.terms} onChange={v => sf('terms', v)} placeholder="Payment terms, conditions…" minHeight={120} />
                  </div>
                  <div>
                    <label className="label"><Tag size={10} className="inline mr-1" />Tags</label>
                    <input className="input-3d text-sm" placeholder="e.g. q3-2026, recurring, priority" value={form.tags} onChange={e => sf('tags', e.target.value)} />
                  </div>
                </div>
              </div>
            </div>

            {/* Footer */}
            <div className="drawer-footer">
              <button onClick={() => setShowDrawer(false)} className="flex-1 py-3 rounded-2xl text-sm font-semibold" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>Cancel</button>
              <button onClick={handleCreate} className="flex-[2] py-3 rounded-2xl text-sm font-bold text-white hover:scale-[1.01] transition-all" style={{ background: 'linear-gradient(135deg,#9f67ff,#7C3AED,#5b21b6)', boxShadow: '0 6px 20px rgba(124,58,237,0.4)' }}>Create Invoice</button>
            </div>
          </div>
        </>
      )}

      {/* ── Record Payment Drawer ── */}
      {showPayModal && selectedInv && (
        <>
          <div className="drawer-backdrop" />
          <div className="drawer-panel" style={{ width: 'min(480px, 95vw)' }}>
            <div className="drawer-header">
              <div>
                <div className="flex items-center gap-2.5">
                  <div className="w-8 h-8 rounded-xl flex items-center justify-center"
                    style={{ background: 'linear-gradient(135deg,#10b981,#059669)', boxShadow: '0 4px 12px rgba(16,185,129,0.4)' }}>
                    <CreditCard size={14} className="text-white" />
                  </div>
                  <h2 className="font-black text-lg" style={{ color: 'var(--text-h)', letterSpacing: '-0.02em' }}>Record Payment</h2>
                </div>
                <p className="text-xs mt-1 ml-[42px]" style={{ color: 'var(--text-muted)' }}>Record a payment received for this invoice</p>
              </div>
              <button onClick={() => setShowPayModal(false)}
                className="w-9 h-9 rounded-xl flex items-center justify-center transition-colors hover:bg-[rgba(239,68,68,0.08)]"
                style={{ border: '1px solid var(--border)' }}>
                <X size={16} style={{ color: 'var(--text-muted)' }} />
              </button>
            </div>
            <div className="drawer-body">
              <div className="p-4 rounded-2xl" style={{ background: 'rgba(124,58,237,0.06)', border: '1px solid rgba(124,58,237,0.15)' }}>
                <p className="text-xs font-bold" style={{ color: '#a78bfa' }}>{selectedInv.number}</p>
                <div className="flex justify-between items-center mt-1">
                  <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{selectedInv.client}</p>
                  <p className="text-sm font-black" style={{ color: 'var(--text-h)' }}>Balance: {fmt(selectedInv.balance)}</p>
                </div>
              </div>
              <div className="space-y-4">
                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <label className="label">Amount *</label>
                    <input type="number" className="input-3d text-sm" placeholder={`Max: ${selectedInv.balance}`}
                      value={payForm.amount} onChange={e => setPayForm(p => ({...p, amount: e.target.value}))} />
                  </div>
                  <div>
                    <label className="label">Payment Date</label>
                    <input type="date" className="input-3d text-sm"
                      value={payForm.date || new Date().toISOString().split('T')[0]}
                      onChange={e => setPayForm(p => ({...p, date: e.target.value}))} />
                  </div>
                </div>
                <div>
                  <label className="label">Payment Mode</label>
                  <div className="grid grid-cols-4 gap-2">
                    {PAY_MODES.map(m => {
                      const mc = m==='Bank Transfer'?'#3b82f6':m==='Cash'?'#10b981':m==='Cheque'?'#f59e0b':m==='Razorpay'?'#528ff0':m==='Stripe'?'#635bff':m==='UPI'?'#00baf2':'#a78bfa'
                      return (
                        <button key={m} onClick={() => setPayForm(p => ({...p, mode: m}))}
                          className="py-2 px-1 rounded-xl text-[10px] font-bold transition-all text-center"
                          style={{
                            background: payForm.mode===m ? mc+'20' : 'var(--bg-input)',
                            color: payForm.mode===m ? mc : 'var(--text-muted)',
                            border: `1px solid ${payForm.mode===m ? mc+'60' : 'var(--border)'}`,
                          }}>
                          {m}
                        </button>
                      )
                    })}
                  </div>
                </div>
                <div>
                  <label className="label">Transaction / Reference ID</label>
                  <input className="input-3d text-sm" placeholder="Bank ref, UTR, Stripe charge ID…"
                    value={payForm.transaction_id} onChange={e => setPayForm(p => ({...p, transaction_id: e.target.value}))} />
                </div>
                <div>
                  <label className="label">Note</label>
                  <textarea className="input-3d text-sm resize-none" rows={3} placeholder="Optional payment note…"
                    value={payForm.note || ''} onChange={e => setPayForm(p => ({...p, note: e.target.value}))} />
                </div>

                <button onClick={() => setShowTds(s => !s)}
                  className="text-xs font-bold flex items-center gap-1.5" style={{ color: '#a78bfa' }}>
                  {showTds ? '− Hide' : '+ Add'} TDS deduction
                </button>
                {showTds && (
                  <div className="grid grid-cols-2 gap-4 p-3 rounded-2xl" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
                    <div>
                      <label className="label">TDS Amount</label>
                      <input type="number" className="input-3d text-sm" placeholder="0.00"
                        value={payForm.tds_amount} onChange={e => setPayForm(p => ({...p, tds_amount: e.target.value}))} />
                    </div>
                    <div>
                      <label className="label">TDS Section</label>
                      <input className="input-3d text-sm" placeholder="e.g. 194C, 194J"
                        value={payForm.tds_section} onChange={e => setPayForm(p => ({...p, tds_section: e.target.value}))} />
                    </div>
                  </div>
                )}
              </div>
            </div>
            <div className="drawer-footer">
              <button onClick={() => setShowPayModal(false)}
                className="flex-1 py-3 rounded-2xl text-sm font-semibold"
                style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>
                Cancel
              </button>
              <button onClick={handlePay}
                className="flex-[2] py-3 rounded-2xl text-sm font-bold text-white transition-all hover:scale-[1.01]"
                style={{ background: 'linear-gradient(135deg,#10b981,#059669)', boxShadow: '0 6px 20px rgba(16,185,129,0.4)' }}>
                Record Payment
              </button>
            </div>
          </div>
        </>
      )}

      {confirmDelete && (
        <ConfirmDialog
          title="Delete this invoice?"
          message={`This will permanently delete invoice ${confirmDelete.number}. This cannot be undone.`}
          confirmLabel="Delete"
          tone="danger"
          onConfirm={handleDelete}
          onCancel={() => setConfirmDelete(null)}
        />
      )}
    </>
  )
}
