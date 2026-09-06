import { useEffect, useMemo, useState } from 'react'
import { useNavigate, useOutletContext, useSearchParams } from 'react-router-dom'
import { ArrowLeft, Layers, Users, CheckCircle2, HelpCircle, ChevronRight } from 'lucide-react'
import { medicalApi } from '@/services/medicalApi'
import LoadError from '@/components/ui/LoadError'
import { S, SEVERITY_TONE, SEVERITY_WORD } from '@/components/medical/MedicalBits'

/**
 * What these people have IN COMMON.
 *
 * A doctor who has just seen fourteen workers gets fourteen certificates, and
 * fourteen certificates read one after another cannot answer the question that
 * matters on a site: is the same thing wrong with several of them?
 *
 * Six workers off one gang with the same hearing loss is a noise-exposure
 * problem, not six unlucky people. Four with the same abnormal chest film is a
 * reason to look at where they work. Nobody spots that by opening records one
 * at a time — so the findings are derived from the numbers already recorded and
 * the people are grouped by what they share.
 *
 * Shared findings come first and are the point of the page; a finding only one
 * person has is on their own record and is listed after, quietly.
 *
 * Nothing here is new data. It is a way of LOOKING at examinations that already
 * exist, which is why it needs no save button and cannot be wrong in a way the
 * underlying records are not.
 */
export default function DoctorGroupFindings() {
  const { module, audience } = useOutletContext()
  const navigate = useNavigate()
  const [params] = useSearchParams()

  const ids = useMemo(
    () => (params.get('ids') || '').split(',').map(s => s.trim()).filter(Boolean),
    [params],
  )

  const [result, setResult] = useState(null)
  const [error, setError] = useState(null)

  const load = () => {
    if (!ids.length) { setResult({ groups: [], clear: [], unexamined: [], examined: 0 }); return }
    setError(null)
    medicalApi.doctor.groupFindings(module, ids)
      .then(setResult)
      .catch(e => { setResult(null); setError(e) })
  }

  useEffect(load, [module, params]) // eslint-disable-line react-hooks/exhaustive-deps

  const shared = (result?.groups ?? []).filter(g => g.shared)
  const individual = (result?.groups ?? []).filter(g => !g.shared)

  const backToList = () => navigate('/doctor-portal/examine')

  const examine = (personId) => navigate({
    pathname: '/doctor-portal/examine/form',
    search: new URLSearchParams({ person: String(personId), queue: ids.join(',') }).toString(),
  })

  return (
    <div>
      <header style={{ display: 'flex', alignItems: 'center', gap: 12, flexWrap: 'wrap', marginBottom: 16 }}>
        <button onClick={backToList} style={{ ...S.btn, minHeight: 44 }}>
          <ArrowLeft size={14} /> Back to list
        </button>
        <div style={{ minWidth: 0 }}>
          <h1 style={{ margin: 0, fontSize: 21, fontWeight: 900, color: 'var(--text-h)', display: 'flex', alignItems: 'center', gap: 8 }}>
            <Layers size={19} /> What these {ids.length} have in common
          </h1>
          <p style={{ margin: '4px 0 0', color: 'var(--text-muted)', fontSize: 12.5 }}>
            {audience?.label || 'Selected people'} · from each person’s most recent examination.
          </p>
        </div>
      </header>

      {error ? (
        <LoadError error={error} onRetry={load} />
      ) : !result ? (
        <p style={{ fontSize: 13, color: 'var(--text-muted)' }}>Reading their examinations…</p>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>

          {/* ── The point of the page ─────────────────────────────────── */}
          {shared.length > 0 ? (
            <>
              <p style={{ margin: 0, fontSize: 12.5, color: 'var(--text-muted)' }}>
                <strong style={{ color: '#a78bfa' }}>{shared.length}</strong>{' '}
                {shared.length === 1 ? 'finding is' : 'findings are'} shared by more than one person.
                Most-shared first.
              </p>
              {shared.map(group => <Group key={group.key} group={group} onOpen={examine} />)}
            </>
          ) : (
            <div className="pr-glass" style={{ padding: 16, borderRadius: 14 }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: 9, marginBottom: 6 }}>
                <CheckCircle2 size={17} style={{ color: '#10b981' }} />
                <h2 style={{ margin: 0, fontSize: 14, fontWeight: 900, color: 'var(--text-h)' }}>
                  Nothing is shared between them
                </h2>
              </div>
              <p style={{ margin: 0, fontSize: 12.5, color: 'var(--text-muted)', lineHeight: 1.55 }}>
                No finding appears in more than one of these {result.examined || 0} examinations. Anything
                found belongs to one person and is listed below.
              </p>
            </div>
          )}

          {/* ── One person each ───────────────────────────────────────── */}
          {individual.length > 0 && (
            <>
              <h2 style={{ margin: '6px 0 0', fontSize: 12, fontWeight: 900, letterSpacing: '0.06em', textTransform: 'uppercase', color: 'var(--text-muted)' }}>
                Found in one person only
              </h2>
              {individual.map(group => <Group key={group.key} group={group} onOpen={examine} />)}
            </>
          )}

          {/* ── Everybody the grouping could not speak for ─────────────── */}
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(260px, 1fr))', gap: 12 }}>
            {result.clear?.length > 0 && (
              <People
                icon={CheckCircle2} tone="#10b981"
                title={`${result.clear.length} with nothing found`}
                note="Their last examination raised no finding at all."
                people={result.clear} onOpen={examine}
              />
            )}
            {result.unexamined?.length > 0 && (
              <People
                icon={HelpCircle} tone="#6b7280"
                title={`${result.unexamined.length} never examined`}
                note="Nothing on record to compare — they are the ones to see first."
                people={result.unexamined} onOpen={examine}
              />
            )}
          </div>
        </div>
      )}
    </div>
  )
}

