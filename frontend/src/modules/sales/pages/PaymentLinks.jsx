import { useState, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { ArrowLeft, Plus, Copy, XCircle, Trash2, Link2, CheckCircle } from 'lucide-react'
import { salesApi } from '@/services/salesApi'
import Drawer from '@/components/ui/Drawer'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import EmptyState from '@/components/ui/EmptyState'
import FormField, { Input, Select, Textarea } from '@/components/ui/FormField'
import { useToast } from '@/hooks/useToast'

const fmt = v => '₹' + Number(v || 0).toLocaleString('en-IN')
const fmtDate = d => d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'

const STATUS_STYLE = {
  active:    { bg: 'rgba(59,130,246,0.12)', color: '#3b82f6', label: 'Active' },
  paid:      { bg: 'rgba(16,185,129,0.12)', color: '#10b981', label: 'Paid' },
  expired:   { bg: 'rgba(245,158,11,0.12)', color: '#fbbf24', label: 'Expired' },
  cancelled: { bg: 'rgba(148,163,184,0.12)', color: '#94a3b8', label: 'Cancelled' },
}

const EMPTY_FORM = {
  amount: '', client_name: '', client_email: '', description: '',
  expiry_date: '', payment_gateway: '',
}

export default function PaymentLinks() {
  const navigate = useNavigate()
  const toast = useToast()
  const [data, setData] = useState([])
  const [loading, setLoading] = useState(true)
  const [filter, setFilter] = useState('All')
  const [showDrawer, setShowDrawer] = useState(false)
  const [form, setForm] = useState(EMPTY_FORM)
  const [creating, setCreating] = useState(false)
  const [confirmCancel, setConfirmCancel] = useState(null)
  const [confirmDelete, setConfirmDelete] = useState(null)

  const sf = (k, v) => setForm(p => ({ ...p, [k]: v }))

  const load = () => {
    setLoading(true)
    salesApi.paymentLinks.list({ status: filter !== 'All' ? filter : undefined }).then(d => { setData(d); setLoading(false) })
  }
  useEffect(() => { load() }, [filter])

  const handleCreate = async () => {
    if (!form.amount) return toast.error('Amount is required')
    setCreating(true)
    try {
      await salesApi.paymentLinks.create({ ...form, expiry_date: form.expiry_date || null })
      toast.success('Payment link created')
      setShowDrawer(false)
      setForm(EMPTY_FORM)
      load()
    } catch (e) {
      toast.error(e.message)
    } finally {
      setCreating(false)
    }
  }

  const handleCopy = (link) => {
    const url = `${window.location.origin}/pay/${link.token}`
    navigator.clipboard.writeText(url)
    toast.success('Payment link copied to clipboard!')
  }

  const handleCancel = async () => {
    try {
      await salesApi.paymentLinks.cancel(confirmCancel.id)
      toast.success('Payment link cancelled')
      setConfirmCancel(null)
      load()
    } catch (e) {
      toast.error(e.message)
    }
  }

  const handleDelete = async () => {
    try {
      await salesApi.paymentLinks.delete(confirmDelete.id)
      toast.success('Payment link deleted')
      setConfirmDelete(null)
      load()
    } catch (e) {
      toast.error(e.message)
    }
  }

  return (
    <div className="space-y-6 animate-fade-in">

      {/* Header */}
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div className="flex items-center gap-3">
          <button onClick={() => navigate('/app/sales/dashboard')}
            className="w-9 h-9 rounded-xl flex items-center justify-center transition-colors hover:bg-[rgba(124,58,237,0.08)]"
            style={{ border: '1px solid var(--border)' }}>
            <ArrowLeft size={16} style={{ color: 'var(--text-muted)' }} />
          </button>
          <div>
            <p className="label-caps mb-1" style={{ color: '#a78bfa' }}>Sales & Revenue</p>
            <h1 className="text-2xl font-black" style={{ color: 'var(--text-h)', letterSpacing: '-0.03em' }}>Payment Links</h1>
          </div>
        </div>
        <button onClick={() => setShowDrawer(true)}
          className="flex items-center gap-2 px-5 py-2.5 rounded-2xl text-sm font-bold text-white transition-all hover:scale-[1.03]"
          style={{ background: 'linear-gradient(135deg,#9f67ff,#7C3AED)', boxShadow: '0 6px 20px rgba(124,58,237,0.4)' }}>
          <Plus size={15} /> New Payment Link
        </button>
      </div>

      {/* Filter tabs */}
      <div className="flex gap-1.5 p-1 rounded-2xl w-fit" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
        {['All', 'active', 'paid', 'expired', 'cancelled'].map(f => (
          <button key={f} onClick={() => setFilter(f)}
            className="px-3 py-1.5 rounded-xl text-xs font-bold capitalize transition-all"
            style={{ background: filter === f ? 'linear-gradient(135deg,#7C3AED,#5b21b6)' : 'transparent', color: filter === f ? '#fff' : 'var(--text-muted)' }}>
            {f}
          </button>
        ))}
      </div>

      {/* List */}
      {loading ? (
        <div className="space-y-2">{[1, 2, 3].map(i => <div key={i} className="skeleton h-16 rounded-xl" style={{ background: 'var(--border)' }} />)}</div>
      ) : !data.length ? (
        <EmptyState
          icon={Link2}
          title="No payment links yet"
          description="Create a shareable link that lets a client pay you directly."
          action={<button onClick={() => setShowDrawer(true)} className="btn-3d">Create your first link</button>}
        />
      ) : (
        <div className="card-3d overflow-hidden" style={{ padding: 0 }}>
          <div className="overflow-x-auto">
            <table className="w-full text-xs">
              <thead>
                <tr style={{ background: 'rgba(124,58,237,0.04)', borderBottom: '1px solid var(--border)' }}>
                  {['Client', 'Amount', 'Description', 'Expires', 'Status', ''].map(h => (
                    <th key={h} className="py-3.5 px-4 text-left label-caps whitespace-nowrap">{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {data.map(link => {
                  const s = STATUS_STYLE[link.status] || STATUS_STYLE.active
                  return (
                    <tr key={link.id} style={{ borderBottom: '1px solid var(--border)' }}>
                      <td className="py-3.5 px-4">
                        <p className="font-semibold" style={{ color: 'var(--text-h)' }}>{link.client_name || '—'}</p>
                        <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>{link.client_email || ''}</p>
                      </td>
                      <td className="py-3.5 px-4 font-bold" style={{ color: 'var(--text-h)' }}>{fmt(link.amount)}</td>
                      <td className="py-3.5 px-4 max-w-[200px] truncate" style={{ color: 'var(--text-muted)' }}>{link.description || '—'}</td>
                      <td className="py-3.5 px-4 whitespace-nowrap" style={{ color: 'var(--text-muted)' }}>{link.expiry_date ? fmtDate(link.expiry_date) : 'No expiry'}</td>
                      <td className="py-3.5 px-4">
                        <span className="px-2 py-0.5 rounded-lg text-[10px] font-bold" style={{ background: s.bg, color: s.color }}>{s.label}</span>
                      </td>
                      <td className="py-3.5 px-4">
                        <div className="flex items-center gap-1">
                          <button onClick={() => handleCopy(link)} className="btn-icon" title="Copy link"><Copy size={13} /></button>
                          {link.status === 'active' && (
                            <button onClick={() => setConfirmCancel(link)} className="btn-icon" title="Cancel">
                              <XCircle size={13} style={{ color: '#f87171' }} />
                            </button>
                          )}
                          <button onClick={() => setConfirmDelete(link)} className="btn-icon" title="Delete">
                            <Trash2 size={13} style={{ color: '#f87171' }} />
                          </button>
                        </div>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* Create drawer */}
      <Drawer
        open={showDrawer}
        onClose={() => setShowDrawer(false)}
        title="New Payment Link"
        footer={
          <button onClick={handleCreate} disabled={creating} className="btn-3d w-full">
            {creating ? 'Creating…' : 'Create Payment Link'}
          </button>
        }
      >
        <div className="space-y-4">
          <FormField label="Amount" required>
            <Input type="number" min="0.01" placeholder="0.00" value={form.amount} onChange={e => sf('amount', e.target.value)} />
          </FormField>
          <FormField label="Client Name">
            <Input value={form.client_name} onChange={e => sf('client_name', e.target.value)} />
          </FormField>
          <FormField label="Client Email">
            <Input type="email" value={form.client_email} onChange={e => sf('client_email', e.target.value)} />
          </FormField>
          <FormField label="Description">
            <Textarea rows={3} value={form.description} onChange={e => sf('description', e.target.value)} />
          </FormField>
          <FormField label="Expiry Date" hint="optional">
            <Input type="date" value={form.expiry_date} onChange={e => sf('expiry_date', e.target.value)} />
          </FormField>
          <FormField label="Payment Gateway" hint="optional">
            <Select value={form.payment_gateway} onChange={e => sf('payment_gateway', e.target.value)}>
              <option value="">Not specified</option>
              <option value="Razorpay">Razorpay</option>
              <option value="Stripe">Stripe</option>
              <option value="PayPal">PayPal</option>
            </Select>
          </FormField>
        </div>
      </Drawer>

      {confirmCancel && (
        <ConfirmDialog
          title="Cancel this payment link?"
          message="The link will stop working immediately. This cannot be undone."
          confirmLabel="Cancel Link"
          tone="danger"
          onConfirm={handleCancel}
          onCancel={() => setConfirmCancel(null)}
        />
      )}

      {confirmDelete && (
        <ConfirmDialog
          title="Delete this payment link?"
          message="This will permanently remove the link. This cannot be undone."
          confirmLabel="Delete"
          tone="danger"
          onConfirm={handleDelete}
          onCancel={() => setConfirmDelete(null)}
        />
      )}
    </div>
  )
}
