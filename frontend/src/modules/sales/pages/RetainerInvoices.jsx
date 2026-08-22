import { useState, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { ArrowLeft, Plus, Trash2, RefreshCw } from 'lucide-react'
import { salesApi } from '@/services/salesApi'
import LoadError from '@/components/ui/LoadError'
import Drawer from '@/components/ui/Drawer'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import EmptyState from '@/components/ui/EmptyState'
import FormField, { Input, Select } from '@/components/ui/FormField'
import { useToast } from '@/hooks/useToast'
import ListToolbar from '@/components/ui/ListToolbar'
import { useListView } from '@/hooks/useListView'

const fmt = v => '₹' + Number(v || 0).toLocaleString('en-IN')
const fmtDate = d => d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'

const STATUS_STYLE = {
  Draft:   { bg: 'rgba(148,163,184,0.12)', color: '#94a3b8' },
  Sent:    { bg: 'rgba(59,130,246,0.12)',  color: '#3b82f6' },
  Paid:    { bg: 'rgba(16,185,129,0.12)',  color: '#10b981' },
  Overdue: { bg: 'rgba(239,68,68,0.12)',   color: '#f87171' },
}

const EMPTY_FORM = {
  amount: '', billing_period_start: '', billing_period_end: '',
  retainer_type: 'monthly', auto_create: false, next_billing_date: '',
}

export default function RetainerInvoices() {
  const navigate = useNavigate()
  const toast = useToast()
  const [data, setData] = useState([])
  const [loading, setLoading] = useState(true)
  const [loadError, setLoadError] = useState(null)
  const [filter, setFilter] = useState('All')
  const [showDrawer, setShowDrawer] = useState(false)
  const [form, setForm] = useState(EMPTY_FORM)
  const [creating, setCreating] = useState(false)
  const [confirmDelete, setConfirmDelete] = useState(null)

  const sf = (k, v) => setForm(p => ({ ...p, [k]: v }))

  const load = () => {
    setLoading(true)
    salesApi.retainerInvoices.list({ status: filter !== 'All' ? filter : undefined }).then(d => { setData(d); setLoadError(null) })
      .catch(e => setLoadError(e))
      .finally(() => setLoading(false))
  }
  useEffect(() => { load() }, [filter])

  const handleCreate = async () => {
    if (!form.amount || !form.billing_period_start || !form.billing_period_end) {
      return toast.error('Amount and billing period are required')
    }
    setCreating(true)
    try {
      await salesApi.retainerInvoices.create({ ...form, next_billing_date: form.next_billing_date || null })
      toast.success('Retainer invoice created')
      setShowDrawer(false)
      setForm(EMPTY_FORM)
      load()
    } catch (e) {
      toast.error(e.message)
    } finally {
      setCreating(false)
    }
  }

  const handleDelete = async () => {
    try {
      await salesApi.retainerInvoices.delete(confirmDelete.id)
      toast.success('Retainer invoice deleted')
      setConfirmDelete(null)
      load()
    } catch (e) {
      toast.error(e.message)
    }
  }

  // Search + rows-per-page client-side: the endpoint returns everything unpaginated.
  const { search, setSearch, pageSize, setPageSize, visible, matched, pager } =
    useListView(data, ['number','client','status'])

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
            <h1 className="text-2xl font-black" style={{ color: 'var(--text-h)', letterSpacing: '-0.03em' }}>Retainer Invoices</h1>
          </div>
        </div>
        <button onClick={() => setShowDrawer(true)}
          className="flex items-center gap-2 px-5 py-2.5 rounded-2xl text-sm font-bold text-white transition-all hover:scale-[1.03]"
          style={{ background: 'linear-gradient(135deg,#9f67ff,#7C3AED)', boxShadow: '0 6px 20px rgba(124,58,237,0.4)' }}>
          <Plus size={15} /> New Retainer
        </button>
      </div>

      {/* Filter tabs */}
      <div className="flex gap-1.5 p-1 rounded-2xl w-fit" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
        {['All', 'Draft', 'Sent', 'Paid', 'Overdue'].map(f => (
          <button key={f} onClick={() => setFilter(f)}
            className="px-3 py-1.5 rounded-xl text-xs font-bold transition-all"
            style={{ background: filter === f ? 'linear-gradient(135deg,#7C3AED,#5b21b6)' : 'transparent', color: filter === f ? '#fff' : 'var(--text-muted)' }}>
            {f}
          </button>
        ))}
      </div>

      {/* Toolbar: search · count · rows-per-page · refresh */}
      <ListToolbar
        search={search} onSearch={setSearch} searchPlaceholder="Search retainer invoices…"
        count={matched} total={data.length} unit="record"
        pageSize={pageSize} onPageSize={setPageSize} pager={pager} onRefresh={load} />

      {/* List */}
      {/* A failed load must not read as an empty list — that is a claim,
          and it was the only thing this page said when the API was down. */}
      {loadError && <LoadError error={loadError} onRetry={load} className="mb-4" />}

      {loading ? (
        <div className="space-y-2">{[1, 2, 3].map(i => <div key={i} className="skeleton h-16 rounded-xl" style={{ background: 'var(--border)' }} />)}</div>
      ) : !data.length ? (
        <EmptyState
          icon={RefreshCw}
          title="No retainer invoices yet"
          description="Set up recurring billing for clients on a monthly, quarterly, or yearly retainer."
          action={<button onClick={() => setShowDrawer(true)} className="btn-3d">Create your first retainer</button>}
        />
      ) : (
        <div className="card-3d overflow-hidden" style={{ padding: 0 }}>
          <div className="overflow-x-auto">
            <table className="w-full text-xs">
              <thead>
                <tr style={{ background: 'rgba(124,58,237,0.04)', borderBottom: '1px solid var(--border)' }}>
                  {['Number', 'Amount', 'Type', 'Billing Period', 'Next Billing', 'Status', ''].map(h => (
                    <th key={h} className="py-3.5 px-4 text-left label-caps whitespace-nowrap">{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {visible.map(r => {
                  const s = STATUS_STYLE[r.status] || STATUS_STYLE.Draft
                  return (
                    <tr key={r.id} style={{ borderBottom: '1px solid var(--border)' }}>
                      <td className="py-3.5 px-4 font-bold" style={{ color: '#a78bfa' }}>{r.number}</td>
                      <td className="py-3.5 px-4 font-bold" style={{ color: 'var(--text-h)' }}>{fmt(r.amount)}</td>
                      <td className="py-3.5 px-4 capitalize" style={{ color: 'var(--text-muted)' }}>{r.retainer_type}</td>
                      <td className="py-3.5 px-4 whitespace-nowrap" style={{ color: 'var(--text-muted)' }}>{fmtDate(r.billing_period_start)} – {fmtDate(r.billing_period_end)}</td>
                      <td className="py-3.5 px-4 whitespace-nowrap" style={{ color: 'var(--text-muted)' }}>{r.next_billing_date ? fmtDate(r.next_billing_date) : '—'}</td>
                      <td className="py-3.5 px-4">
                        <span className="px-2 py-0.5 rounded-lg text-[10px] font-bold" style={{ background: s.bg, color: s.color }}>{r.status}</span>
                      </td>
                      <td className="py-3.5 px-4">
                        <button onClick={() => setConfirmDelete(r)} className="btn-icon" title="Delete">
                          <Trash2 size={13} style={{ color: '#f87171' }} />
                        </button>
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
        title="New Retainer Invoice"
        footer={
          <button onClick={handleCreate} disabled={creating} className="btn-3d w-full">
            {creating ? 'Creating…' : 'Create Retainer'}
          </button>
        }
      >
        <div className="space-y-4">
          <FormField label="Amount" required>
            <Input type="number" min="0.01" placeholder="0.00" value={form.amount} onChange={e => sf('amount', e.target.value)} />
          </FormField>
          <FormField label="Retainer Type">
            <Select value={form.retainer_type} onChange={e => sf('retainer_type', e.target.value)}>
              <option value="monthly">Monthly</option>
              <option value="quarterly">Quarterly</option>
              <option value="yearly">Yearly</option>
            </Select>
          </FormField>
          <div className="grid grid-cols-2 gap-3">
            <FormField label="Period Start" required>
              <Input type="date" value={form.billing_period_start} onChange={e => sf('billing_period_start', e.target.value)} />
            </FormField>
            <FormField label="Period End" required>
              <Input type="date" value={form.billing_period_end} onChange={e => sf('billing_period_end', e.target.value)} />
            </FormField>
          </div>
          <FormField label="Next Billing Date" hint="optional">
            <Input type="date" value={form.next_billing_date} onChange={e => sf('next_billing_date', e.target.value)} />
          </FormField>
          <label className="flex items-center gap-2 text-xs font-semibold cursor-pointer" style={{ color: 'var(--text-h)' }}>
            <input type="checkbox" checked={form.auto_create} onChange={e => sf('auto_create', e.target.checked)} />
            Auto-generate the next invoice on schedule
          </label>
          {form.auto_create && (
            <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
              Note: auto-generation isn't wired up yet — this saves your preference, but invoices won't be created automatically until the scheduler is set up.
            </p>
          )}
        </div>
      </Drawer>

      {confirmDelete && (
        <ConfirmDialog
          title="Delete this retainer invoice?"
          message={`This will permanently delete ${confirmDelete.number}. This cannot be undone.`}
          confirmLabel="Delete"
          tone="danger"
          onConfirm={handleDelete}
          onCancel={() => setConfirmDelete(null)}
        />
      )}
    </div>
  )
}
