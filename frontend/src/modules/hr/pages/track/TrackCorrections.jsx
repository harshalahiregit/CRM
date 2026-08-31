/**
 * Attendance correction requests from SangoeTrack.
 *
 * Approving one here does three things on their side, not one: it marks the
 * request approved, it creates or updates the real attendance row, and it pushes
 * a notification to the employee's phone. That chain is the whole reason these
 * decisions go through their API rather than anything closer to the data — a
 * direct write would fix the row and leave the employee never knowing.
 *
 * This is also the only way attendance gets edited at all today. SangoeTrack has
 * no admin endpoint for changing an attendance row directly, so a forgotten
 * clock-out is corrected by the employee raising it and someone approving here.
 */

import { useState } from 'react'
import { sangoeTrackApi } from '@/services/sangoeTrackApi'
import useTrackApprovals from './useTrackApprovals'
import useTrackHistory from './useTrackHistory'
import {
  TrackHeader, TrackList, TrackCard, FieldGrid, Field, DecisionBar,
  QueueTabs, HistoryFilters, HistoryPager, Outcome, DecidedBy, ExportButton,
} from './TrackShell'

/** '09:15:00' → '09:15'. */
const hhmm = v => (v ? String(v).slice(0, 5) : null)

/** Hours between two times, tolerating a shift that ended after midnight. */
function span(from, to) {
  if (!from || !to) return null
  const [fh, fm] = from.split(':').map(Number)
  const [th, tm] = to.split(':').map(Number)
  let mins = (th * 60 + tm) - (fh * 60 + fm)
  if (mins < 0) mins += 24 * 60          // crossed midnight
  return `${Math.floor(mins / 60)}h ${String(mins % 60).padStart(2, '0')}m`
}

const CSV = [
  { key: 'employee_name',   label: 'Employee' },
  { key: 'attendance_date', label: 'Date' },
  { key: 'login_time',      label: 'Clock in' },
  { key: 'logout_time',     label: 'Clock out' },
  { key: 'reason',          label: 'Their reason' },
  { key: 'status',          label: 'Outcome' },
  { key: 'decided_by',      label: 'Decided by' },
  { key: 'decided_at',      label: 'Decided at' },
  { key: 'admin_remarks',   label: 'Remarks' },
  { key: 'applied_on',      label: 'Applied on' },
]

export default function TrackCorrections() {
  const [tab, setTab] = useState('pending')
  const { rows, loading, error, reload } = useTrackApprovals('raises')
  const past = useTrackHistory('corrections')

  return (
    <div className="p-5 md:p-7 flex flex-col gap-5">
      <TrackHeader
        title="Attendance Corrections"
        subtitle="Requests to fix a clock-in or clock-out. Approving writes the attendance row and notifies the employee."
        onRefresh={tab === 'pending' ? reload : past.reload}
        loading={tab === 'pending' ? loading : past.loading}
      />

      <QueueTabs tab={tab} onChange={setTab} pendingCount={rows.length} />

      {tab === 'pending' ? (
        <TrackList loading={loading} error={error} rows={rows} onRetry={reload} noun="corrections">
          {rows.map(r => (
            <TrackCard key={r.id} who={r.employee_name} when={r.applied_on}>
              <FieldGrid>
                <Field label="Date"      value={r.attendance_date} />
                <Field label="Clock in"  value={hhmm(r.login_time)} />
                <Field label="Clock out" value={hhmm(r.logout_time)} />
                <Field label="Total"     value={span(r.login_time, r.logout_time)} />
                <Field label="Their reason" value={r.reason} wide />
              </FieldGrid>

              <DecisionBar
                onDecide={(status, remark) => sangoeTrackApi.corrections.decide(r.id, status, remark)}
                onDone={reload}
              />
            </TrackCard>
          ))}
        </TrackList>
      ) : (
        <>
          <HistoryFilters {...past} setFilter={past.setFilter} clear={past.clear}>
            <ExportButton
              filename={`corrections-${past.filters.from || "all"}-to-${past.filters.to || "now"}`}
              rows={past.rows} columns={CSV} total={past.meta?.total} />
          </HistoryFilters>

          <TrackList loading={past.loading} error={past.error} rows={past.rows} onRetry={past.reload} noun="corrections">
            {past.rows.map(r => (
              <TrackCard key={r.id} who={r.employee_name} when={r.applied_on}>
                <Outcome status={r.status} />
                <FieldGrid>
                  <Field label="Date"      value={r.attendance_date} />
                  <Field label="Clock in"  value={hhmm(r.login_time)} />
                  <Field label="Clock out" value={hhmm(r.logout_time)} />
                  <Field label="Total"     value={span(r.login_time, r.logout_time)} />
                  <Field label="Their reason" value={r.reason} wide />
                </FieldGrid>
                {/* No DecisionBar — this is a record, not a worklist. */}
                <DecidedBy by={r.decided_by} at={r.decided_at} remarks={r.admin_remarks} />
              </TrackCard>
            ))}
          </TrackList>

          <HistoryPager meta={past.meta} page={past.page} setPage={past.setPage} noun="corrections" />
        </>
      )}
    </div>
  )
}
