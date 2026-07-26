import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { X, FileText, Plus, AlertCircle, IndianRupee } from 'lucide-react'
import { projectApi, PROJECT_ACCENT, BILLING_TYPES } from '@/services/projectApi'

/**
 * Invoice Project — generate and review project invoice drafts.
 *
 * "Invoice Project" turns the project into a billable amount by its billing type
 * (fixed → project cost, project_hours → rate × logged hours, task_hours → sum of
 * billable-task hours × rate). When nothing is billable the backend refuses with a
 * clear reason, which we surface here rather than writing a zero invoice — the same
 * "produced nothing" outcome the spec describes for a $0 project.
 */

const money = v => '₹' + Number(v || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const fmtDate = d => d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'
const billingLabel = (k) => BILLING_TYPES.find(b => b.value === k)?.label || k

export default function ProjectInvoiceModal({ open, onClose, projectId, canManage }) {
  const qc = useQueryClient()
  const { data: invoices = [], isLoading } = useQuery({
    queryKey: ['project-invoices', projectId], queryFn: () => projectApi.invoices(projectId), enabled: open,
  })

  const generate = useMutation({
    mutationFn: () => projectApi.generateInvoice(projectId),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['project-invoices', projectId] }),
  })

  if (!open) return null

  return (
    <div className="fixed inset-0 z-[60] flex items-start justify-center p-4" style={{ background: 'rgba(0,0,0,0.5)' }} onClick={onClose}>
      <div className="w-full max-w-lg rounded-2xl mt-[8vh] max-h-[80vh] overflow-y-auto"
        style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card-3d)' }} onClick={e => e.stopPropagation()}>

        <header className="flex items-center justify-between px-5 py-4 sticky top-0" style={{ background: 'var(--bg-card)', borderBottom: '1px solid var(--border)' }}>
          <div className="flex items-center gap-2">
            <FileText size={16} style={{ color: PROJECT_ACCENT }} />
            <h2 className="font-bold" style={{ color: 'var(--text-h)', fontSize: 15 }}>Invoice Project</h2>
          </div>
          <button onClick={onClose} aria-label="Close"><X size={17} style={{ color: 'var(--text-muted)' }} /></button>
        </header>

        <div className="p-5 space-y-3">
          {canManage && (
            <button onClick={() => generate.mutate()} disabled={generate.isPending}
              className="w-full flex items-center justify-center gap-1.5 py-2.5 rounded-xl text-sm font-bold disabled:opacity-50"
              style={{ background: PROJECT_ACCENT, color: '#fff' }}>
              <Plus size={15} /> {generate.isPending ? 'Generating…' : 'Generate invoice'}
            </button>
          )}

          {generate.isError && (
            <p className="flex items-start gap-2 text-xs px-3 py-2.5 rounded-lg"
              style={{ background: 'color-mix(in srgb, var(--color-warning-500) 14%, transparent)', color: 'var(--color-warning-600)' }}>
              <AlertCircle size={14} className="shrink-0 mt-0.5" /> {generate.error?.message}
            </p>
          )}

          {isLoading && <div className="h-16 rounded-xl animate-pulse" style={{ background: 'var(--bg-input)' }} />}

          {!isLoading && invoices.length === 0 && (
            <div className="text-center py-8">
              <IndianRupee size={26} style={{ color: 'var(--text-muted)', margin: '0 auto 8px' }} />
              <p className="text-sm font-semibold" style={{ color: 'var(--text-h)' }}>No invoices yet</p>
              <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>
                {canManage ? 'Generate one from this project’s billing type.' : 'None have been generated for this project.'}
              </p>
            </div>
          )}

          {invoices.map(inv => (
            <div key={inv.id} className="rounded-xl p-3" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
              <div className="flex items-center justify-between mb-2">
                <div>
                  <span className="font-bold text-sm" style={{ color: 'var(--text-h)' }}>{inv.number}</span>
                  <span className="ml-2 text-[10px] font-bold px-1.5 py-0.5 rounded-full uppercase"
                    style={{ background: `color-mix(in srgb, ${PROJECT_ACCENT} 14%, transparent)`, color: PROJECT_ACCENT }}>{inv.status}</span>
                </div>
                <span className="font-black text-sm" style={{ color: 'var(--text-h)' }}>{money(inv.amount)}</span>
              </div>
              <p className="text-[11px] mb-2" style={{ color: 'var(--text-muted)' }}>
                {billingLabel(inv.billing_type)} · {fmtDate(inv.created_at)} · by {inv.creator?.name || '—'}
              </p>
              <div className="space-y-1">
                {(inv.line_items || []).map((li, i) => (
                  <div key={i} className="flex items-center justify-between text-[11px]" style={{ color: 'var(--text-body)' }}>
                    <span className="truncate pr-2">{li.description}{li.qty != null && li.qty !== 1 ? ` · ${li.qty} × ${money(li.rate)}` : ''}</span>
                    <span className="font-semibold shrink-0">{money(li.amount)}</span>
                  </div>
                ))}
              </div>
            </div>
          ))}
        </div>
      </div>
    </div>
  )
}
