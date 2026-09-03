import { useCallback, useEffect, useState } from 'react'
import { HeartPulse, FileText, TrendingUp, TrendingDown } from 'lucide-react'
import { medicalApi } from '@/services/medicalApi'
import MedicalDetailDrawer from './MedicalDetailDrawer'
import { S, FitnessPill, QcPill, ClearancePill, HealthScore } from './MedicalBits'

/**
 * A worker's health history, for their profile.
 *
 * Two things a profile is for: the current standing, and the direction of
 * travel. So the score leads, the movement against the previous examination
 * sits under it, and every past report is one click from its PDF — a health
 * record that cannot produce the document it summarises is not a record.
 *
 * `module` is 'tpv' | 'purchase'; the panel reads the same worker-history
 * endpoint on either side.
 */
export default function WorkerHealthPanel({ module = 'tpv', workerId, compact = false }) {
  const [history, setHistory] = useState(null)
  const [error, setError] = useState(false)
  const [openId, setOpenId] = useState(null)

  const load = useCallback(() => {
    if (!workerId) return
    medicalApi.admin.workerHistory(module, workerId)
      .then(d => { setError(false); setHistory(d) })
      .catch(() => setError(true))
  }, [module, workerId])

  useEffect(() => { load() }, [load])

  const source = {
    get:         (id) => medicalApi.admin.get(module, id),
    decide:      (id, data) => medicalApi.admin.decide(module, id, data),
    comment:     (id, data) => medicalApi.admin.comment(module, id, data),
    certificate: (id) => medicalApi.admin.certificate(module, id),
    document:    (id) => medicalApi.admin.document(module, id),
  }

  if (error) return <p style={{ fontSize: 12.5, color: 'var(--text-muted)' }}>Health history is unavailable.</p>
  if (!history) return <p style={{ fontSize: 12.5, color: 'var(--text-muted)' }}>Loading health history…</p>

  const { records = [], health_score, health_band, score_scale, score_trend, exam_count, clearance } = history
  const Trend = score_trend >= 0 ? TrendingUp : TrendingDown

  return (
    <div>
      {/* ── Standing ─────────────────────────────────────────────────── */}
      <div className="pr-glass" style={{ padding: 14, borderRadius: 12, marginBottom: 12, display: 'flex', gap: 20, alignItems: 'center', flexWrap: 'wrap' }}>
        <div>
          <div style={S.label}>Overall health score</div>
          <HealthScore score={health_score} band={health_band} scale={score_scale ?? 10} size="lg" />
          {score_trend != null && score_trend !== 0 && (
            <div style={{ display: 'flex', alignItems: 'center', gap: 4, fontSize: 11.5, fontWeight: 700, color: score_trend >= 0 ? '#10b981' : '#ef4444' }}>
              <Trend size={13} /> {Math.abs(score_trend).toFixed(1)} since the previous examination
            </div>
          )}
        </div>

        <div>
          <div style={S.label}>Medical clearance</div>
          <ClearancePill clearance={clearance} />
          {clearance?.message && (
            <div style={{ fontSize: 11.5, color: 'var(--text-muted)', marginTop: 4, maxWidth: 320 }}>{clearance.message}</div>
          )}
        </div>

        <div>
          <div style={S.label}>Examinations</div>
          <div style={{ fontSize: 20, fontWeight: 900, color: 'var(--text-h)' }}>{exam_count ?? 0}</div>
        </div>
      </div>

      {/* ── The reports themselves ───────────────────────────────────── */}
      <div className="pr-glass" style={{ padding: 0, borderRadius: 12, overflow: 'hidden' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 6, padding: '10px 14px', borderBottom: '1px solid var(--border)' }}>
          <HeartPulse size={14} color="#a78bfa" />
          <strong style={{ fontSize: 12.5, color: 'var(--text-h)' }}>Health reports</strong>
        </div>

        {records.length === 0 ? (
          <p style={{ padding: 16, margin: 0, fontSize: 12.5, color: 'var(--text-muted)' }}>No examinations recorded yet.</p>
        ) : (
          <div style={{ overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12.5 }}>
              <thead>
                <tr style={{ textAlign: 'left', color: 'var(--text-muted)', fontSize: 10.5, textTransform: 'uppercase', letterSpacing: '0.04em' }}>
                  {['Date', 'Outcome', 'Review', 'Score', 'Examiner', ...(compact ? [] : ['Restrictions']), ''].map((h, i) => (
                    <th key={i} style={{ padding: '9px 12px' }}>{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {records.map(r => (
                  <tr key={r.id} onClick={() => setOpenId(r.id)} style={{ borderTop: '1px solid var(--border)', cursor: 'pointer' }}>
                    <td style={{ padding: '9px 12px', fontWeight: 700, color: 'var(--text-h)' }}>
                      {r.exam_date}
                      {r.is_reexam && <div style={{ fontSize: 10.5, color: '#8b5cf6', fontWeight: 700 }}>Re-examination #{r.attempt_no}</div>}
                    </td>
                    <td style={{ padding: '9px 12px' }}><FitnessPill status={r.fitness_status} /></td>
                    <td style={{ padding: '9px 12px' }}><QcPill status={r.qc_status} /></td>
                    <td style={{ padding: '9px 12px' }}><HealthScore score={r.health_score} band={r.health_band} /></td>
                    <td style={{ padding: '9px 12px', color: 'var(--text-muted)' }}>
                      {r.examiner_name || r.doctor?.name || '—'}
                      {r.doctor_license_no && <div style={{ fontSize: 10.5 }}>{r.doctor_license_no}</div>}
                    </td>
                    {!compact && (
                      <td style={{ padding: '9px 12px', color: 'var(--text-muted)' }}>{r.restrictions || '—'}</td>
                    )}
                    <td style={{ padding: '9px 12px' }}>
                      <button
                        onClick={e => { e.stopPropagation(); medicalApi.admin.certificate(module, r.id) }}
                        style={{ ...S.btn, padding: '3px 9px', fontSize: 11 }}
                      >
                        <FileText size={11} /> PDF
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      <MedicalDetailDrawer
        open={!!openId}
        medicalId={openId}
        onClose={() => setOpenId(null)}
        role="reviewer"
        source={source}
        onChanged={load}
      />
    </div>
  )
}
