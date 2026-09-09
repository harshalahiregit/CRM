import { useCallback, useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { Users, FileText, RefreshCw, Search, BarChart3, ChevronLeft, ChevronRight } from 'lucide-react'
import { medicalApi } from '@/services/medicalApi'
import LoadError from '@/components/ui/LoadError'
import Drawer from '@/components/ui/Drawer'
import { KIT3D_STYLE as GLASS_STYLE } from '@/components/ui/kit3d'
import {
  S, FitnessPill, QcPill, HealthScore, FindingPill, humanise,
} from '@/components/medical/MedicalBits'

/**
 * The general medical register, for an admin.
 *
 * The doctor portal has been examining internal staff, client contacts and site
 * visitors for as long as it has existed, and every one of those examinations
 * went into a table nothing could read. No list, no report, no way to open a
 * certificate. From the doctor's side it looked like a working feature; from
 * everywhere else the records did not exist.
 *
 * This is the list. The row says who, when, the verdict and how many findings
 * it carries — enough to triage without opening anything — and opening one
 * shows the examination in full with that person's history beside it.
 *
 * The two vendor registers are untouched: they have their own module-scoped
 * pages and their own reviewers, and a contractor's certificate belongs in the
 * register it was filed in.
 */
export default function MedicalGeneralRegister() {
  const [rows, setRows] = useState(null)
  const [meta, setMeta] = useState(null)
  const [options, setOptions] = useState(null)
  const [error, setError] = useState(null)
  const [openId, setOpenId] = useState(null)
  // The report links straight to one person's examination, so a link that
  // names a record must open it rather than landing on an unfiltered list.
  const [params, setParams] = useSearchParams()

  useEffect(() => {
    const wanted = params.get('open')
    if (wanted) setOpenId(Number(wanted))
  }, [params])

  const closeDrawer = () => {
    setOpenId(null)
    if (!params.get('open')) return
    // A fresh object rather than mutating the one the router handed back —
    // mutating it in place is how a "close" ends up not sticking on re-render.
    const next = new URLSearchParams(params)
    next.delete('open')
    setParams(next, { replace: true })
  }

  const [filters, setFilters] = useState({
    audience: '', fitness: '', qc_status: '', doctor_id: '', from: '', to: '', q: '',
  })
  const [page, setPage] = useState(1)
  // Typing filters the list only once typing stops — a request per keystroke
  // over a register is a table that flickers.
  const [typed, setTyped] = useState('')

  useEffect(() => {
    const t = setTimeout(() => { setFilters(f => ({ ...f, q: typed })); setPage(1) }, 300)
    return () => clearTimeout(t)
  }, [typed])

  const load = useCallback(() => {
    setError(null)
    const clean = Object.fromEntries(Object.entries({ ...filters, page }).filter(([, v]) => v !== '' && v != null))
    medicalApi.general.list(clean)
      .then(res => { setRows(res?.data ?? []); setMeta(res?.meta ?? null); setOptions(res?.options ?? null) })
      .catch(e => { setRows([]); setError(e) })
  }, [filters, page])

  useEffect(() => { load() }, [load])

  const set = (k, v) => { setFilters(f => ({ ...f, [k]: v })); setPage(1) }

  return (
    <div>
      {/* .pr-glass lives in this stylesheet, and the admin shell does not
          inject it — without this the cards below have no background at all
          and the page shows through them. */}
      <style>{GLASS_STYLE}</style>
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-end', flexWrap: 'wrap', gap: 12, marginBottom: 16 }}>
        <div>
          <h1 style={{ margin: 0, fontSize: 22, fontWeight: 900, color: 'var(--text-h)', display: 'flex', alignItems: 'center', gap: 8 }}>
            <Users size={20} /> Medical register — staff, clients and visitors
          </h1>
          <p style={{ margin: '4px 0 0', color: 'var(--text-muted)', fontSize: 12.5 }}>
            Every examination of somebody who is not a vendor worker. Open one to see it in full.
          </p>
        </div>
        <div style={{ display: 'flex', gap: 8 }}>
          <Link to="/app/medical/general/report" style={{ ...S.btn, textDecoration: 'none', minHeight: 40 }}>
            <BarChart3 size={14} /> Report
          </Link>
          <button onClick={load} style={{ ...S.btn, minHeight: 40 }}><RefreshCw size={14} /> Refresh</button>
        </div>
      </header>

      {/* ── Filters ──────────────────────────────────────────────────────── */}
      <div className="pr-glass" style={{ padding: 12, borderRadius: 14, marginBottom: 14, display: 'flex', gap: 10, flexWrap: 'wrap', alignItems: 'flex-end' }}>
        <div style={{ flex: '1 1 220px', minWidth: 0, position: 'relative' }}>
          <label style={S.label}>Search</label>
          <Search size={14} style={{ position: 'absolute', left: 10, top: 32, color: 'var(--text-muted)' }} />
          <input value={typed} onChange={e => setTyped(e.target.value)}
            placeholder="Name or certificate number…"
            style={{ ...S.input, paddingLeft: 32, minHeight: 40 }} />
        </div>

        <Choice label="Audience" value={filters.audience} onChange={v => set('audience', v)} any="Everyone"
          options={(options?.audiences ?? []).map(a => ({ value: a.key, label: a.label }))} />

        <Choice label="Outcome" value={filters.fitness} onChange={v => set('fitness', v)} any="Any outcome"
          options={(options?.fitness_statuses ?? []).map(v => ({ value: v, label: humanise(v) }))} />

        <Choice label="Review" value={filters.qc_status} onChange={v => set('qc_status', v)} any="Any review state"
          options={(options?.qc_statuses ?? []).map(v => ({ value: v, label: humanise(v) }))} />

        <Choice label="Doctor" value={filters.doctor_id} onChange={v => set('doctor_id', v)} any="Any doctor"
          options={(options?.doctors ?? []).map(d => ({ value: String(d.id), label: d.name }))} />

        <div>
          <label style={S.label}>From</label>
          <input type="date" value={filters.from} onChange={e => set('from', e.target.value)} style={{ ...S.input, minHeight: 40 }} />
        </div>
        <div>
          <label style={S.label}>To</label>
          <input type="date" value={filters.to} onChange={e => set('to', e.target.value)} style={{ ...S.input, minHeight: 40 }} />
        </div>
      </div>

      {/* ── The register ─────────────────────────────────────────────────── */}
      <div className="pr-glass" style={{ padding: 0, borderRadius: 14, overflow: 'hidden' }}>
        <div style={{ overflowX: 'auto' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
            <thead>
              <tr style={{ textAlign: 'left', color: 'var(--text-muted)', fontSize: 11, textTransform: 'uppercase', letterSpacing: '0.04em' }}>
                {['Person', 'Audience', 'Certificate', 'Outcome', 'Review', 'Score', 'Findings', 'Valid until', ''].map((h, i) => (
                  <th key={i} style={S.th}>{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {error ? (
                <tr><td colSpan={9} style={{ padding: 8 }}><LoadError error={error} onRetry={load} /></td></tr>
              ) : rows === null ? (
                <tr><td colSpan={9} style={{ padding: 18, color: 'var(--text-muted)' }}>Loading…</td></tr>
              ) : rows.length === 0 ? (
                <tr><td colSpan={9} style={{ padding: 18, color: 'var(--text-muted)' }}>
                  No examinations match. Doctors file these from the portal, under Internal team, Client contacts or Site visitors.
                </td></tr>
              ) : rows.map(r => (
                <tr key={r.id} onClick={() => setOpenId(r.id)} style={{ borderTop: '1px solid var(--border)', cursor: 'pointer' }}>
                  <td style={{ ...S.td, fontWeight: 700, color: 'var(--text-h)' }}>
                    {r.person}
                    {r.context && <div style={{ fontSize: 11, color: 'var(--text-muted)', fontWeight: 500 }}>{r.context}</div>}
                  </td>
                  <td style={{ ...S.td, color: 'var(--text-muted)' }}>{r.audience_label}</td>
                  <td style={S.td}>
                    {r.certificate_no || '—'}
                    <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>
                      {r.exam_date}{r.is_reexam ? ' · re-exam' : ''}
                    </div>
                  </td>
                  <td style={S.td}><FitnessPill status={r.fitness_status} /></td>
                  <td style={S.td}><QcPill status={r.qc_status} /></td>
                  <td style={S.td}><HealthScore score={r.health_score} band={r.health_band} /></td>
                  <td style={S.td}>
                    {/* Counted rather than listed: the row is for triage, and
                        the findings themselves are one tap away. */}
                    {r.finding_count > 0
                      ? <span style={{ fontSize: 11.5, fontWeight: 800, padding: '3px 8px', borderRadius: 7, background: '#f9731620', color: '#f97316' }}>
                          {r.finding_count}
                        </span>
                      : <span style={{ fontSize: 11.5, color: '#10b981', fontWeight: 700 }}>none</span>}
                  </td>
                  <td style={{ ...S.td, color: r.is_expired ? '#ef4444' : 'var(--text-muted)' }}>
                    {r.valid_until || '—'}{r.is_expired ? ' · expired' : ''}
                  </td>
                  <td style={S.td}>
                    <button onClick={e => { e.stopPropagation(); medicalApi.general.certificate(r.id) }}
                      style={{ ...S.btn, padding: '4px 10px', fontSize: 11.5 }}>
                      <FileText size={12} /> PDF
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        {meta && meta.pages > 1 && (
          <div style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '10px 14px', borderTop: '1px solid var(--border)' }}>
            <span style={{ fontSize: 12, color: 'var(--text-muted)' }}>
              {meta.total.toLocaleString()} examinations · page {meta.page} of {meta.pages}
            </span>
            <button disabled={meta.page <= 1} onClick={() => setPage(p => p - 1)}
              style={{ ...S.btn, marginLeft: 'auto', opacity: meta.page <= 1 ? 0.4 : 1 }}>
              <ChevronLeft size={13} /> Previous
            </button>
            <button disabled={meta.page >= meta.pages} onClick={() => setPage(p => p + 1)}
              style={{ ...S.btn, opacity: meta.page >= meta.pages ? 0.4 : 1 }}>
              Next <ChevronRight size={13} />
            </button>
          </div>
        )}
      </div>

      <GeneralMedicalDrawer id={openId} onClose={closeDrawer} />
    </div>
  )
}

/** A short fixed vocabulary is exactly what a dropdown is for. */
function Choice({ label, value, onChange, any, options }) {
  return (
    <div>
      <label style={S.label}>{label}</label>
      <select value={value} onChange={e => onChange(e.target.value)} style={{ ...S.select, minHeight: 40 }}>
        <option value="">{any}</option>
        {options.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
      </select>
    </div>
  )
}

/**
 * One examination in full — the individual view.
 *
 * Findings first, then the numbers they were derived from, then the proof the
 * doctor was present, then this person's other examinations. That order is the
 * order the questions get asked in: what is wrong, on what evidence, who says
 * so, and has it changed.
 */
function GeneralMedicalDrawer({ id, onClose }) {
  const [data, setData] = useState(null)
  const [error, setError] = useState(null)

  useEffect(() => {
    setData(null); setError(null)
    if (!id) return
    medicalApi.general.one(id).then(setData).catch(setError)
  }, [id])

  const m = data?.medical

  return (
    <Drawer open={!!id} onClose={onClose} title={m?.certificate_no || 'Examination'} width="min(680px, 96vw)">
      {error ? (
        <LoadError error={error} onRetry={() => medicalApi.general.one(id).then(setData).catch(setError)} />
      ) : !data ? (
        <p style={{ fontSize: 13, color: 'var(--text-muted)' }}>Loading…</p>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>

          <section className="pr-glass" style={{ padding: 14, borderRadius: 12 }}>
            <div style={{ fontSize: 16, fontWeight: 900, color: 'var(--text-h)' }}>{data.person?.name}</div>
            <div style={{ fontSize: 12, color: 'var(--text-muted)' }}>
              {[data.person?.audience_label, data.person?.context, data.person?.email].filter(Boolean).join(' · ')}
            </div>
            <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginTop: 10, alignItems: 'center' }}>
              <FitnessPill status={m.fitness_status} />
              <QcPill status={m.qc_status} />
              <HealthScore score={m.health_score} band={m.health_band} note={m.health_score_note} />
              {m.is_expired && <span style={{ fontSize: 11.5, color: '#ef4444', fontWeight: 700 }}>Expired</span>}
            </div>
          </section>

          <Section title={`Findings${data.findings?.length ? ` (${data.findings.length})` : ''}`}>
            {data.findings?.length ? (
              <div style={{ display: 'flex', flexWrap: 'wrap', gap: 7 }}>
                {data.findings.map(f => <FindingPill key={f.key} finding={f} />)}
              </div>
            ) : (
              <p style={{ margin: 0, fontSize: 12.5, color: '#10b981' }}>Nothing was found in this examination.</p>
            )}
          </Section>

          <Section title="Examination">
            <Grid>
              <Item label="Examined" value={m.exam_date} />
              <Item label="Valid until" value={m.valid_until} />
              <Item label="Blood group" value={m.blood_group} />
              <Item label="Height / weight" value={[m.height_cm && `${m.height_cm} cm`, m.weight_kg && `${m.weight_kg} kg`].filter(Boolean).join(' · ')} />
              <Item label="Blood pressure" value={m.bp_systolic ? `${m.bp_systolic}/${m.bp_diastolic ?? '?'} mmHg` : null} />
              <Item label="Pulse" value={m.pulse_bpm && `${m.pulse_bpm} bpm`} />
              <Item label="SpO₂" value={m.spo2 && `${m.spo2}%`} />
              <Item label="Temperature" value={m.temperature_c && `${m.temperature_c} °C`} />
              <Item label="Vision" value={[m.vision_left, m.vision_right].filter(Boolean).join(' / ')} />
              <Item label="Colour vision" value={m.colour_vision} />
              <Item label="Hearing" value={m.hearing} />
            </Grid>
            {(m.restrictions || m.doctor_remarks) && (
              <div style={{ marginTop: 10, fontSize: 12.5, color: 'var(--text-muted)', lineHeight: 1.6 }}>
                {m.restrictions && <div><strong style={{ color: 'var(--text-h)' }}>Restrictions:</strong> {m.restrictions}</div>}
                {m.doctor_remarks && <div><strong style={{ color: 'var(--text-h)' }}>Remarks:</strong> {m.doctor_remarks}</div>}
              </div>
            )}
          </Section>

          <Section title="Who signed it">
            <Grid>
              <Item label="Doctor" value={m.examiner_name} />
              <Item label="Licence" value={m.doctor_license_no} />
              <Item label="Council" value={m.doctor_council} />
              <Item label="Clinic" value={m.clinic_name} />
            </Grid>
            {/* The evidence the certificate rests on. Recorded on every
                examination and, until this page, never shown to anybody. */}
            <p style={{ margin: '10px 0 0', fontSize: 11.5, color: 'var(--text-muted)' }}>
              Signed from {m.geo_place || m.geo_location || 'an unrecorded place'}
              {m.system_ip ? ` · IP ${m.system_ip}` : ''}
            </p>
          </Section>

          <Section title="This person's examinations">
            <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
              {(data.history ?? []).map(h => (
                <div key={h.id} style={{
                  display: 'flex', alignItems: 'center', gap: 9, padding: '8px 10px', borderRadius: 10,
                  background: h.is_current ? 'rgba(124,58,237,0.12)' : 'var(--bg-input)',
                  border: `1px solid ${h.is_current ? 'rgba(124,58,237,0.4)' : 'var(--border)'}`,
                }}>
                  <span style={{ fontSize: 12, fontWeight: 700, color: 'var(--text-h)', minWidth: 92 }}>{h.exam_date}</span>
                  <FitnessPill status={h.fitness_status} />
                  <span style={{ fontSize: 11.5, color: 'var(--text-muted)', marginLeft: 'auto' }}>
                    {h.health_band ? `${h.health_score} · ${h.health_band}` : '—'}
                  </span>
                </div>
              ))}
            </div>
          </Section>

          <button onClick={() => medicalApi.general.certificate(m.id)} style={{ ...S.btn, alignSelf: 'flex-start' }}>
            <FileText size={13} /> Open the certificate
          </button>
        </div>
      )}
    </Drawer>
  )
}

const Section = ({ title, children }) => (
  <section className="pr-glass" style={{ padding: 14, borderRadius: 12 }}>
    <h3 style={{ margin: '0 0 10px', fontSize: 12, fontWeight: 900, letterSpacing: '0.05em', textTransform: 'uppercase', color: 'var(--text-muted)' }}>{title}</h3>
    {children}
  </section>
)

const Grid = ({ children }) => (
  <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(140px, 1fr))', gap: 10 }}>{children}</div>
)

const Item = ({ label, value }) => (
  <div>
    <div style={{ fontSize: 10.5, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.04em', color: 'var(--text-muted)' }}>{label}</div>
    <div style={{ fontSize: 12.5, color: 'var(--text-h)', fontWeight: 600 }}>{value || '—'}</div>
  </div>
)
