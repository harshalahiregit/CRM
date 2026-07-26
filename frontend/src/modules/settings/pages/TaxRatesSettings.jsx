import { useState, useEffect } from 'react'
import { Plus, Trash2 } from 'lucide-react'
import { createPortal } from 'react-dom'
import api from '@/lib/api'
import { useToast } from '@/hooks/useToast'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import { invalidateTaxRates } from '@/hooks/useTaxRates'

const handleErr = (err) => { throw new Error(err?.response?.data?.message || err?.response?.data?.error || 'Something went wrong') }
const taxApi = {
  list: () => api.get('/sales/tax-rates').then(r => r.data).catch(handleErr),
  create: (d) => api.post('/sales/tax-rates', d).then(r => r.data).catch(handleErr),
  update: (id, d) => api.put(`/sales/tax-rates/${id}`, d).then(r => r.data).catch(handleErr),
  remove: (id) => api.delete(`/sales/tax-rates/${id}`).then(r => r.data).catch(handleErr),
}

export default function TaxRatesSettings() {
  const toast = useToast()
  const [rates, setRates] = useState(null)
  const [adding, setAdding] = useState(false)
  const [form, setForm] = useState({ name: '', rate: '' })
  const [confirmDel, setConfirmDel] = useState(null)

  const load = () => taxApi.list().then(setRates).catch(e => toast.error(e.message))
  useEffect(() => { load() }, [])

  const add = async () => {
    if (!form.name.trim() || form.rate === '') return toast.error('Name and rate required')
    try {
      await taxApi.create({ name: form.name, rate: Number(form.rate), active: true })
      invalidateTaxRates()
      setForm({ name: '', rate: '' }); setAdding(false); load()
      toast.success('Tax slab added')
    } catch (e) { toast.error(e.message) }
  }

  const toggle = async (r) => {
    try { await taxApi.update(r.id, { name: r.name, rate: r.rate, active: !r.active }); invalidateTaxRates(); load() }
    catch (e) { toast.error(e.message) }
  }

  const doDelete = async () => {
    try { await taxApi.remove(confirmDel.id); invalidateTaxRates(); load(); toast.success('Deleted') }
    catch (e) { toast.error(e.message) } finally { setConfirmDel(null) }
  }

  return (
    <div className="card-3d max-w-xl">
      <div className="flex items-center justify-between mb-1">
        <h2 className="font-bold text-base" style={{ color: 'var(--text-h)' }}>Tax Rates (GST slabs)</h2>
        <button onClick={() => setAdding(a => !a)} className="flex items-center gap-1 text-xs font-bold" style={{ color: 'var(--accent)' }}><Plus size={13} /> New slab</button>
      </div>
      <p className="text-xs mb-4" style={{ color: 'var(--text-muted)' }}>
        These slabs feed the Tax dropdown on proposal, estimate and invoice line items.
        Intra-state documents show the rate split as CGST + SGST; inter-state as IGST.
      </p>

      {adding && (
        <div className="flex gap-2 mb-4">
          <input className="input-3d text-sm flex-1" placeholder="Name (e.g. GST 18%)" value={form.name} onChange={e => setForm(p => ({ ...p, name: e.target.value }))} />
          <input type="number" min="0" max="100" step="0.01" className="input-3d text-sm w-28" placeholder="Rate %" value={form.rate} onChange={e => setForm(p => ({ ...p, rate: e.target.value }))} />
          <button onClick={add} className="px-4 py-2 rounded-xl text-xs font-bold text-white" style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>Add</button>
        </div>
      )}

      {!rates ? (
        <div className="skeleton h-24 rounded-xl" style={{ background: 'var(--border)' }} />
      ) : (
        <div className="divide-y" style={{ borderColor: 'var(--border)' }}>
          {rates.map(r => (
            <div key={r.id} className="flex items-center justify-between py-2.5">
              <div>
                <p className="text-sm font-semibold" style={{ color: 'var(--text-h)' }}>{r.name}</p>
                <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>{Number(r.rate)}% · intra-state: CGST {Number(r.rate) / 2}% + SGST {Number(r.rate) / 2}%</p>
              </div>
              <div className="flex items-center gap-2">
                <label className="flex items-center gap-1.5 text-[11px] font-bold cursor-pointer" style={{ color: r.active ? '#10b981' : 'var(--text-muted)' }}>
                  <input type="checkbox" checked={!!r.active} onChange={() => toggle(r)} /> {r.active ? 'Active' : 'Off'}
                </label>
                <button onClick={() => setConfirmDel(r)} className="p-1.5 rounded-lg hover:bg-[rgba(239,68,68,0.08)]"><Trash2 size={12} style={{ color: '#f87171' }} /></button>
              </div>
            </div>
          ))}
        </div>
      )}

      {confirmDel && createPortal(
        <ConfirmDialog title="Delete tax slab?" message={`Remove ${confirmDel.name}? Existing documents keep their saved rates.`} confirmLabel="Delete" onConfirm={doDelete} onCancel={() => setConfirmDel(null)} />,
        document.body,
      )}
    </div>
  )
}
