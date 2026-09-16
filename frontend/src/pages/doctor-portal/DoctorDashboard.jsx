import { useEffect, useState } from 'react'
import { useNavigate, useOutletContext } from 'react-router-dom'
import { ClipboardPlus, Stethoscope, FileEdit, ArrowRight } from 'lucide-react'
import { medicalApi } from '@/services/medicalApi'
import { countDrafts } from '@/hooks/useExamDraft'
import { S, Stat } from '@/components/medical/MedicalBits'

/**
 * What a doctor wants on opening the portal: how much of their work is still
 * moving, and a way straight into the next examination.
 *
 * ── Why this page answers the audience switch ───────────────────────────
 * It used to ignore it completely. The switch in the left panel is the control
 * a doctor uses on every examination, and on this page it changed nothing on
 * screen — so it looked broken here and working everywhere else, which is a
 * worse impression than being broken everywhere: it teaches people that the
 * control is unreliable.
 *
 * It was in fact working the whole time. The page simply had nothing to say
 * about the choice, and a control with no visible effect is indistinguishable
 * from a control that does not fire.
 *
 * Now the current audience leads the page, its own numbers are shown first, and
 * the counts cover all five audiences rather than the two vendor sides — the
 * three general ones were absent from the summary entirely, so switching to
 * "Site visitors" genuinely had nothing to change.
 */
export default function DoctorDashboard() {
  const { me, module, audience } = useOutletContext()
  const navigate = useNavigate()
  const [summary, setSummary] = useState(null)
  const [drafts, setDrafts] = useState(0)

  useEffect(() => { medicalApi.doctor.summary().then(setSummary).catch(() => setSummary(null)) }, [])

  // Re-read on every audience change: drafts are per audience, and this is also
  // the visible proof that the switch did something.
  useEffect(() => { setDrafts(countDrafts(module)) }, [module])

  const mine = (summary?.modules ?? []).find(m => m.module === module)
  const others = (summary?.modules ?? []).filter(m => m.module !== module)

  const goExamine = () => navigate('/doctor-portal/examine')

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
        <button onClick={goExamine} style={S.btnPrimary}>
          <ClipboardPlus size={15} /> New examination
        </button>
      </header>

      {/* ── The audience you are on ──────────────────────────────────────
          First on the page and unmistakable, because it is what the panel
          switch changes and a doctor should never have to guess which register
          they are about to write into. */}
      <section className="pr-glass" style={{
        padding: 16, borderRadius: 14, marginBottom: 16,
        borderLeft: '3px solid #7C3AED',
      }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 12, flexWrap: 'wrap' }}>
          {audience?.icon && (
            <div style={{ width: 40, height: 40, borderRadius: 12, background: '#7C3AED22', color: '#a78bfa', display: 'grid', placeItems: 'center', flexShrink: 0 }}>
              <audience.icon size={20} />
            </div>
          )}
          <div style={{ minWidth: 0, flex: '1 1 200px' }}>
            <div style={{ fontSize: 10.5, fontWeight: 900, letterSpacing: '0.08em', textTransform: 'uppercase', color: 'var(--text-muted)' }}>
              Currently examining
            </div>
            <div style={{ fontSize: 18, fontWeight: 900, color: 'var(--text-h)' }}>
              {audience?.label || '—'}
            </div>
          </div>
          <button onClick={goExamine} style={{ ...S.btnPrimary, minHeight: 46 }}>
            Start an examination <ArrowRight size={14} />
          </button>
        </div>

        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(120px, 1fr))', gap: 10, marginTop: 14 }}>
          <Line label="Examinations" value={mine?.examinations} tone="var(--text-h)" />
          <Line label="Approved" value={mine?.approved} tone="#10b981" />
          <Line label="Awaiting review" value={mine?.pending_review} tone="#6366f1" />
          <Line label="On hold" value={mine?.held} tone="#f59e0b" />
          <Line label="Rejected" value={mine?.rejected} tone="#ef4444" />
        </div>

        {/* Unfinished work, said out loud. Before drafts existed at all a
            half-typed examination simply vanished; now it survives, and the
            doctor is told rather than left to rediscover it. */}
        {drafts > 0 && (
          <button onClick={goExamine} style={{
            display: 'flex', alignItems: 'center', gap: 9, width: '100%', marginTop: 12,
            padding: '10px 12px', borderRadius: 11, minHeight: 46, cursor: 'pointer', textAlign: 'left',
            background: 'rgba(16,185,129,0.10)', border: '1px solid rgba(16,185,129,0.35)',
          }}>
            <FileEdit size={15} style={{ color: '#10b981', flexShrink: 0 }} />
            <span style={{ fontSize: 12.5, color: 'var(--text-body, #c8c3dd)', fontWeight: 600 }}>
              You have {drafts} unfinished {drafts === 1 ? 'examination' : 'examinations'} saved on this
              device for {audience?.label?.toLowerCase() || 'this audience'}. They are marked in the list.
            </span>
          </button>
        )}
      </section>

      <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap', marginBottom: 18 }}>
        <Stat label="Examinations (all)" value={summary?.total_examinations} tone="#7C3AED" />
        <Stat label="Awaiting review" value={summary?.pending_review} tone="#6366f1" />
        <Stat label="On hold" value={summary?.held} tone="#f59e0b" />
        <Stat label="Rejected" value={summary?.rejected} tone="#ef4444" />
      </div>

      <h2 style={{ margin: '0 0 10px', fontSize: 12, fontWeight: 900, letterSpacing: '0.06em', textTransform: 'uppercase', color: 'var(--text-muted)' }}>
        Your other audiences
      </h2>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(240px, 1fr))', gap: 12 }}>
        {others.map(m => (
          <section key={m.module} className="pr-glass" style={{ padding: 16, borderRadius: 14 }}>
            <h3 style={{ margin: '0 0 12px', fontSize: 13, fontWeight: 800, color: 'var(--text-h)' }}>
              {AUDIENCE_NAMES[m.module] ?? m.module}
            </h3>
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

/** The same five words the panel uses, so nothing is named twice differently. */
const AUDIENCE_NAMES = {
  tpv: 'TPV workers',
  purchase: 'Purchase workers',
  internal: 'Internal team',
  client: 'Client contacts',
  visitor: 'Site visitors',
}

function Line({ label, value, tone }) {
  return (
    <div>
      <div style={S.label}>{label}</div>
      <div style={{ fontSize: 18, fontWeight: 900, color: tone }}>{value ?? 0}</div>
    </div>
  )
}
