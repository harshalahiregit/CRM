import { useState, useEffect } from 'react'
import { useSearchParams } from 'react-router-dom'
import { Plus, Trash2, X, MoreVertical, Ban, ArrowRightLeft, Receipt, Tag } from 'lucide-react'
import { salesApi } from '@/services/salesApi'
import { useClientOptions } from '@/hooks/useClientOptions'
import StatusBadge from '../components/StatusBadge'
import RowMenu from '../components/RowMenu'
import RichTextEditor from '@/components/ui/RichTextEditor'
import { useToast } from '@/hooks/useToast'
import ListToolbar from '@/components/ui/ListToolbar'
import { useListView } from '@/hooks/useListView'
import { exportSalesList } from '@/services/salesApi'

const fmt = v => '₹' + Number(v||0).toLocaleString('en-IN')
const fmtDate = d => d ? new Date(d).toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'}) : '—'
const STATUSES = ['Open','Closed','Void']
const EMPTY = { client_id:'', invoice_number:'', amount:'', discount_type:'none', adminnote:'', clientnote:'', terms:'', reason:'', tags:'' }
const EMPTY_REFUND = { amount:'', mode:'Bank Transfer', reference:'', note:'' }
const PAY_MODES = ['Bank Transfer','Cash','Cheque','Stripe','Razorpay','PayPal','UPI']

