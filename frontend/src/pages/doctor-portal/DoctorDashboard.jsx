import { useEffect, useState } from 'react'
import { useNavigate, useOutletContext } from 'react-router-dom'
import { ClipboardPlus, Stethoscope } from 'lucide-react'
import { medicalApi } from '@/services/medicalApi'
import { S, Stat } from '@/components/medical/MedicalBits'

/**
 * What a doctor wants on opening the portal: how much of their work is still
 * moving, and a way straight into the next examination.
 *
 * Counts are shown per vendor side rather than only as a total — a doctor who
 * serves both is really running two caseloads, and a single number would hide
 * which one has certificates stuck in review.
 */
export default function DoctorDashboard() {
  const { me } = useOutletContext()
  const navigate = useNavigate()
  const [summary, setSummary] = useState(null)

  useEffect(() => { medicalApi.doctor.summary().then(setSummary).catch(() => setSummary(null)) }, [])

  return (
    <div>
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-end', flexWrap: 'wrap', gap: 12, marginBottom: 18 }}>
        <div>
          <h1 style={{ margin: 0, fontSize: 22, fontWeight: 900, color: 'var(--text-h)', display: 'flex', alignItems: 'center', gap: 8 }}>
            <Stethoscope size={20} /> Good to see you{me?.name ? `, ${me.name.split(' ')[0]}` : ''}
          </h1>
          <p style={{ margin: '4px 0 0', color: 'var(--text-muted)', fontSize: 12.5 }}>
            Your examinations and where they have got to.
          </p>
        </div>
        <button onClick={() => navigate('/doctor-portal/examine')} style={S.btnPrimary}>
          <ClipboardPlus size={15} /> New examination
        </button>
      </header>

      <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap', marginBottom: 18 }}>
        <Stat label="Examinations" value={summary?.total_examinations} tone="#7C3AED" />
        <Stat label="Awaiting review" value={summary?.pending_review} tone="#6366f1" />
        <Stat label="On hold" value={summary?.held} tone="#f59e0b" />
        <Stat label="Rejected" value={summary?.rejected} tone="#ef4444" />
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(260px, 1fr))', gap: 12 }}>
        {(summary?.modules ?? []).map(m => (
          <section key={m.module} className="pr-glass" style={{ padding: 16, borderRadius: 14 }}>
            <h2 style={{ margin: '0 0 12px', fontSize: 13, fontWeight: 800, color: 'var(--text-h)' }}>
              {m.module === 'tpv' ? 'TPV vendors' : 'Purchase vendors'}
            </h2>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(2, 1fr)', gap: 10 }}>
              <Line label="Examinations" value={m.examinations} tone="var(--text-h)" />
              <Line label="Approved" value={m.approved} tone="#10b981" />
              <Line label="Awaiting review" value={m.pending_review} tone="#6366f1" />
              <Line label="On hold" value={m.held} tone="#f59e0b" />
              <Line label="Rejected" value={m.rejected} tone="#ef4444" />
            </div>
          </section>
        ))}
      </div>
    </div>
  )
}

function Line({ label, value, tone }) {
  return (
    <div>
      <div style={S.label}>{label}</div>
      <div style={{ fontSize: 18, fontWeight: 900, color: tone }}>{value ?? 0}</div>
    </div>
  )
}
