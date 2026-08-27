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

import { PenLine } from 'lucide-react'
import { sangoeTrackApi } from '@/services/sangoeTrackApi'
import useTrackApprovals from './useTrackApprovals'
import { TrackHeader, TrackList, TrackCard, FieldGrid, Field, DecisionBar } from './TrackShell'

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

export default function TrackCorrections() {
  const { rows, loading, error, reload } = useTrackApprovals('raises')

  return (
    <div className="p-5 md:p-7 flex flex-col gap-5">
      <TrackHeader
        title="Attendance Corrections"
        subtitle="Requests to fix a clock-in or clock-out. Approving writes the attendance row and notifies the employee."
        onRefresh={reload}
        loading={loading}
      />

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
    </div>
  )
}
