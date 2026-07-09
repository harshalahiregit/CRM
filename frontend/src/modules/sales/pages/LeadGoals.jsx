import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { ArrowLeft, Plus, Trash2, Target, Users } from 'lucide-react'
import { useLeadGoals, useCreateLeadGoal, useDeleteLeadGoal } from '@/hooks/useLeadGoals'
import { useToast } from '@/hooks/useToast'
import Drawer from '@/components/ui/Drawer'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import EmptyState from '@/components/ui/EmptyState'
import FormField, { Input, Select } from '@/components/ui/FormField'

const TYPE_LABEL = { monthly: 'Monthly', quarterly: 'Quarterly', yearly: 'Yearly' }
const fmt = v => '₹' + Number(v || 0).toLocaleString('en-IN')
const fmtDate = d => d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'

const EMPTY_FORM = {
  user_id: '', type: 'monthly', period_start: '', period_end: '',
  target_count: '', target_value: '', incentive_type: 'none', incentive_value: '',
}

export default function LeadGoals() {
  const navigate = useNavigate()
  const toast = useToast()
  const { data: goals, isLoading } = useLeadGoals()
  const createGoal = useCreateLeadGoal()
  const deleteGoal = useDeleteLeadGoal()

  const [showDrawer, setShowDrawer] = useState(false)
  const [form, setForm] = useState(EMPTY_FORM)
  const [confirmDelete, setConfirmDelete] = useState(null)

  const sf = (k, v) => setForm(p => ({ ...p, [k]: v }))

  const handleCreate = () => {
    if (!form.period_start || !form.period_end) {
      return toast.error('Period start and end are required')
    }
    const payload = {
      ...form,
      user_id: form.user_id || null,
      target_count: form.target_count || null,
      target_value: form.target_value || null,
      incentive_value: form.incentive_value || null,
    }
    createGoal.mutate(payload, {
      onSuccess: () => { toast.success('Goal created'); setShowDrawer(false); setForm(EMPTY_FORM) },
      onError: (e) => toast.error(e.message),
    })
  }

  const handleDelete = () => {
    deleteGoal.mutate(confirmDelete.id, {
      onSuccess: () => { toast.success('Goal deleted'); setConfirmDelete(null) },
      onError: (e) => toast.error(e.message),
    })
  }

  return (
    <div className="space-y-6 animate-fade-in">

      {/* Header */}
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div className="flex items-center gap-3">
          <button onClick={() => navigate('/app/sales/leads')}
            className="w-9 h-9 rounded-xl flex items-center justify-center transition-colors hover:bg-[rgba(124,58,237,0.08)]"
            style={{ border: '1px solid var(--border)' }}>
            <ArrowLeft size={16} style={{ color: 'var(--text-muted)' }} />
          </button>
          <div>
            <p className="label-caps mb-1" style={{ color: '#a78bfa' }}>Sales & Revenue</p>
            <h1 className="text-2xl font-black" style={{ color: 'var(--text-h)', letterSpacing: '-0.03em' }}>Lead Goals &amp; Targets</h1>
          </div>
        </div>
        <button onClick={() => setShowDrawer(true)}
          className="flex items-center gap-2 px-5 py-2.5 rounded-2xl text-sm font-bold text-white transition-all hover:scale-[1.03]"
          style={{ background: 'linear-gradient(135deg,#9f67ff,#7C3AED)', boxShadow: '0 6px 20px rgba(124,58,237,0.4)' }}>
          <Plus size={15} /> New Goal
        </button>
      </div>

      {/* Goals list */}
      {isLoading ? (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          {[1, 2].map(i => <div key={i} className="skeleton h-40 rounded-2xl" style={{ background: 'var(--border)' }} />)}
        </div>
      ) : !goals?.length ? (
        <EmptyState
          icon={Target}
          title="No goals set yet"
          description="Set monthly, quarterly, or yearly targets for your team or individual sales staff."
          action={<button onClick={() => setShowDrawer(true)} className="btn-3d">Create your first goal</button>}
        />
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          {goals.map(goal => {
            const countPct = goal.target_count ? Math.min(100, Math.round((goal.achieved_count / goal.target_count) * 100)) : null
            const valuePct = goal.target_value ? Math.min(100, Math.round((goal.achieved_value / goal.target_value) * 100)) : null
            return (
              <div key={goal.id} className="card-3d" style={{ padding: '20px' }}>
                <div className="flex items-start justify-between mb-3">
                  <div>
                    <div className="flex items-center gap-2">
                      <span className="text-[11px] font-bold px-2 py-0.5 rounded-lg" style={{ background: 'rgba(124,58,237,0.1)', color: '#a78bfa' }}>
                        {TYPE_LABEL[goal.type] || goal.type}
                      </span>
                      <span className="inline-flex items-center gap-1 text-[11px]" style={{ color: 'var(--text-muted)' }}>
                        <Users size={11} /> {goal.user?.name || 'Team-wide'}
                      </span>
                    </div>
                    <p className="text-xs mt-1.5" style={{ color: 'var(--text-muted)' }}>
                      {fmtDate(goal.period_start)} – {fmtDate(goal.period_end)}
                    </p>
                  </div>
                  <button onClick={() => setConfirmDelete(goal)} className="btn-icon">
                    <Trash2 size={14} style={{ color: '#f87171' }} />
                  </button>
                </div>

                {countPct !== null && (
                  <div className="mb-3">
                    <div className="flex justify-between text-xs mb-1">
                      <span style={{ color: 'var(--text-muted)' }}>Leads: {goal.achieved_count} / {goal.target_count}</span>
                      <span className="font-bold" style={{ color: 'var(--text-h)' }}>{countPct}%</span>
                    </div>
                    <div className="h-1.5 rounded-full overflow-hidden" style={{ background: 'var(--border)' }}>
                      <div className="h-full rounded-full" style={{ width: `${countPct}%`, background: 'linear-gradient(90deg,#a78bfa,#7C3AED)' }} />
                    </div>
                  </div>
                )}

                {valuePct !== null && (
                  <div className="mb-3">
                    <div className="flex justify-between text-xs mb-1">
                      <span style={{ color: 'var(--text-muted)' }}>Value: {fmt(goal.achieved_value)} / {fmt(goal.target_value)}</span>
                      <span className="font-bold" style={{ color: 'var(--text-h)' }}>{valuePct}%</span>
                    </div>
                    <div className="h-1.5 rounded-full overflow-hidden" style={{ background: 'var(--border)' }}>
                      <div className="h-full rounded-full" style={{ width: `${valuePct}%`, background: 'linear-gradient(90deg,#10b981,#059669)' }} />
                    </div>
                  </div>
                )}

                {goal.incentive_type && goal.incentive_type !== 'none' && (
                  <p className="text-xs mt-2 pt-2" style={{ color: 'var(--text-muted)', borderTop: '1px solid var(--border)' }}>
                    Incentive: {goal.incentive_type === 'percentage' ? `${goal.incentive_value}%` : fmt(goal.incentive_value)}
                  </p>
                )}
              </div>
            )
          })}
        </div>
      )}

      {/* Create drawer */}
      <Drawer
        open={showDrawer}
        onClose={() => setShowDrawer(false)}
        title="New Goal"
        footer={
          <button onClick={handleCreate} disabled={createGoal.isPending} className="btn-3d w-full">
            {createGoal.isPending ? 'Creating…' : 'Create Goal'}
          </button>
        }
      >
        <div className="space-y-4">
          <FormField label="Type" required>
            <Select value={form.type} onChange={e => sf('type', e.target.value)}>
              <option value="monthly">Monthly</option>
              <option value="quarterly">Quarterly</option>
              <option value="yearly">Yearly</option>
            </Select>
          </FormField>
          <div className="grid grid-cols-2 gap-3">
            <FormField label="Period Start" required>
              <Input type="date" value={form.period_start} onChange={e => sf('period_start', e.target.value)} />
            </FormField>
            <FormField label="Period End" required>
              <Input type="date" value={form.period_end} onChange={e => sf('period_end', e.target.value)} />
            </FormField>
          </div>
          <FormField label="Target Lead Count" hint="optional">
            <Input type="number" min="0" placeholder="e.g. 50" value={form.target_count} onChange={e => sf('target_count', e.target.value)} />
          </FormField>
          <FormField label="Target Pipeline Value" hint="optional, ₹">
            <Input type="number" min="0" placeholder="e.g. 500000" value={form.target_value} onChange={e => sf('target_value', e.target.value)} />
          </FormField>
          <FormField label="Incentive Type">
            <Select value={form.incentive_type} onChange={e => sf('incentive_type', e.target.value)}>
              <option value="none">None</option>
              <option value="fixed">Fixed Amount</option>
              <option value="percentage">Percentage</option>
            </Select>
          </FormField>
          {form.incentive_type !== 'none' && (
            <FormField label="Incentive Value" hint={form.incentive_type === 'percentage' ? '%' : '₹'}>
              <Input type="number" min="0" value={form.incentive_value} onChange={e => sf('incentive_value', e.target.value)} />
            </FormField>
          )}
          <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
            Leave the assignee blank to set a team-wide goal.
          </p>
        </div>
      </Drawer>

      {confirmDelete && (
        <ConfirmDialog
          title="Delete this goal?"
          message={`This will remove the ${TYPE_LABEL[confirmDelete.type]?.toLowerCase()} goal for ${confirmDelete.user?.name || 'the team'}.`}
          confirmLabel="Delete"
          tone="danger"
          onConfirm={handleDelete}
          onCancel={() => setConfirmDelete(null)}
        />
      )}
    </div>
  )
}
