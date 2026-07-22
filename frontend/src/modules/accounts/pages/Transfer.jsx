import { useState } from 'react'
import { useQuery, useMutation } from '@tanstack/react-query'
import { ArrowLeftRight } from 'lucide-react'
import { accountsApi } from '@/services/accountsApi'
import { useToast } from '@/hooks/useToast'
import FormField, { Input, Select, Textarea } from '@/components/ui/FormField'

/**
 * Fund transfer between two asset ledgers (old-CRM "Transfer" — the feature
 * the reference build's sidebar mislabels "Convert" due to a language-key
 * collision with its Recruitment module; see accounts memory notes). A
 * friendlier front door onto a Contra voucher: Dr destination, Cr source.
 */
export default function Transfer() {
  const toast = useToast()
  const [form, setForm] = useState({ from_ledger_id: '', to_ledger_id: '', date: new Date().toISOString().slice(0, 10), amount: '', narration: '' })

  const { data: ledgers = [] } = useQuery({ queryKey: ['accounts', 'ledgers', 'options'], queryFn: accountsApi.ledgers.options })
  const assetLedgers = ledgers.filter(l => l.group?.nature === 'asset')

  const sf = (k, v) => setForm(p => ({ ...p, [k]: v }))

  const submit = useMutation({
    mutationFn: () => accountsApi.vouchers.transfer({ ...form, amount: Number(form.amount) }),
    onSuccess: (v) => { toast.success(`Transferred — voucher ${v.number}`); setForm({ from_ledger_id: '', to_ledger_id: '', date: new Date().toISOString().slice(0, 10), amount: '', narration: '' }) },
    onError: (e) => toast.error(e.message),
  })

  const valid = form.from_ledger_id && form.to_ledger_id && form.from_ledger_id !== form.to_ledger_id && Number(form.amount) > 0 && form.date

  return (
    <div className="max-w-lg space-y-6 animate-fade-in">
      <div className="flex items-center gap-3">
        <div className="w-10 h-10 rounded-2xl flex items-center justify-center" style={{ background: 'rgba(124,58,237,0.12)' }}>
          <ArrowLeftRight size={18} style={{ color: '#a78bfa' }} />
        </div>
        <div>
          <h1 className="text-xl font-black" style={{ color: 'var(--text-h)' }}>Transfer Funds</h1>
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Move money between two of your own accounts — posts a Contra voucher</p>
        </div>
      </div>

      <div className="kpi-3d space-y-4">
        <FormField label="From Account" required>
          <Select value={form.from_ledger_id} onChange={e => sf('from_ledger_id', e.target.value)}>
            <option value="">Select…</option>
            {assetLedgers.map(l => <option key={l.id} value={l.id}>{l.name}</option>)}
          </Select>
        </FormField>
        <FormField label="To Account" required>
          <Select value={form.to_ledger_id} onChange={e => sf('to_ledger_id', e.target.value)}>
            <option value="">Select…</option>
            {assetLedgers.filter(l => String(l.id) !== String(form.from_ledger_id)).map(l => <option key={l.id} value={l.id}>{l.name}</option>)}
          </Select>
        </FormField>
        <div className="grid grid-cols-2 gap-3">
          <FormField label="Date" required><Input type="date" value={form.date} onChange={e => sf('date', e.target.value)} /></FormField>
          <FormField label="Amount" required><Input type="number" step="0.01" value={form.amount} onChange={e => sf('amount', e.target.value)} /></FormField>
        </div>
        <FormField label="Narration"><Textarea rows={2} value={form.narration} onChange={e => sf('narration', e.target.value)} placeholder="e.g. Moved to HDFC current account" /></FormField>

        <button onClick={() => submit.mutate()} disabled={!valid || submit.isPending} className="btn-3d w-full disabled:opacity-50">
          {submit.isPending ? 'Transferring…' : 'Transfer'}
        </button>
      </div>
    </div>
  )
}
