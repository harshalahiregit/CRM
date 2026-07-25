import { useState, useEffect } from 'react'
import { useInr } from '@/modules/accounts/useMoney'
import { useParams, Link } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, Loader2, Plus, X, Save } from 'lucide-react'
import { accountsApi } from '@/services/accountsApi'

import { useToast } from '@/hooks/useToast'
import { Select, Input } from '@/components/ui/FormField'

export default function BudgetDetail() {
  const inr = useInr()
  const { id } = useParams()
  const [tab, setTab] = useState('Targets')
  const { data, isLoading } = useQuery({ queryKey: ['accounts', 'budget', id], queryFn: () => accountsApi.budgets.get(id) })

  if (isLoading || !data) return <div className="flex justify-center py-16"><Loader2 className="animate-spin" style={{ color: 'var(--text-muted)' }} /></div>

  return (
    <div className="space-y-5 animate-fade-in max-w-4xl">
      <Link to="/app/accounts/budgets" className="flex items-center gap-1.5 text-sm font-semibold" style={{ color: 'var(--text-muted)' }}><ArrowLeft size={15} /> Budgets</Link>
      <h1 className="text-xl font-black" style={{ color: 'var(--text-h)' }}>{data.budget?.name}</h1>

      <div className="flex gap-1" style={{ borderBottom: '1px solid var(--border)' }}>
        {['Targets', 'Budget vs Actual'].map(t => (
          <button key={t} onClick={() => setTab(t)} className="px-4 py-2.5 text-sm font-bold -mb-px"
            style={{ color: tab === t ? '#a78bfa' : 'var(--text-muted)', borderBottom: tab === t ? '2px solid #a78bfa' : '2px solid transparent' }}>{t}</button>
        ))}
      </div>

      {tab === 'Targets' ? <TargetsTab budgetId={id} initial={data.lines} /> : <VsActualTab budgetId={id} />}
    </div>
  )
}

function TargetsTab({ budgetId, initial }) {
  const inr = useInr()
  const toast = useToast(); const qc = useQueryClient()
  const [rows, setRows] = useState([])
  useEffect(() => { setRows((initial || []).map(l => ({ ledger_id: String(l.ledger_id), ledger: l.ledger, annual_amount: l.annual_amount }))) }, [initial])

  const { data: ledgers = [] } = useQuery({ queryKey: ['accounts', 'ledgers', 'options'], queryFn: accountsApi.ledgers.options })
  const save = useMutation({
    mutationFn: () => accountsApi.budgets.saveLines(budgetId, rows.filter(r => r.ledger_id && r.annual_amount !== '').map(r => ({ ledger_id: Number(r.ledger_id), annual_amount: Number(r.annual_amount) }))),
    onSuccess: () => { toast.success('Targets saved'); qc.invalidateQueries({ queryKey: ['accounts', 'budget', budgetId] }); qc.invalidateQueries({ queryKey: ['accounts', 'budgets'] }) },
    onError: (e) => toast.error(e.message),
  })

  const usedIds = rows.map(r => String(r.ledger_id))
  const available = ledgers.filter(l => !usedIds.includes(String(l.id)))
  const addRow = () => setRows(rs => [...rs, { ledger_id: '', ledger: '', annual_amount: '' }])
  const setRow = (i, k, v) => setRows(rs => rs.map((r, idx) => idx === i ? { ...r, [k]: v } : r))
  const total = rows.reduce((s, r) => s + (parseFloat(r.annual_amount) || 0), 0)

  return (
    <div className="space-y-4">
      <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Enter an annual target per ledger — it's spread evenly across the 12 months of the financial year.</p>
      <div className="table-wrapper">
        <table className="table">
          <thead><tr><th>Ledger</th><th style={{ textAlign: 'right', width: 200 }}>Annual target</th><th style={{ width: 40 }}></th></tr></thead>
          <tbody>
            {rows.map((r, i) => (
              <tr key={i}>
                <td>
                  {r.ledger && r.ledger_id ? <span style={{ color: 'var(--text-h)', fontWeight: 600 }}>{r.ledger}</span> : (
                    <Select value={r.ledger_id} onChange={e => { const l = ledgers.find(x => String(x.id) === e.target.value); setRow(i, 'ledger_id', e.target.value); setRow(i, 'ledger', l?.name || '') }}>
                      <option value="">Select ledger…</option>
                      {available.concat(ledgers.filter(l => String(l.id) === String(r.ledger_id))).map(l => <option key={l.id} value={l.id}>{l.name}</option>)}
                    </Select>
                  )}
                </td>
                <td style={{ textAlign: 'right' }}><Input type="number" step="0.01" className="text-right" value={r.annual_amount} onChange={e => setRow(i, 'annual_amount', e.target.value)} /></td>
                <td style={{ textAlign: 'right' }}><button onClick={() => setRows(rs => rs.filter((_, idx) => idx !== i))} style={{ color: '#f87171' }}><X size={15} /></button></td>
              </tr>
            ))}
            <tr><td colSpan={3}><button className="text-xs font-bold flex items-center gap-1" style={{ color: '#a78bfa' }} onClick={addRow}><Plus size={13} /> Add ledger</button></td></tr>
            <tr style={{ borderTop: '2px solid var(--border)' }}><td style={{ fontWeight: 800, color: 'var(--text-h)' }}>Total</td><td style={{ textAlign: 'right', fontWeight: 800, color: 'var(--text-h)' }}>{inr(total)}</td><td></td></tr>
          </tbody>
        </table>
      </div>
      <div className="flex justify-end">
        <button className="btn-3d flex items-center gap-2" disabled={save.isPending} onClick={() => save.mutate()}>{save.isPending ? <Loader2 size={15} className="animate-spin" /> : <Save size={15} />} Save targets</button>
      </div>
    </div>
  )
}

