import { History, TrendingUp, TrendingDown, Minus, FileText, RotateCcw } from 'lucide-react'
import { S } from './MedicalBits'

/**
 * Every past checkup for one person, newest at the top.
 *
 * A single latest result answers "can this person work today". It does not
 * answer the question a doctor actually asks in front of the patient — is this
 * getting better or worse, has this been seen before, was there a re-examination
 * and why. That needs the sequence, which is why this is a timeline rather than
 * a row.
 *
 * Movement is shown against the examination BEFORE it rather than against the
 * best or the average, because that is the comparison a clinician makes: what
 * has changed since I last saw you.
 *
 * Capped by the server at ten. Somebody examined monthly for a decade would
 * otherwise send a hundred and twenty records to a panel that shows the recent
 * ones — and the recent ones are what the question is about.
 */
export default function ExamHistoryTimeline({ history, onOpenCertificate, title = 'Medical examination history' }) {
  const rows = Array.isArray(history) ? history : (history?.records ?? history?.items ?? [])

  return (
    <div className="pr-glass" style={{ padding: 16 }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 12 }}>
        <History size={15} style={{ color: '#a78bfa' }} />
        <h3 style={{ margin: 0, fontSize: 13.5, fontWeight: 800, color: 'var(--text-h)' }}>{title}</h3>
        {rows.length > 0 && (
          <span style={{ marginLeft: 'auto', fontSize: 11, color: 'var(--text-muted)' }}>
            {rows.length === 10 ? 'last 10' : `${rows.length} on record`}
          </span>
        )}
      </div>

      {rows.length === 0 ? (
        <p style={{ margin: 0, fontSize: 12.5, color: 'var(--text-muted)' }}>
          No previous examination on record. This will be the first.
        </p>
      ) : (
        <div style={{ position: 'relative' }}>
          {/* The spine. Behind the dots, and stopping at the last one so it does
              not trail off past the end of the list. */}
          <div style={{
            position: 'absolute', left: 7, top: 10, bottom: 14, width: 2,
            background: 'var(--border)', borderRadius: 2,
          }} />

          {rows.map((r, i) => {
            const previous = rows[i + 1]           // the row below is the older one
            const move = movement(r.health_score, previous?.health_score)
            const tone = fitnessTone(r.fitness_status, r.is_cleared)

            return (
              <div key={r.id ?? i} style={{ position: 'relative', paddingLeft: 26, paddingBottom: i === rows.length - 1 ? 0 : 14 }}>
                <span style={{
                  position: 'absolute', left: 0, top: 4, width: 16, height: 16, borderRadius: '50%',
                  background: tone, border: '3px solid var(--bg-card)', boxSizing: 'border-box',
                }} />

                <div style={{ display: 'flex', alignItems: 'baseline', gap: 8, flexWrap: 'wrap' }}>
                  <strong style={{ fontSize: 13, color: 'var(--text-h)' }}>
                    {fmtDate(r.exam_date)}
                  </strong>
                  <span style={{ fontSize: 10.5, fontWeight: 800, padding: '2px 7px', borderRadius: 6, background: `${tone}1f`, color: tone }}>
                    {r.fitness_label || r.fitness_status || 'Not stated'}
                  </span>
                  {r.is_reexam && (
                    <span style={{ display: 'inline-flex', alignItems: 'center', gap: 3, fontSize: 10, fontWeight: 800, color: '#a78bfa' }}>
                      <RotateCcw size={10} /> RE-EXAM
                    </span>
                  )}
                  {r.qc_label && (
                    <span style={{ fontSize: 10, color: 'var(--text-muted)' }}>· {r.qc_label}</span>
                  )}
                </div>

                <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginTop: 4, flexWrap: 'wrap' }}>
                  {r.health_score !== null && r.health_score !== undefined && (
                    <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4, fontSize: 11.5, color: 'var(--text-muted)' }}>
                      Score <strong style={{ color: 'var(--text-h)' }}>{r.health_score}</strong>/10
                      <span style={{ display: 'inline-flex', alignItems: 'center', gap: 2, color: move.tone, fontWeight: 800 }}>
                        <move.Icon size={11} />{move.label}
                      </span>
                    </span>
                  )}
                  {r.examiner_name && (
                    <span style={{ fontSize: 11, color: 'var(--text-muted)' }}>by {r.examiner_name}</span>
                  )}
                  {r.certificate_no && onOpenCertificate && (
                    <button type="button" onClick={() => onOpenCertificate(r)}
                      style={{ ...S.btn, padding: '4px 9px', fontSize: 11, minHeight: 32 }}>
                      <FileText size={11} /> Certificate
                    </button>
                  )}
                </div>

                {r.doctor_remarks && (
                  <p style={{ margin: '5px 0 0', fontSize: 11.5, color: 'var(--text-muted)', lineHeight: 1.5 }}>
                    {r.doctor_remarks}
                  </p>
                )}
              </div>
            )
          })}
        </div>
      )}
    </div>
  )
}

/**
 * How the score moved against the PREVIOUS examination.
 *
 * Against the previous one specifically, not the best or the average: the
 * question a clinician asks in front of the patient is what has changed since
 * they last saw them.
 */
function movement(score, previousScore) {
  if (score === null || score === undefined || previousScore === null || previousScore === undefined) {
    return { Icon: Minus, tone: 'var(--text-muted)', label: '' }
  }
  const delta = Number(score) - Number(previousScore)
  if (Math.abs(delta) < 0.05) return { Icon: Minus, tone: 'var(--text-muted)', label: 'same' }

  return delta > 0
    ? { Icon: TrendingUp, tone: '#10b981', label: `+${delta.toFixed(1)}` }
    : { Icon: TrendingDown, tone: '#ef4444', label: delta.toFixed(1) }
}

const fitnessTone = (fitness, cleared) => {
  if (cleared) return '#10b981'
  const f = String(fitness || '').toLowerCase()
  if (f.includes('unfit')) return '#ef4444'
  if (f.includes('restrict')) return '#f59e0b'
  return '#6b7280'
}

const fmtDate = (d) => {
  if (!d) return 'Undated'
  const at = new Date(d)
  return Number.isNaN(at.getTime())
    ? String(d).slice(0, 10)
    : at.toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' })
}
