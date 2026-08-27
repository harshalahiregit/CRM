/**
 * Expense claims awaiting a decision.
 *
 * The receipt is the point of this screen — an approver is checking that the
 * claim matches the paper. It opens full size rather than living as a thumbnail,
 * because a 30px image proves nothing.
 *
 * Amounts come back as numbers and are formatted here. SangoeTrack's own report
 * endpoint bakes the rupee symbol into strings, which is why that data cannot be
 * sorted; this payload does not, so it stays sortable.
 */

import { useState } from 'react'
import { Receipt as ReceiptIcon, ExternalLink } from 'lucide-react'
import { sangoeTrackApi } from '@/services/sangoeTrackApi'
import useTrackApprovals from './useTrackApprovals'
import useTrackHistory from './useTrackHistory'
import {
  TrackHeader, TrackList, TrackCard, FieldGrid, Field, DecisionBar,
  QueueTabs, HistoryFilters, HistoryPager, Outcome, DecidedBy, ExportButton,
} from './TrackShell'

const inr = n =>
  new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 2 })
    .format(Number(n) || 0)

const CSV = [
  { key: 'employee_name', label: 'Employee' },
  { key: 'title',         label: 'Claim' },
  { key: 'description',   label: 'Description' },
  // The raw number, not the formatted string — a spreadsheet has to be able to
  // sum this column, which it cannot do with a rupee symbol in the cell.
  { key: 'amount',        label: 'Amount' },
  { key: 'expense_date',  label: 'Spent on' },
  { key: 'status',        label: 'Outcome' },
  { key: 'decided_by',    label: 'Decided by' },
  { key: 'decided_at',    label: 'Decided at' },
  { key: 'admin_remarks', label: 'Remarks' },
  { key: 'receipt',       label: 'Receipt URL' },
  { key: 'submitted_on',  label: 'Submitted on' },
]

