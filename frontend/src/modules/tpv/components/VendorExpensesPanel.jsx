import { useState, useEffect, useCallback } from 'react'
import { Receipt } from 'lucide-react'
import { projectApi } from '@/services/projectApi'
import { fmtMoney } from '@/pages/vendor-portal/portalConstants'
import { SectionTable } from './VendorSectionTable'
import VendorAddModal from './VendorAddModal'

/**
 * Project expenses booked against this TPV vendor's projects.
 *
 * There is no vendor expense in this system and none was invented: an expense
 * belongs to a PROJECT (project_expenses.project_id) and the project carries the
 * vendor link, so this is TPV vendor → TPV projects → project expenses, read
 * through GET /projects/expenses?vendor_id=. The backend resolves the vendor to
 * project ids using the same visibility path as the Projects tab.
 *
 * Rows are not clickable: an expense has no detail screen of its own anywhere in
 * the app, and inventing one here would be a second place to maintain it.
 *
 * fmtMoney is the app's existing currency formatter — reused rather than a third
 * copy of Intl.NumberFormat.
 */
export function VendorExpenses({ vendorId, manage }) {
  const [rows, setRows] = useState([])
  const [total, setTotal] = useState(0)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [adding, setAdding] = useState(false)
  const [projects, setProjects] = useState([])

  const load = useCallback(() => {
    setLoading(true); setError(null)
    projectApi.vendorExpenses(vendorId)
      .then(d => { setRows(d?.rows ?? []); setTotal(Number(d?.total ?? 0)) })
      .catch(e => setError(e?.title || e?.message || 'Could not load expenses.'))
      .finally(() => setLoading(false))
  }, [vendorId])

  useEffect(() => {
    let live = true
    load()

    // An expense belongs to a PROJECT, so the picker offers this vendor's
    // projects only — there is no way to book spend against another vendor.
    projectApi.list({ vendor_id: vendorId })
      .then(d => { if (live) setProjects(d?.data ?? d ?? []) })
      .catch(() => {})

    return () => { live = false }
  }, [vendorId, load])

  // Stated as the full-set total so it never reads as a total of whatever the
  // search box happens to be showing; the header count reports "x of y".
  const hint = rows.length
    ? `${rows.length} expense${rows.length === 1 ? '' : 's'} across this vendor's projects · Total ${fmtMoney(total)}`
    : "Expenses booked against this vendor's projects."

  return (
    <>
    {adding && (
      <VendorAddModal
        title="Add Expense" submitLabel="Add expense"
        blockedReason={projects.length ? null : "This vendor has no projects yet. An expense is booked against a project, so add one on the Projects tab first."}
        onClose={() => setAdding(false)} onSaved={load}
        initial={{ billable: true }}
        onSubmit={({ project_id, ...data }) => projectApi.addExpense(project_id, data)}
        fields={[
          { name: 'project_id', label: 'Project', type: 'select', required: true,
            options: projects.map(p => ({ value: String(p.id), label: p.name })),
            help: "Only this vendor's projects." },
          { name: 'title', label: 'Description', required: true },
          { name: 'amount', label: 'Amount', type: 'number', required: true },
          { name: 'expense_date', label: 'Date', type: 'date', required: true },
          { name: 'category', label: 'Category' },
          { name: 'billable', label: 'Billable', type: 'checkbox' },
          { name: 'note', label: 'Note', type: 'textarea' },
        ]}
      />
    )}
    <SectionTable
      icon={Receipt} title="Expenses" loading={loading} error={error} rows={rows}
      hint={hint}
      onAdd={manage ? () => setAdding(true) : undefined} addLabel="Add Expense"
      searchFields={['title', 'category', 'note', 'project_name']}
      columns={[
        { key: 'expense_date', label: 'Date', render: e => e.expense_date ? new Date(e.expense_date).toLocaleDateString() : '—' },
        { key: 'title', label: 'Description', render: e => <span style={{ fontWeight: 700 }}>{e.title}</span> },
        { key: 'project_name', label: 'Project' },
        { key: 'category', label: 'Category' },
        {
          key: 'amount',
          label: 'Amount',
          render: e => <span style={{ fontWeight: 700, whiteSpace: 'nowrap' }}>{fmtMoney(e.amount)}</span>,
        },
        {
          key: 'billable',
          label: 'Billable',
          render: e => (
            <span style={{ fontSize: 11, fontWeight: 700, color: e.billable ? '#10b981' : 'var(--text-muted)' }}>
              {e.billable ? 'Billable' : 'Non-billable'}
            </span>
          ),
        },
        { key: 'creator', label: 'Added by', render: e => e.creator?.name || '—' },
      ]}
    />
    </>
  )
}
