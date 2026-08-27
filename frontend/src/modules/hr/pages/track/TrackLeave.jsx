/**
 * Leave applications awaiting a decision.
 *
 * SangoeTrack sends each request's leave balance alongside it — allocated, used,
 * remaining, and what would be left after approving. That is the fact the
 * decision actually turns on, so it is shown next to the buttons rather than
 * left for someone to look up.
 *
 * A request that exceeds the balance is flagged but NOT blocked: their service
 * marks it advisory, and unpaid or exceptional leave is a real thing an approver
 * is allowed to grant. The warning exists so it is a choice rather than an
 * accident.
 */

import { useState } from 'react'
import { AlertTriangle } from 'lucide-react'
import { sangoeTrackApi } from '@/services/sangoeTrackApi'
import useTrackApprovals from './useTrackApprovals'
import useTrackHistory from './useTrackHistory'
import {
  TrackHeader, TrackList, TrackCard, FieldGrid, Field, DecisionBar,
  QueueTabs, HistoryFilters, HistoryPager, Outcome, DecidedBy, ExportButton,
} from './TrackShell'

function BalanceStrip({ balance }) {
  if (!balance) return null

  // Some leave types are not counted against an allowance at all — saying so is
  // more use than showing four zeroes that look like an exhausted balance.
  if (!balance.tracked) {
    return (
      <p className="text-[11px] rounded-lg px-2.5 py-1.5" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)' }}>
        {balance.type ? `${balance.type} is not tracked against an allowance.` : 'Not tracked against an allowance.'}
      </p>
    )
  }

  const over = balance.exceeds

  return (
    <div className="rounded-lg px-3 py-2 flex flex-wrap items-center gap-x-5 gap-y-1"
      style={{
        background: over ? 'rgba(251,191,36,0.10)' : 'var(--bg-input)',
        border: `1px solid ${over ? 'rgba(251,191,36,0.35)' : 'transparent'}`,
      }}>
      {[
        ['Allocated', balance.allocated],
        ['Used',      balance.used],
        ['Remaining', balance.remaining],
        ['After this', balance.after_approval],
      ].map(([label, value]) => (
        <div key={label}>
          <span className="text-[10px] uppercase tracking-wider font-bold" style={{ color: 'var(--text-muted)' }}>{label} </span>
          <span className="text-sm font-bold"
            style={{
              fontVariantNumeric: 'tabular-nums',
              color: label === 'After this' && Number(value) < 0 ? '#fbbf24' : 'var(--text-h)',
            }}>
            {value}
          </span>
        </div>
      ))}
      {over && (
        <span className="text-[11px] font-semibold flex items-center gap-1" style={{ color: '#fbbf24' }}>
          <AlertTriangle size={12} />
          More than they have left — you can still approve it
        </span>
      )}
    </div>
  )
}

const CSV = [
  { key: 'employee_name',    label: 'Employee' },
  { key: 'leave_type',       label: 'Type' },
  { key: 'start_date',       label: 'From' },
  { key: 'end_date',         label: 'To' },
  { key: 'total_leave_days', label: 'Days' },
  { key: 'reason',           label: 'Their reason' },
  { key: 'status',           label: 'Outcome' },
  { key: 'remark',           label: 'Remark' },
  { key: 'applied_on',       label: 'Applied on' },
]

export default function TrackLeave() {
  const [tab, setTab] = useState('pending')
  const { rows, loading, error, reload } = useTrackApprovals('leaves')
  const past = useTrackHistory('leaves')

  return (
    <div className="p-5 md:p-7 flex flex-col gap-5">
      <TrackHeader
        title="Leave"
        subtitle="Applications waiting on a decision, with each employee's balance."
        onRefresh={tab === 'pending' ? reload : past.reload}
        loading={tab === 'pending' ? loading : past.loading}
      />

      <QueueTabs tab={tab} onChange={setTab} pendingCount={rows.length} />

      {tab === 'history' && (
        <>
          <HistoryFilters {...past} setFilter={past.setFilter} clear={past.clear}>
            <ExportButton
              filename={`leave-${past.filters.from || "all"}-to-${past.filters.to || "now"}`}
              rows={past.rows} columns={CSV} total={past.meta?.total} />
          </HistoryFilters>
          <TrackList loading={past.loading} error={past.error} rows={past.rows} onRetry={past.reload} noun="leave applications">
            {past.rows.map(l => (
              <TrackCard key={l.id} who={l.employee_name} when={l.applied_on}>
                <Outcome status={l.status} />
                <FieldGrid>
                  <Field label="Type"  value={l.leave_type} />
                  <Field label="From"  value={l.start_date} />
                  <Field label="To"    value={l.end_date} />
                  <Field label="Days"  value={l.total_leave_days} />
                  <Field label="Their reason" value={l.reason} wide />
                </FieldGrid>
                {/* Balance is not returned for past leave — it is a live figure,
                    and showing today's against last year's request would mislead. */}
                <DecidedBy remarks={l.remark} />
              </TrackCard>
            ))}
          </TrackList>
          <HistoryPager meta={past.meta} page={past.page} setPage={past.setPage} noun="leave applications" />
        </>
      )}

      {tab === 'pending' && <TrackList loading={loading} error={error} rows={rows} onRetry={reload} noun="leave applications">
        {rows.map(r => (
          <TrackCard key={r.id} who={r.employee_name} when={r.applied_on}>
            <FieldGrid>
              <Field label="Type"  value={r.leave_type} />
              <Field label="From"  value={r.start_date} />
              <Field label="To"    value={r.end_date} />
              <Field label="Days"  value={r.total_leave_days} />
              <Field label="Their reason" value={r.reason} wide />
            </FieldGrid>

            <BalanceStrip balance={r.balance} />

            <DecisionBar
              onDecide={(status, remark) => sangoeTrackApi.leave.decide(r.id, status, remark)}
              onDone={reload}
            />
          </TrackCard>
        ))}
      </TrackList>}
    </div>
  )
}
