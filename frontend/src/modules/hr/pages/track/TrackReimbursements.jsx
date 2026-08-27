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
import { TrackHeader, TrackList, TrackCard, FieldGrid, Field, DecisionBar } from './TrackShell'

const inr = n =>
  new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 2 })
    .format(Number(n) || 0)

export default function TrackReimbursements() {
  const { rows, loading, error, reload } = useTrackApprovals('reimbursements')
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
        onRefresh={reload}
        loading={loading}
      />

      <TrackList loading={loading} error={error} rows={rows} onRetry={reload} noun="claims">
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
      </TrackList>

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