/** One finding, and everyone who has it. */
function Group({ group, onOpen }) {
  const tone = SEVERITY_TONE[group.severity] || '#6b7280'

  return (
    <section className="pr-glass" style={{ padding: 14, borderRadius: 14, borderLeft: `3px solid ${tone}` }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 9, flexWrap: 'wrap', marginBottom: 10 }}>
        <span style={{ width: 9, height: 9, borderRadius: 5, background: tone, flexShrink: 0 }} />
        <h3 style={{ margin: 0, fontSize: 14.5, fontWeight: 900, color: 'var(--text-h)' }}>{group.label}</h3>
        <span style={{ fontSize: 10, fontWeight: 800, padding: '3px 7px', borderRadius: 6, background: `${tone}1f`, color: tone }}>
          {SEVERITY_WORD[group.severity] || group.severity}
        </span>
        <span style={{ marginLeft: 'auto', display: 'inline-flex', alignItems: 'center', gap: 5, fontSize: 12, fontWeight: 800, color: group.shared ? '#a78bfa' : 'var(--text-muted)' }}>
          <Users size={13} /> {group.count} {group.count === 1 ? 'person' : 'people'}
        </span>
      </div>

      <div style={{ display: 'flex', flexWrap: 'wrap', gap: 7 }}>
        {group.people.map(p => (
          <button key={`${group.key}-${p.id}`} type="button" onClick={() => onOpen(p.id)}
            title={`Examine ${p.name}`}
            style={{
              display: 'flex', flexDirection: 'column', alignItems: 'flex-start', gap: 1,
              padding: '7px 11px', borderRadius: 10, minHeight: 44, cursor: 'pointer',
              background: 'var(--bg-input)', border: '1px solid var(--border)', textAlign: 'left',
            }}>
            <span style={{ fontSize: 12.5, fontWeight: 700, color: 'var(--text-h)' }}>{p.name}</span>
            {(p.detail || p.context) && (
              <span style={{ fontSize: 10.5, color: 'var(--text-muted)' }}>
                {[p.detail, p.context].filter(Boolean).join(' · ')}
              </span>
            )}
          </button>
        ))}
      </div>
    </section>
  )
}

/** A plain list of people who share a status rather than a finding. */
function People({ icon: Icon, tone, title, note, people, onOpen }) {
  return (
    <section className="pr-glass" style={{ padding: 14, borderRadius: 14 }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 4 }}>
        <Icon size={16} style={{ color: tone }} />
        <h3 style={{ margin: 0, fontSize: 13.5, fontWeight: 900, color: 'var(--text-h)' }}>{title}</h3>
      </div>
      <p style={{ margin: '0 0 10px', fontSize: 11.5, color: 'var(--text-muted)', lineHeight: 1.5 }}>{note}</p>
      <div style={{ display: 'flex', flexDirection: 'column', gap: 5 }}>
        {people.map(p => (
          <button key={p.id} type="button" onClick={() => onOpen(p.id)}
            style={{
              display: 'flex', alignItems: 'center', gap: 8, minHeight: 42, padding: '8px 10px',
              borderRadius: 10, background: 'var(--bg-input)', border: '1px solid var(--border)',
              cursor: 'pointer', textAlign: 'left',
            }}>
            <span style={{ flex: 1, minWidth: 0, fontSize: 12.5, fontWeight: 700, color: 'var(--text-h)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
              {p.name}
            </span>
            {p.context && <span style={{ fontSize: 10.5, color: 'var(--text-muted)' }}>{p.context}</span>}
            <ChevronRight size={13} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />
          </button>
        ))}
      </div>
    </section>
  )
}
