import { useState, useEffect } from 'react'
import { Plus, Send, Trash2, X, Search, Filter } from 'lucide-react'
import { salesApi } from '@/services/salesApi'
import { useToast } from '@/hooks/useToast'
import ListToolbar from '@/components/ui/ListToolbar'
import { useListView } from '@/hooks/useListView'
import { exportSalesList } from '@/services/salesApi'

const fmt  = v => '₹' + Number(v || 0).toLocaleString('en-IN')
const fmtDate = d => d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'

const PAY_MODES = ['Bank Transfer', 'Cash', 'Cheque', 'Stripe', 'Razorpay', 'PayPal', 'UPI']

const mc = m =>
  m === 'Bank Transfer' ? '#3b82f6'
  : m === 'Cash'        ? '#10b981'
  : m === 'Cheque'      ? '#f59e0b'
  : m === 'Razorpay'    ? '#528ff0'
  : m === 'Stripe'      ? '#635bff'
  : m === 'UPI'         ? '#00baf2'
  : '#a78bfa'

const EMPTY_FORM = {
  invoiceid: '',
  amount: '',
  date: new Date().toISOString().split('T')[0],
  paymentmode: 'Bank Transfer',
  transactionid: '',
  note: '',
}

export default function Payments() {
  const [data, setData]           = useState([])
  const [loading, setLoading]     = useState(true)
  const [filterMode, setFilterMode] = useState('All')
  const [search, setSearch]       = useState('')
  const [showDrawer, setShowDrawer] = useState(false)
  const [form, setForm]           = useState(EMPTY_FORM)
  const [invoices, setInvoices]   = useState([])

  // Routed through the shared Toast so every module notifies identically
  // (and error toasts get the per-field validation detail + tip).
  const toast = useToast()
  const showToast = (msg, type = 'success') =>
    type === 'error' ? toast.error(msg) : type === 'info' ? toast.info(msg) : toast.success(msg)
  const sf = (k, v) => setForm(p => ({ ...p, [k]: v }))

  const load = () => {
    setLoading(true)
    salesApi.payments.list({ mode: filterMode !== 'All' ? filterMode : undefined })
      .then(d => { setData(d); setLoading(false) })
  }
  useEffect(() => { load() }, [filterMode])
  // Payments are recorded against a real invoice — load the list for the picker.
  useEffect(() => { salesApi.invoices.list().then(d => setInvoices(Array.isArray(d) ? d : (d?.data || []))).catch(() => {}) }, [])

  const handleRecord = async () => {
    if (!form.amount || !form.invoiceid) return showToast('Invoice & amount are required', 'error')
    try {
      await salesApi.invoices.recordPayment(form.invoiceid, {
        amount: Number(form.amount),
        date: form.date,
        mode: form.paymentmode,
        transaction_id: form.transactionid || null,
        note: form.note || null,
      })
      showToast('Payment recorded successfully!')
      setShowDrawer(false)
      setForm(EMPTY_FORM)
      load()
    } catch (e) { showToast(e.message, 'error') }
  }

  const filtered = search
    ? data.filter(p => p.invoice_number?.toLowerCase().includes(search.toLowerCase()) || p.client?.toLowerCase().includes(search.toLowerCase()))
    : data

  const totalAmt  = data.reduce((s, p) => s + (p.amount || 0), 0)
  const modeBreakdown = PAY_MODES.map(m => ({ mode: m, count: data.filter(p => p.mode === m).length, total: data.filter(p => p.mode === m).reduce((s, p) => s + (p.amount || 0), 0) })).filter(m => m.count > 0)

  // Paging + count over the page's own search result.
  const { pageSize, setPageSize, visible, matched } = useListView(filtered, [])

  return (
    <>
      <div className="space-y-6 animate-[tiltIn_0.35s_ease]">

        {/* Header */}
        <div className="flex items-center justify-between flex-wrap gap-3">
          <div>
            <p className="label-caps mb-1">Sales &amp; Revenue</p>
            <h1 className="font-black" style={{ fontSize: 'clamp(1.3rem,2vw,1.8rem)', color: 'var(--text-h)', letterSpacing: '-0.02em' }}>
              <span className="text-gradient">Payments</span>
            </h1>
            <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>Track and record all payments received against invoices</p>
          </div>
          <button
            onClick={() => setShowDrawer(true)}
            className="flex items-center gap-2 px-5 py-2.5 rounded-2xl text-sm font-bold text-white transition-all hover:scale-[1.03]"
            style={{ background: 'linear-gradient(135deg,#9f67ff,#7C3AED,#5b21b6)', boxShadow: '0 6px 20px rgba(124,58,237,0.45)' }}>
            <Plus size={15} /> Record Payment
          </button>
        </div>

        {/* KPIs */}
        <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
          <div className="kpi-3d py-4 px-5">
            <p className="text-xl font-black" style={{ color: '#10b981' }}>{fmt(totalAmt)}</p>
            <p className="text-xs font-semibold mt-1" style={{ color: 'var(--text-muted)' }}>Total Collected</p>
          </div>
          <div className="kpi-3d py-4 px-5">
            <p className="text-2xl font-black" style={{ color: '#7C3AED' }}>{data.length}</p>
            <p className="text-xs font-semibold mt-1" style={{ color: 'var(--text-muted)' }}>Total Payments</p>
          </div>
          {modeBreakdown.slice(0, 2).map(m => (
            <div key={m.mode} className="kpi-3d py-4 px-5">
              <p className="text-xl font-black" style={{ color: mc(m.mode) }}>{fmt(m.total)}</p>
              <p className="text-xs font-semibold mt-1" style={{ color: 'var(--text-muted)' }}>{m.mode} ({m.count})</p>
            </div>
          ))}
        </div>

        {/* Mode breakdown strip */}
        {modeBreakdown.length > 0 && (
          <div className="card-3d py-3 px-5 flex gap-3 flex-wrap items-center">
            <span className="label-caps mr-2">By Mode:</span>
            {modeBreakdown.map(m => (
              <span key={m.mode} className="px-2.5 py-1 rounded-xl text-[10px] font-bold"
                style={{ background: mc(m.mode) + '18', color: mc(m.mode) }}>
                {m.mode} · {m.count} · {fmt(m.total)}
              </span>
            ))}
          </div>
        )}

        {/* Filters */}
        <div className="flex items-center gap-3 flex-wrap">
          <div className="flex gap-1.5 p-1 rounded-2xl" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
            {['All', ...PAY_MODES].map(f => (
              <button key={f} onClick={() => setFilterMode(f)}
                className="px-3 py-1.5 rounded-xl text-xs font-bold transition-all"
                style={{
                  background: filterMode === f ? 'linear-gradient(135deg,#7C3AED,#5b21b6)' : 'transparent',
                  color: filterMode === f ? '#fff' : 'var(--text-muted)',
                }}>
                {f}
              </button>
            ))}
          </div>
          <ListToolbar
            search={search} onSearch={setSearch} searchPlaceholder="Search by invoice or client…"
            count={matched} total={data.length} unit="payment"
            pageSize={pageSize} onPageSize={setPageSize} onRefresh={load}
            onExport={() => exportSalesList('payments', { search: search || undefined })
              .catch(e => showToast(e.message, 'error'))}
            className="flex-1"
          />
        </div>

        {/* Table */}
        {loading ? (
          <div className="space-y-2">{[1,2,3].map(i => <div key={i} className="skeleton h-14 rounded-xl" style={{ background: 'var(--border)' }} />)}</div>
        ) : (
          <div className="card-3d overflow-hidden" style={{ padding: 0 }}>
            <div className="overflow-x-auto">
              <table className="w-full text-xs">
                <thead>
                  <tr style={{ background: 'rgba(124,58,237,0.04)', borderBottom: '1px solid var(--border)' }}>
                    {['Payment ID', 'Invoice', 'Client', 'Date', 'Amount', 'Mode', 'Transaction ID', 'Note', ''].map(h => (
                      <th key={h} className="py-3.5 px-4 text-left label-caps whitespace-nowrap">{h}</th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {visible.map(p => (
                    <tr key={p.id} className="transition-colors" style={{ borderBottom: '1px solid var(--border)' }}
                      onMouseEnter={e => e.currentTarget.style.background = 'rgba(124,58,237,0.04)'}
                      onMouseLeave={e => e.currentTarget.style.background = 'transparent'}>
                      <td className="py-3.5 px-4 font-bold" style={{ color: '#a78bfa' }}>PAY-{String(p.id).padStart(3, '0')}</td>
                      <td className="py-3.5 px-4 font-semibold" style={{ color: 'var(--text-h)' }}>{p.invoice_number}</td>
                      <td className="py-3.5 px-4" style={{ color: 'var(--text-muted)' }}>{p.client}</td>
                      <td className="py-3.5 px-4 whitespace-nowrap" style={{ color: 'var(--text-muted)' }}>{fmtDate(p.date)}</td>
                      <td className="py-3.5 px-4 font-bold" style={{ color: '#10b981' }}>{fmt(p.amount)}</td>
                      <td className="py-3.5 px-4">
                        <span className="px-2.5 py-1 rounded-xl text-[10px] font-bold" style={{ background: mc(p.mode) + '18', color: mc(p.mode) }}>
                          {p.mode}
                        </span>
                      </td>
                      <td className="py-3.5 px-4 font-mono text-[10px]" style={{ color: 'var(--text-muted)' }}>{p.reference || '—'}</td>
                      <td className="py-3.5 px-4 max-w-[140px]" style={{ color: 'var(--text-muted)' }}>
                        <span className="truncate block">{p.note || '—'}</span>
                      </td>
                      <td className="py-3.5 px-4">
                        <div className="flex gap-1">
                          <button onClick={() => showToast('Receipt sent!')}
                            className="p-1.5 rounded-lg transition-colors hover:bg-[rgba(124,58,237,0.08)]" title="Send Receipt">
                            <Send size={12} style={{ color: 'var(--text-muted)' }} />
                          </button>
                          <button onClick={() => showToast('Deleted!', 'error')}
                            className="p-1.5 rounded-lg transition-colors hover:bg-[rgba(239,68,68,0.08)]" title="Delete">
                            <Trash2 size={12} style={{ color: '#f87171' }} />
                          </button>
                        </div>
                      </td>
                    </tr>
                  ))}
                  {filtered.length === 0 && (
                    <tr><td colSpan="9" className="py-16 text-center">
                      <div className="flex flex-col items-center gap-3">
                        <div className="w-14 h-14 rounded-2xl flex items-center justify-center text-2xl" style={{ background: 'rgba(124,58,237,0.08)' }}>💳</div>
                        <p className="text-sm font-semibold" style={{ color: 'var(--text-muted)' }}>No payments found</p>
                        <button onClick={() => setShowDrawer(true)} className="text-xs font-bold" style={{ color: '#a78bfa' }}>+ Record first payment</button>
                      </div>
                    </td></tr>
                  )}
                </tbody>
              </table>
            </div>
          </div>
        )}

      </div>

      {/* ── Record Payment Drawer ── */}
      {showDrawer && (
        <>
          <div className="drawer-backdrop" />
          <div className="drawer-panel" style={{ width: 'min(720px, 95vw)' }}>

            {/* Header */}
            <div className="drawer-header">
              <div>
                <div className="flex items-center gap-2.5">
                  <div className="w-8 h-8 rounded-xl flex items-center justify-center"
                    style={{ background: 'linear-gradient(135deg,#10b981,#059669)', boxShadow: '0 4px 12px rgba(16,185,129,0.4)' }}>
                    <Plus size={14} className="text-white" />
                  </div>
                  <h2 className="font-black text-lg" style={{ color: 'var(--text-h)', letterSpacing: '-0.02em' }}>Record Payment</h2>
                </div>
                <p className="text-xs mt-1 ml-[42px]" style={{ color: 'var(--text-muted)' }}>Record a payment received against an invoice</p>
              </div>
              <button onClick={() => setShowDrawer(false)}
                className="w-9 h-9 rounded-xl flex items-center justify-center transition-colors hover:bg-[rgba(239,68,68,0.08)]"
                style={{ border: '1px solid var(--border)' }}>
                <X size={16} style={{ color: 'var(--text-muted)' }} />
              </button>
            </div>

            {/* Body */}
            <div className="drawer-body">
              <div>
                <p className="label-caps mb-4" style={{ color: '#10b981' }}>Payment Details</p>
                <div className="space-y-4">

                  <div>
                    <label className="label">Invoice *</label>
                    <select className="input-3d text-sm" value={form.invoiceid} onChange={e => sf('invoiceid', e.target.value)}>
                      <option value="">Select an invoice…</option>
                      {invoices.filter(inv => Number(inv.balance) > 0).map(inv => (
                        <option key={inv.id} value={inv.id}>
                          {inv.number} — {inv.client?.company || inv.client_name || 'Customer'} (bal {fmt(inv.balance)})
                        </option>
                      ))}
                    </select>
                  </div>

                  <div className="grid grid-cols-2 gap-4">
                    <div>
                      <label className="label">Amount *</label>
                      <input type="number" className="input-3d text-sm" placeholder="0.00"
                        value={form.amount} onChange={e => sf('amount', e.target.value)} />
                    </div>
                    <div>
                      <label className="label">Payment Date</label>
                      <input type="date" className="input-3d text-sm"
                        value={form.date} onChange={e => sf('date', e.target.value)} />
                    </div>
                  </div>

                  <div>
                    <label className="label">Payment Mode</label>
                    <div className="grid grid-cols-4 gap-2">
                      {PAY_MODES.map(m => (
                        <button key={m}
                          onClick={() => sf('paymentmode', m)}
                          className="py-2 px-2 rounded-xl text-[10px] font-bold transition-all text-center"
                          style={{
                            background: form.paymentmode === m ? mc(m) + '20' : 'var(--bg-input)',
                            color: form.paymentmode === m ? mc(m) : 'var(--text-muted)',
                            border: `1px solid ${form.paymentmode === m ? mc(m) + '60' : 'var(--border)'}`,
                          }}>
                          {m}
                        </button>
                      ))}
                    </div>
                  </div>

                  <div>
                    <label className="label">Transaction / Reference ID</label>
                    <input className="input-3d text-sm" placeholder="Bank ref, UTR, Stripe charge ID…"
                      value={form.transactionid} onChange={e => sf('transactionid', e.target.value)} />
                  </div>

                  <div>
                    <label className="label">Note</label>
                    <textarea className="input-3d text-sm resize-none" rows={3}
                      placeholder="Optional note about this payment…"
                      value={form.note} onChange={e => sf('note', e.target.value)} />
                  </div>

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
              <button onClick={handleRecord}
                className="flex-[2] py-3 rounded-2xl text-sm font-bold text-white transition-all hover:scale-[1.01]"
                style={{ background: 'linear-gradient(135deg,#10b981,#059669)', boxShadow: '0 6px 20px rgba(16,185,129,0.4)' }}>
                Record Payment
              </button>
            </div>
          </div>
        </>
      )}
    </>
  )
}
