import { useCallback, useEffect, useState } from 'react'
import { useOutletContext } from 'react-router-dom'
import { FileText, RefreshCw } from 'lucide-react'
import LoadError from '@/components/ui/LoadError'
import { medicalApi } from '@/services/medicalApi'
import MedicalDetailDrawer from '@/components/medical/MedicalDetailDrawer'
import { S, FitnessPill, QcPill, HealthScore, humanise } from '@/components/medical/MedicalBits'

/**
 * Everything this doctor has signed on the current side, newest first.
 *
 * The default view is deliberately unfiltered rather than "pending only": a
 * doctor is answerable for the certificates they issued, including the ones
 * that were rejected, and a list that quietly hid those would be a worse record
 * than a paper one.
 */
export default function DoctorExaminations() {
  const { module } = useOutletContext()
  const [rows, setRows] = useState(null)
  const [statuses, setStatuses] = useState([])
  const [status, setStatus] = useState('')
  const [loadError, setLoadError] = useState(null)
  const [openId, setOpenId] = useState(null)

  const load = useCallback(() => {
    medicalApi.doctor.examinations(module, status ? { qc_status: status } : {})
      .then(d => { setLoadError(null); setRows(Array.isArray(d) ? d : d?.data ?? []) })
      .catch(e => { setRows([]); setLoadError(e) })
  }, [module, status])

  useEffect(() => { load() }, [load])
  useEffect(() => { setStatuses(['Pending', 'Approved', 'Hold', 'Rejected']) }, [])

  // A doctor READS their own examinations. They neither rule on them nor argue
  // about them: the quality check is somebody else's job, and the conversation
  // about a certificate belongs to the vendor and the reviewer.
  const source = {
    get:         (id) => medicalApi.doctor.examination(module, id),
    certificate: (id) => medicalApi.doctor.certificate(module, id),
  }

  return (
    <div>
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-end', flexWrap: 'wrap', gap: 12, marginBottom: 16 }}>
        <div>
          <h1 style={{ margin: 0, fontSize: 22, fontWeight: 900, color: 'var(--text-h)', display: 'flex', alignItems: 'center', gap: 8 }}>
            <FileText size={20} /> My examinations
          </h1>
          <p style={{ margin: '4px 0 0', color: 'var(--text-muted)', fontSize: 12.5 }}>
            {module === 'tpv' ? 'TPV vendors' : 'Purchase vendors'} · what the quality team has done with each one.
          </p>
        </div>
        <div style={{ display: 'flex', gap: 8 }}>
          <select value={status} onChange={e => setStatus(e.target.value)} style={S.select}>
            <option value="">Any review state</option>
            {statuses.map(s => <option key={s} value={s}>{humanise(s)}</option>)}
          </select>
          <button onClick={load} style={S.btn}><RefreshCw size={14} /> Refresh</button>
        </div>
      </header>

      <div className="pr-glass" style={{ padding: 0, borderRadius: 14, overflow: 'hidden' }}>
        <div style={{ overflowX: 'auto' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
            <thead>
              <tr style={{ textAlign: 'left', color: 'var(--text-muted)', fontSize: 11, textTransform: 'uppercase', letterSpacing: '0.04em' }}>
                {['Certificate', 'Worker', 'Outcome', 'Review', 'Score', 'Valid until', ''].map((h, i) => <th key={i} style={S.th}>{h}</th>)}
              </tr>
            </thead>
            <tbody>
              {loadError ? (
                <tr><td colSpan={7} style={{ padding: 8 }}><LoadError error={loadError} onRetry={load} /></td></tr>
              ) : rows === null ? (
                <tr><td colSpan={7} style={{ padding: 18, color: 'var(--text-muted)' }}>Loading…</td></tr>
              ) : rows.length === 0 ? (
                <tr><td colSpan={7} style={{ padding: 18, color: 'var(--text-muted)' }}>No examinations recorded on this side yet.</td></tr>
              ) : rows.map(m => (
                <tr key={m.id} onClick={() => setOpenId(m.id)} style={{ borderTop: '1px solid var(--border)', cursor: 'pointer' }}>
                  <td style={{ ...S.td, fontWeight: 700, color: 'var(--text-h)' }}>
                    {m.certificate_no}
                    <div style={{ fontSize: 11, color: 'var(--text-muted)', fontWeight: 500 }}>
                      {m.exam_date}{m.is_reexam ? ` · re-exam #${m.attempt_no}` : ''}
                    </div>
                  </td>
                  <td style={{ ...S.td, fontWeight: 700, color: 'var(--text-h)' }}>
                    {m.worker?.name || m.worker?.full_name || '—'}
                    <div style={{ fontSize: 11, color: 'var(--text-muted)', fontWeight: 500 }}>{m.worker?.worker_code}</div>
                  </td>
                  <td style={S.td}><FitnessPill status={m.fitness_status} /></td>
                  <td style={S.td}>
                    <QcPill status={m.qc_status} />
                    {m.qc_reason_code && (
                      <div style={{ fontSize: 11, color: 'var(--text-muted)', marginTop: 2 }}>{humanise(m.qc_reason_code)}</div>
                    )}
                  </td>
                  <td style={S.td}><HealthScore score={m.health_score} band={m.health_band} /></td>
                  <td style={{ ...S.td, color: m.is_expired ? '#ef4444' : 'var(--text-muted)' }}>{m.valid_until || m.expiry_date || '—'}</td>
                  <td style={S.td}>
                    <button
                      onClick={e => { e.stopPropagation(); medicalApi.doctor.certificate(module, m.id) }}
                      style={{ ...S.btn, padding: '4px 10px', fontSize: 11.5 }}
                    >
                      <FileText size={12} /> PDF
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      <MedicalDetailDrawer
        open={!!openId}
        medicalId={openId}
        onClose={() => setOpenId(null)}
        role="doctor"
        source={source}
      />
    </div>
  )
}