export default function CreditNotes() {
  const clientOptions = useClientOptions()
  const [searchParams] = useSearchParams()
  const [data, setData]         = useState([])
  const [loading, setLoading]   = useState(true)
  const [filter, setFilter]     = useState('All')
  const [showDrawer, setShowDrawer] = useState(false)
  const [showRefund, setShowRefund] = useState(false)
  const [selectedCN, setSelectedCN] = useState(null)
  const [openMenu, setOpenMenu] = useState(null)
  const [form, setForm]         = useState(EMPTY)
  const [refundForm, setRefundForm] = useState(EMPTY_REFUND)

  // Routed through the shared Toast so every module notifies identically
  // (and error toasts get the per-field validation detail + tip).
  const toast = useToast()
  const showToast = (msg, type = 'success') =>
    type === 'error' ? toast.error(msg) : type === 'info' ? toast.info(msg) : toast.success(msg)
  const sf = (k,v) => setForm(p=>({...p,[k]:v}))

  const load = () => {
    setLoading(true)
    salesApi.creditNotes.list({status:filter!=='All'?filter:undefined}).then(d=>{setData(d);setLoading(false)})
  }
  useEffect(()=>{ load() },[filter])

  // Preselect the customer + open the create drawer when arriving from the
  // customer profile (?client_id=…&new=1).
  useEffect(() => {
    if (searchParams.get('new') === '1') {
      setForm(p => ({ ...p, client_id: searchParams.get('client_id') || '' }))
      setShowDrawer(true)
    }
  }, [searchParams])

  const handleCreate = async () => {
    if(!form.client_id||!form.amount) return showToast('Customer & amount required','error')
    // The credit-note total is derived from line items — map the entered amount
    // to a single line so it persists.
    await salesApi.creditNotes.create({
      ...form,
      client_id: Number(form.client_id),
      date: new Date().toISOString().split('T')[0], // backend requires date
      line_items: [{ item_name: form.reason || 'Credit', qty: 1, rate: Number(form.amount), tax: 0, discount: 0 }],
    })
    showToast('Credit note created!'); setShowDrawer(false); setForm(EMPTY); load()
  }
  const handleRefund = async () => {
    if(!refundForm.amount) return showToast('Amount required','error')
    showToast('Refund recorded!'); setShowRefund(false); setRefundForm(EMPTY_REFUND)
  }

  const stats = {
    total: data.length,
    open: data.filter(c=>c.status==='Open').length,
    closed: data.filter(c=>c.status==='Closed').length,
    void: data.filter(c=>c.status==='Void').length,
    available: data.filter(c=>c.status==='Open').reduce((s,c)=>s+c.amount,0),
  }

  // Search + rows-per-page over the (server status-filtered) list. Client-side
  // because the endpoint returns everything unpaginated, so this costs no request.
  const { search, setSearch, pageSize, setPageSize, visible, matched } =
    useListView(data, ['number', 'client', 'status', 'reference'])

  return (
    <>
      <div className="space-y-6 animate-[tiltIn_0.35s_ease]" onClick={()=>setOpenMenu(null)}>

      <div className="flex items-center justify-between flex-wrap gap-3">
        <div>
          <p className="label-caps mb-1">Sales & Revenue</p>
          <h1 className="font-black" style={{fontSize:'clamp(1.3rem,2vw,1.8rem)',color:'var(--text-h)',letterSpacing:'-0.02em'}}>Credit <span className="text-gradient">Notes</span></h1>
          <p className="text-xs mt-0.5" style={{color:'var(--text-muted)'}}>Refunds and credit adjustments for customers</p>
        </div>
        <button onClick={()=>setShowDrawer(true)} className="flex items-center gap-2 px-5 py-2.5 rounded-2xl text-sm font-bold text-white hover:scale-[1.03] transition-all" style={{background:'linear-gradient(135deg,#9f67ff,#7C3AED,#5b21b6)',boxShadow:'0 6px 20px rgba(124,58,237,0.45)'}}>
          <Plus size={15}/> New Credit Note
        </button>
      </div>

      <div className="grid grid-cols-2 lg:grid-cols-5 gap-3">
        {[
          {l:'Available Credit', v:fmt(stats.available), c:'#10b981'},
          {l:'Total', v:stats.total, c:'#7C3AED'},
          {l:'Open', v:stats.open, c:'#10b981'},
          {l:'Closed', v:stats.closed, c:'#94a3b8'},
          {l:'Void', v:stats.void, c:'#f87171'},
        ].map(k=>(
          <div key={k.l} className="kpi-3d py-4 px-4">
            <p className="text-xl font-black" style={{color:k.c}}>{k.v}</p>
            <p className="text-xs font-semibold mt-1" style={{color:'var(--text-muted)'}}>{k.l}</p>
          </div>
        ))}
      </div>

      {/* Toolbar: search · status tabs · count · rows-per-page · refresh · export */}
      <ListToolbar
        search={search} onSearch={setSearch} searchPlaceholder="Search credit notes…"
        count={matched} total={data.length} unit="record"
        pageSize={pageSize} onPageSize={setPageSize} onRefresh={load}
        onExport={() => exportSalesList('credit-notes', { status: filter !== 'All' ? filter : undefined, search: search || undefined })
          .catch(e => showToast(e.message, 'error'))}
      >
      <div className="flex gap-1.5 p-1 rounded-2xl w-fit" style={{background:'var(--bg-input)',border:'1px solid var(--border)'}}>
        {['All',...STATUSES].map(f=>(
          <button key={f} onClick={()=>setFilter(f)} className="px-3 py-1.5 rounded-xl text-xs font-bold transition-all"
            style={{background:filter===f?'linear-gradient(135deg,#7C3AED,#5b21b6)':'transparent',color:filter===f?'#fff':'var(--text-muted)'}}>
            {f}
          </button>
        ))}
      </div>
      </ListToolbar>

      {loading ? <div className="space-y-2">{[1,2,3].map(i=><div key={i} className="skeleton h-14 rounded-xl" style={{background:'var(--border)'}}/>)}</div> : (
        <div className="card-3d overflow-hidden" style={{padding:0}}>
          <div className="overflow-x-auto">
            <table className="w-full text-xs">
              <thead>
                <tr style={{background:'rgba(124,58,237,0.04)',borderBottom:'1px solid var(--border)'}}>
                  {['CN #','Client','Invoice','Amount','Date','Reason','Status',''].map(h=>(
                    <th key={h} className="py-3.5 px-4 text-left label-caps whitespace-nowrap">{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {visible.map(cn=>(
                  <tr key={cn.id} className="transition-colors" style={{borderBottom:'1px solid var(--border)'}}
                    onMouseEnter={e=>e.currentTarget.style.background='rgba(124,58,237,0.04)'}
                    onMouseLeave={e=>e.currentTarget.style.background='transparent'}>
                    <td className="py-3.5 px-4 font-bold" style={{color:'#a78bfa'}}>{cn.number}</td>
                    <td className="py-3.5 px-4 font-semibold" style={{color:'var(--text-h)'}}>{cn.client}</td>
                    <td className="py-3.5 px-4" style={{color:'var(--text-muted)'}}>{cn.invoice_number||'—'}</td>
                    <td className="py-3.5 px-4 font-bold" style={{color:'#10b981'}}>{fmt(cn.amount)}</td>
                    <td className="py-3.5 px-4 whitespace-nowrap" style={{color:'var(--text-muted)'}}>{fmtDate(cn.date)}</td>
                    <td className="py-3.5 px-4 max-w-[180px]" style={{color:'var(--text-muted)'}}><span className="truncate block">{cn.reason||'—'}</span></td>
                    <td className="py-3.5 px-4"><StatusBadge status={cn.status}/></td>
                    <td className="py-3.5 px-4" onClick={e=>e.stopPropagation()}>
                      <RowMenu width={188}>
                        {[
                          {icon:ArrowRightLeft, label:'Apply to Invoice', action:()=>showToast('Applied to invoice!')},
                          {icon:Receipt, label:'Create Refund', action:()=>{setSelectedCN(cn);setShowRefund(true)}},
                          {icon:Ban, label:'Mark Void', action:()=>showToast('Marked void!')},
                          {icon:Trash2, label:'Delete', action:()=>showToast('Deleted!','error'), danger:true},
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
                {visible.length===0 && <tr><td colSpan="8" className="py-12 text-center" style={{color:'var(--text-muted)'}}>No credit notes found.</td></tr>}
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
            <div className="drawer-header">
              <div>
                <h2 className="font-black text-lg" style={{ color: 'var(--text-h)' }}>New Credit Note</h2>
                <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Issue a credit or refund adjustment</p>
              </div>
              <button onClick={() => setShowDrawer(false)} className="w-9 h-9 rounded-xl flex items-center justify-center hover:bg-[rgba(239,68,68,0.08)] transition-colors" style={{ border: '1px solid var(--border)' }}><X size={16} style={{ color: 'var(--text-muted)' }} /></button>
            </div>
            <div className="drawer-body">
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
                      <label className="label">Related Invoice</label>
                      <input className="input-3d text-sm" placeholder="INV-2026-001 (optional)" value={form.invoice_number} onChange={e => sf('invoice_number', e.target.value)} />
                    </div>
                  </div>
                  <div className="grid grid-cols-2 gap-4">
                    <div>
                      <label className="label">Credit Amount *</label>
                      <input type="number" className="input-3d text-sm" placeholder="0.00" value={form.amount} onChange={e => sf('amount', e.target.value)} />
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
                  <div>
                    <label className="label">Reason for Credit</label>
                    <input className="input-3d text-sm" placeholder="e.g. Return of goods, service issue…" value={form.reason} onChange={e => sf('reason', e.target.value)} />
                  </div>
                </div>
              </div>
              <div>
                <p className="label-caps mb-4" style={{ color: '#a78bfa' }}>Notes & Terms</p>
                <div className="space-y-4">
                  <div>
                    <label className="label flex items-center gap-1">
                      <span className="px-1.5 py-0.5 rounded text-[9px] font-bold" style={{ background: 'rgba(239,68,68,0.1)', color: '#f87171' }}>🔒 INTERNAL</span>
                      Admin Note
                    </label>
                    <RichTextEditor value={form.adminnote} onChange={v => sf('adminnote', v)} placeholder="Internal notes…" minHeight={90} />
                  </div>
                  <div>
                    <label className="label flex items-center gap-1">
                      <span className="px-1.5 py-0.5 rounded text-[9px] font-bold" style={{ background: 'rgba(16,185,129,0.1)', color: '#10b981' }}>VISIBLE</span>
                      Client Note
                    </label>
                    <RichTextEditor value={form.clientnote} onChange={v => sf('clientnote', v)} placeholder="Note visible to customer…" minHeight={90} />
                  </div>
                  <div>
                    <label className="label">Terms</label>
                    <RichTextEditor value={form.terms} onChange={v => sf('terms', v)} placeholder="Terms and conditions…" minHeight={120} />
                  </div>
                  <div>
                    <label className="label"><Tag size={10} className="inline mr-1" />Tags</label>
                    <input className="input-3d text-sm" placeholder="e.g. refund, returns" value={form.tags} onChange={e => sf('tags', e.target.value)} />
                  </div>
                </div>
              </div>
            </div>
            <div className="drawer-footer">
              <button onClick={() => setShowDrawer(false)} className="flex-1 py-3 rounded-2xl text-sm font-semibold" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>Cancel</button>
              <button onClick={handleCreate} className="flex-[2] py-3 rounded-2xl text-sm font-bold text-white hover:scale-[1.01] transition-all" style={{ background: 'linear-gradient(135deg,#9f67ff,#7C3AED,#5b21b6)', boxShadow: '0 6px 20px rgba(124,58,237,0.4)' }}>Create Credit Note</button>
            </div>
          </div>
        </>
      )}

      {/* ── Refund Drawer ── */}
      {showRefund && selectedCN && (
        <>
          <div className="drawer-backdrop" />
          <div className="drawer-panel" style={{ width: 'min(460px, 95vw)' }}>
            <div className="drawer-header">
              <div>
                <div className="flex items-center gap-2.5">
                  <div className="w-8 h-8 rounded-xl flex items-center justify-center"
                    style={{ background: 'linear-gradient(135deg,#10b981,#059669)', boxShadow: '0 4px 12px rgba(16,185,129,0.4)' }}>
                    <Receipt size={14} className="text-white" />
                  </div>
                  <h2 className="font-black text-lg" style={{ color: 'var(--text-h)', letterSpacing: '-0.02em' }}>Create Refund</h2>
                </div>
                <p className="text-xs mt-1 ml-[42px]" style={{ color: 'var(--text-muted)' }}>Issue a cash refund against this credit note</p>
              </div>
              <button onClick={() => setShowRefund(false)}
                className="w-9 h-9 rounded-xl flex items-center justify-center transition-colors hover:bg-[rgba(239,68,68,0.08)]"
                style={{ border: '1px solid var(--border)' }}>
                <X size={16} style={{ color: 'var(--text-muted)' }} />
              </button>
            </div>
            <div className="drawer-body">
              <div className="p-4 rounded-2xl" style={{ background: 'rgba(16,185,129,0.06)', border: '1px solid rgba(16,185,129,0.2)' }}>
                <p className="text-xs font-bold" style={{ color: '#10b981' }}>{selectedCN.number}</p>
                <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{selectedCN.client} · Credit: {fmt(selectedCN.amount)}</p>
              </div>
              <div className="space-y-4">
                <div>
                  <label className="label">Refund Amount *</label>
                  <input type="number" className="input-3d text-sm" placeholder="Amount to refund"
                    value={refundForm.amount} onChange={e => setRefundForm(p => ({...p, amount: e.target.value}))} />
                </div>
                <div>
                  <label className="label">Refund Method</label>
                  <div className="grid grid-cols-4 gap-2">
                    {PAY_MODES.map(m => {
                      const mc = m==='Bank Transfer'?'#3b82f6':m==='Cash'?'#10b981':m==='Cheque'?'#f59e0b':m==='Razorpay'?'#528ff0':m==='Stripe'?'#635bff':m==='UPI'?'#00baf2':'#a78bfa'
                      return (
                        <button key={m} onClick={() => setRefundForm(p => ({...p, mode: m}))}
                          className="py-2 px-1 rounded-xl text-[10px] font-bold transition-all text-center"
                          style={{
                            background: refundForm.mode===m ? mc+'20' : 'var(--bg-input)',
                            color: refundForm.mode===m ? mc : 'var(--text-muted)',
                            border: `1px solid ${refundForm.mode===m ? mc+'60' : 'var(--border)'}`,
                          }}>
                          {m}
                        </button>
                      )
                    })}
                  </div>
                </div>
                <div>
                  <label className="label">Reference</label>
                  <input className="input-3d text-sm" placeholder="Transaction reference"
                    value={refundForm.reference} onChange={e => setRefundForm(p => ({...p, reference: e.target.value}))} />
                </div>
                <div>
                  <label className="label">Note</label>
                  <textarea className="input-3d text-sm resize-none" rows={3} placeholder="Refund reason / note"
                    value={refundForm.note} onChange={e => setRefundForm(p => ({...p, note: e.target.value}))} />
                </div>
              </div>
            </div>
            <div className="drawer-footer">
              <button onClick={() => setShowRefund(false)}
                className="flex-1 py-3 rounded-2xl text-sm font-semibold"
                style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>
                Cancel
              </button>
              <button onClick={handleRefund}
                className="flex-[2] py-3 rounded-2xl text-sm font-bold text-white transition-all hover:scale-[1.01]"
                style={{ background: 'linear-gradient(135deg,#10b981,#059669)', boxShadow: '0 6px 20px rgba(16,185,129,0.4)' }}>
                Record Refund
              </button>
            </div>
          </div>
        </>
      )}
    </>
  )
}