function VsActualTab({ budgetId }) {
  const inr = useInr()
  const { data, isLoading } = useQuery({ queryKey: ['accounts', 'budget-vs-actual', budgetId], queryFn: () => accountsApi.budgets.vsActual(budgetId) })
  if (isLoading || !data) return <div className="flex justify-center py-10"><Loader2 className="animate-spin" style={{ color: 'var(--text-muted)' }} /></div>
  const t = data.totals

  return (
    <div className="table-wrapper">
      <table className="table">
        <thead><tr><th>Ledger</th><th style={{ textAlign: 'right' }}>Budget</th><th style={{ textAlign: 'right' }}>Actual</th><th style={{ textAlign: 'right' }}>Variance</th><th style={{ textAlign: 'right' }}>Used</th></tr></thead>
        <tbody>
          {(data.rows || []).length === 0 && <tr><td colSpan={5} style={{ color: 'var(--text-muted)' }}>No targets set yet.</td></tr>}
          {(data.rows || []).map((r, i) => (
            <tr key={i}>
              <td style={{ color: 'var(--text-h)', fontWeight: 600 }}>{r.ledger}</td>
              <td style={{ textAlign: 'right' }}>{inr(r.budget)}</td>
              <td style={{ textAlign: 'right' }}>{inr(r.actual)}</td>
              <td style={{ textAlign: 'right', color: r.variance < 0 ? '#f87171' : '#10b981' }}>{inr(r.variance)}</td>
              <td style={{ textAlign: 'right', color: (r.used_percent ?? 0) > 100 ? '#f87171' : 'var(--text-muted)' }}>{r.used_percent == null ? '—' : `${r.used_percent}%`}</td>
            </tr>
          ))}
          {t && (
            <tr style={{ borderTop: '2px solid var(--border)' }}>
              <td style={{ fontWeight: 800, color: 'var(--text-h)' }}>Total</td>
              <td style={{ textAlign: 'right', fontWeight: 800 }}>{inr(t.budget)}</td>
              <td style={{ textAlign: 'right', fontWeight: 800 }}>{inr(t.actual)}</td>
              <td style={{ textAlign: 'right', fontWeight: 800, color: t.variance < 0 ? '#f87171' : '#10b981' }}>{inr(t.variance)}</td>
              <td></td>
            </tr>
          )}
        </tbody>
      </table>
    </div>
  )
}
