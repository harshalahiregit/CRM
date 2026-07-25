import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { Plus, PieChart, Loader2, Trash2 } from 'lucide-react'
import { accountsApi } from '@/services/accountsApi'
import { fmtDate } from '@/modules/accounts/format'
import { useInr } from '@/modules/accounts/useMoney'
import { useToast } from '@/hooks/useToast'
import DataTable from '@/components/ui/DataTable'
import Drawer from '@/components/ui/Drawer'
import FormField, { Input, Select } from '@/components/ui/FormField'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import { GhostButton } from '@/modules/accounts/components/Btn'

export default function Budgets() {
  const inr = useInr()
  const toast = useToast(); const qc = useQueryClient(); const navigate = useNavigate()
  const [drawer, setDrawer] = useState(false)
  const [confirm, setConfirm] = useState(null)
  const [form, setForm] = useState({ name: '', financial_year_id: '' })

  const { data: budgets = [], isLoading } = useQuery({ queryKey: ['accounts', 'budgets'], queryFn: accountsApi.budgets.list })
  const { data: years = [] } = useQuery({ queryKey: ['accounts', 'financial-years'], queryFn: accountsApi.financialYears.list })
  const inv = () => qc.invalidateQueries({ queryKey: ['accounts', 'budgets'] })

  const create = useMutation({
    mutationFn: (f) => accountsApi.budgets.create(f),
    onSuccess: (b) => { toast.success('Budget created'); setDrawer(false); inv(); navigate(`/app/accounts/budgets/${b.id}`) },
    onError: (e) => toast.error(e.message),
  })
  const remove = useMutation({
    mutationFn: (id) => accountsApi.budgets.remove(id),
    onSuccess: () => { toast.success('Budget deleted'); setConfirm(null); inv() },
    onError: (e) => { toast.error(e.message); setConfirm(null) },
  })

  const columns = [
    { key: 'name', label: 'Budget', sortable: true, render: (r) => <span className="font-semibold" style={{ color: 'var(--text-h)' }}>{r.name}</span> },
    { key: 'fy', label: 'Financial year', render: (r) => r.financial_year ? `${fmtDate(r.financial_year.start_date)} – ${fmtDate(r.financial_year.end_date)}` : '—' },
    { key: 'budgeted_total', label: 'Budgeted', align: 'right', render: (r) => inr(r.budgeted_total) },
    { key: 'actions', label: '', align: 'right', render: (r) => (
      <button className="p-1.5 rounded-lg" title="Delete" onClick={(e) => { e.stopPropagation(); setConfirm(r) }} style={{ color: '#f87171' }}><Trash2 size={14} /></button>
    ) },
  ]

  return (
    <div className="space-y-5 animate-fade-in">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <div className="w-10 h-10 rounded-2xl flex items-center justify-center" style={{ background: 'rgba(124,58,237,0.12)' }}><PieChart size={18} style={{ color: '#a78bfa' }} /></div>
          <div><h1 className="text-xl font-black" style={{ color: 'var(--text-h)' }}>Budgets</h1><p className="text-xs" style={{ color: 'var(--text-muted)' }}>Set targets per ledger; compare against actuals from the ledger</p></div>
        </div>
        <button className="btn-3d flex items-center gap-2" onClick={() => setDrawer(true)}><Plus size={15} /> New Budget</button>
      </div>

      {isLoading ? <div className="flex justify-center py-10"><Loader2 className="animate-spin" style={{ color: 'var(--text-muted)' }} /></div>
        : <DataTable columns={columns} rows={budgets} onRowClick={(r) => navigate(`/app/accounts/budgets/${r.id}`)} />}

      {drawer && (
        <Drawer open onClose={() => setDrawer(false)} title="New Budget"
          footer={
            <div className="flex gap-3">
              <GhostButton className="flex-1" onClick={() => setDrawer(false)}>Cancel</GhostButton>
              <button className="btn-3d flex-1 flex items-center justify-center gap-2"
                disabled={!form.name.trim() || !form.financial_year_id || create.isPending}
                onClick={() => create.mutate({ name: form.name, financial_year_id: Number(form.financial_year_id) })}>
                {create.isPending && <Loader2 size={15} className="animate-spin" />} Create
              </button>
            </div>
          }>
          <div className="space-y-4">
            <FormField label="Name" required><Input value={form.name} onChange={e => setForm(f => ({ ...f, name: e.target.value }))} placeholder="FY 2026-27 Operating Budget" /></FormField>
            <FormField label="Financial year" required>
              <Select value={form.financial_year_id} onChange={e => setForm(f => ({ ...f, financial_year_id: e.target.value }))}>
                <option value="">Select…</option>
                {years.map(y => <option key={y.id} value={y.id}>{y.start_date?.slice(0, 10)} – {y.end_date?.slice(0, 10)}</option>)}
              </Select>
            </FormField>
          </div>
        </Drawer>
      )}

      {confirm && (
        <ConfirmDialog title="Delete budget?" message={`"${confirm.name}" and its targets will be removed.`} confirmLabel="Delete"
          onCancel={() => setConfirm(null)} onConfirm={() => remove.mutate(confirm.id)} />
      )}
    </div>
  )
}
