import { useState, useEffect } from 'react'
import Drawer from '@/components/ui/Drawer'
import { contractApi } from '@/services/contractApi'
import { useToast } from '@/hooks/useToast'
import { useClientOptions } from '@/hooks/useClientOptions'

const BLANK = { subject: '', client_id: '', contract_type_id: '', value: '', currency: 'INR', start_date: '', end_date: '', description: '', status: 'draft', renewal_notice_days: 30 }

export default function ContractDrawer({ open, onClose, contractId, onSaved }) {
  const toast = useToast()
  const clients = useClientOptions()
  const [form, setForm] = useState(BLANK)
  const [types, setTypes] = useState([])
  const [saving, setSaving] = useState(false)
  const sf = (k, v) => setForm(p => ({ ...p, [k]: v }))

  useEffect(() => {
    if (!open) return
    contractApi.types().then(setTypes).catch(() => {})
    if (contractId) {
      contractApi.get(contractId).then(c => setForm({
        subject: c.subject || '', client_id: c.client_id || '', contract_type_id: c.contract_type_id || '',
        value: c.value || '', currency: c.currency || 'INR', start_date: (c.start_date || '').slice(0, 10),
        end_date: (c.end_date || '').slice(0, 10), description: c.description || '', status: c.status || 'draft',
        renewal_notice_days: c.renewal_notice_days ?? 30,
      })).catch(e => toast.error(e.message))
    } else setForm(BLANK)
  }, [open, contractId])

  const save = async () => {
    if (!form.subject.trim() || !form.client_id) return toast.error('Subject & customer required')
    setSaving(true)
    try {
      const payload = { ...form, value: form.value === '' ? 0 : Number(form.value), contract_type_id: form.contract_type_id || null }
      if (contractId) await contractApi.update(contractId, payload)
      else await contractApi.create(payload)
      toast.success(contractId ? 'Contract updated' : 'Contract created')
      onSaved?.(); onClose()
    } catch (e) { toast.error(e.message) } finally { setSaving(false) }
  }

  return (
    <Drawer open={open} onClose={onClose} title={contractId ? 'Edit Contract' : 'New Contract'}
      footer={
        <div className="flex justify-end gap-2">
          <button onClick={onClose} className="px-4 py-2 rounded-xl text-sm font-semibold" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>Cancel</button>
          <button onClick={save} disabled={saving} className="px-5 py-2 rounded-xl text-sm font-bold text-white disabled:opacity-60" style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>{saving ? 'Saving…' : 'Save'}</button>
        </div>
      }>
      <div className="space-y-4">
        <div><label className="label">Subject *</label><input className="input-3d text-sm" value={form.subject} onChange={e => sf('subject', e.target.value)} placeholder="e.g. Annual Maintenance Contract" /></div>
        <div><label className="label">Customer *</label>
          <select className="input-3d text-sm" value={form.client_id} onChange={e => sf('client_id', e.target.value)}>
            <option value="">Select customer…</option>
            {clients.map(c => <option key={c.id} value={c.id}>{c.company || c.name}</option>)}
          </select>
        </div>
        <div className="grid grid-cols-2 gap-3">
          <div><label className="label">Type</label>
            <select className="input-3d text-sm" value={form.contract_type_id} onChange={e => sf('contract_type_id', e.target.value)}>
              <option value="">—</option>
              {types.map(t => <option key={t.id} value={t.id}>{t.name}</option>)}
            </select>
          </div>
          <div><label className="label">Value (₹)</label><input type="number" className="input-3d text-sm" value={form.value} onChange={e => sf('value', e.target.value)} /></div>
        </div>
        <div className="grid grid-cols-2 gap-3">
          <div><label className="label">Start Date</label><input type="date" className="input-3d text-sm" value={form.start_date} onChange={e => sf('start_date', e.target.value)} /></div>
          <div><label className="label">End Date</label><input type="date" className="input-3d text-sm" value={form.end_date} onChange={e => sf('end_date', e.target.value)} /></div>
        </div>
        <div className="grid grid-cols-2 gap-3">
          <div><label className="label">Status</label>
            <select className="input-3d text-sm" value={form.status} onChange={e => sf('status', e.target.value)}>
              {['draft', 'active', 'expired', 'terminated', 'renewed'].map(s => <option key={s} value={s}>{s[0].toUpperCase() + s.slice(1)}</option>)}
            </select>
          </div>
          <div><label className="label">Renewal Notice (days)</label><input type="number" className="input-3d text-sm" value={form.renewal_notice_days} onChange={e => sf('renewal_notice_days', e.target.value)} /></div>
        </div>
        <div><label className="label">Description</label><textarea rows={3} className="input-3d text-sm resize-none" value={form.description} onChange={e => sf('description', e.target.value)} /></div>
      </div>
    </Drawer>
  )
}