export default function TrackReimbursements() {
  const [tab, setTab] = useState('pending')
  const { rows, loading, error, reload } = useTrackApprovals('reimbursements')
  const past = useTrackHistory('reimbursements')
  const [viewing, setViewing] = useState(null)

  const total = rows.reduce((sum, r) => sum + (Number(r.amount) || 0), 0)

  return (
    <div className="p-5 md:p-7 flex flex-col gap-5">
      <TrackHeader
        title="Reimbursements"
        subtitle={
          rows.length
            ? `${rows.length} claim${rows.length === 1 ? '' : 's'} pending, ${inr(total)} in total.`
            : 'Expense claims waiting on a decision.'
        }
        onRefresh={tab === 'pending' ? reload : past.reload}
        loading={tab === 'pending' ? loading : past.loading}
      />

      <QueueTabs tab={tab} onChange={setTab} pendingCount={rows.length} />

      {tab === 'history' && (
        <>
          <HistoryFilters {...past} setFilter={past.setFilter} clear={past.clear}>
            <ExportButton
              filename={`reimbursements-${past.filters.from || "all"}-to-${past.filters.to || "now"}`}
              rows={past.rows} columns={CSV} total={past.meta?.total} />
          </HistoryFilters>

          {/* Totals across the WHOLE filtered set, sent by the server — a footer
              summing the 25 rows on screen would look like an answer and not be one. */}
          {past.meta?.totals && (
            <div className="flex flex-wrap gap-x-6 gap-y-1 rounded-xl px-3.5 py-2.5"
              style={{ border: '1px solid var(--border)', background: 'var(--bg-card)' }}>
              {[['Approved', 'approved', '#34d399'], ['Pending', 'pending', '#fbbf24'], ['Rejected', 'rejected', '#f87171']]
                .map(([label, key, fg]) => (
                  <div key={key}>
                    <span className="text-[10px] uppercase tracking-wider font-bold" style={{ color: 'var(--text-muted)' }}>{label} </span>
                    <span className="text-sm font-bold" style={{ color: fg, fontVariantNumeric: 'tabular-nums' }}>
                      {inr(past.meta.totals[key]?.amount)}
                    </span>
                    <span className="text-[11px]" style={{ color: 'var(--text-muted)' }}> · {past.meta.totals[key]?.count ?? 0}</span>
                  </div>
                ))}
            </div>
          )}

          <TrackList loading={past.loading} error={past.error} rows={past.rows} onRetry={past.reload} noun="claims">
            {past.rows.map(r => (
              <TrackCard key={r.id} who={r.employee_name} when={r.submitted_on}>
                <Outcome status={r.status} />
                <FieldGrid>
                  <Field label="Claim"    value={r.title} />
                  <Field label="Amount"   value={inr(r.amount)} />
                  <Field label="Spent on" value={r.expense_date} />
                  <Field label="What it was for" value={r.description} wide />
                </FieldGrid>
                {r.receipt && (
                  <button onClick={() => setViewing({ src: r.receipt, who: r.employee_name, title: r.title })}
                    className="rounded-lg text-xs font-semibold flex items-center gap-1.5 self-start"
                    style={{ padding: '6px 12px', background: 'var(--bg-input)', border: '1px solid var(--border)', color: '#a78bfa' }}>
                    <ReceiptIcon size={13} /> View receipt
                  </button>
                )}
                <DecidedBy by={r.decided_by} at={r.decided_at} remarks={r.admin_remarks} />
              </TrackCard>
            ))}
          </TrackList>

          <HistoryPager meta={past.meta} page={past.page} setPage={past.setPage} noun="claims" />
        </>
      )}

      {tab === 'pending' && <TrackList loading={loading} error={error} rows={rows} onRetry={reload} noun="claims">
        {rows.map(r => (
          <TrackCard key={r.id} who={r.employee_name} when={r.applied_on}>
            <FieldGrid>
              <Field label="Claim"  value={r.title} />
              <Field label="Amount" value={inr(r.amount)} />
              <Field label="Spent on" value={r.expense_date} />
              <Field label="What it was for" value={r.description} wide />
            </FieldGrid>

            <DecisionBar
              onDecide={(status, remark) => sangoeTrackApi.reimbursements.decide(r.id, status, remark)}
              onDone={reload}
              extra={
                r.receipt ? (
                  <button
                    onClick={() => setViewing({ src: r.receipt, who: r.employee_name, title: r.title })}
                    className="rounded-lg text-xs font-semibold flex items-center gap-1.5"
                    style={{ padding: '7px 12px', background: 'var(--bg-input)', border: '1px solid var(--border)', color: '#a78bfa' }}
                  >
                    <ReceiptIcon size={13} /> View receipt
                  </button>
                ) : (
                  // Worth saying. Approving a claim with no receipt is a
                  // decision, not an oversight to discover later.
                  <span className="text-[11px] font-semibold" style={{ color: '#fbbf24' }}>
                    No receipt attached
                  </span>
                )
              }
            />
          </TrackCard>
        ))}
      </TrackList>}

      {viewing && (
        <div
          role="dialog" aria-modal="true" aria-label={`Receipt for ${viewing.title}`}
          onClick={() => setViewing(null)}
          className="fixed inset-0 z-50 flex items-center justify-center p-6"
          style={{ background: 'rgba(0,0,0,0.8)' }}
        >
          <div onClick={e => e.stopPropagation()} className="flex flex-col items-center gap-3">
            <img src={viewing.src} alt={`Receipt for ${viewing.title}`}
              style={{ maxWidth: '92vw', maxHeight: '78vh', borderRadius: 10, background: '#fff' }} />
            <p className="text-sm font-semibold" style={{ color: '#fff' }}>
              {viewing.title} — {viewing.who}
            </p>
            <div className="flex gap-2">
              {/* A PDF or an odd format will not render in an <img>; the escape
                  hatch means the claim is still reviewable. */}
              <a href={viewing.src} target="_blank" rel="noopener noreferrer"
                className="rounded-lg text-xs font-semibold flex items-center gap-1.5"
                style={{ padding: '7px 14px', background: 'rgba(255,255,255,0.14)', color: '#fff' }}>
                <ExternalLink size={13} /> Open original
              </a>
              <button onClick={() => setViewing(null)}
                className="rounded-lg text-xs font-semibold"
                style={{ padding: '7px 14px', background: 'rgba(255,255,255,0.14)', color: '#fff' }}>
                Close
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
