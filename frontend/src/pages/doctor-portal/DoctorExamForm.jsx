import { useEffect, useMemo, useState } from 'react'
import { useNavigate, useOutletContext, useSearchParams } from 'react-router-dom'
import {
  Plus, Trash2, RefreshCw, ArrowRight, ArrowLeft, Save, Undo2, ClipboardCheck,
} from 'lucide-react'
import { useToast } from '@/hooks/useToast'
import { medicalApi } from '@/services/medicalApi'
import { useExamDraft } from '@/hooks/useExamDraft'
import { useExamSelection } from '@/hooks/useExamSelection'
import CaptureBlock from '@/components/medical/CaptureBlock'
import ExamHistoryTimeline from '@/components/medical/ExamHistoryTimeline'
import { S, Card, Row, Field, ClearancePill, humanise } from '@/components/medical/MedicalBits'

/**
 * Step two: the examination itself, on a page of its own.
 *
 * It used to unfold underneath the list of people. A doctor pressed Open and
 * the screen did not appear to change, because what changed was two screens
 * below the fold — and there is no way to know you are supposed to scroll. The
 * form is now where you land, which is what pressing a button should do.
 *
 * ── Nothing typed is thrown away ────────────────────────────────────────
 * Everything on this page is drafted to the device as it is typed, per person.
 * Before, leaving for any reason destroyed it: opening somebody else, checking
 * the dashboard, a back gesture, a tablet reclaiming memory. Twenty fields of
 * vitals with the next person already waiting.
 *
 * The signature, the photograph and the location are the exception, and are
 * always captured fresh. They are the evidence that this doctor was with this
 * person at this moment; restoring them from a draft would be restoring a
 * signature nobody gave.
 *
 * ── The queue ───────────────────────────────────────────────────────────
 * Position is stated ("3 of 11"), because a doctor working through a group
 * needs to know where they are without counting, and saving moves straight to
 * the next person.
 */

const FITNESS = ['Fit', 'Fit_With_Restrictions', 'Unfit', 'Pending']

const BLANK = {
  fitness_status: 'Fit',
  exam_date: new Date().toISOString().slice(0, 10),
  valid_until: '',
  height_cm: '', weight_kg: '',
  bp_systolic: '', bp_diastolic: '', pulse_bpm: '', spo2: '',
  temperature_c: '', respiratory_rate: '', blood_group: '',
  vision_left: '', vision_right: '', colour_vision: 'Normal', hearing: 'Normal',
  allergies: '', current_medication: '',
  restrictions: '', doctor_remarks: '',
  health_score: '', health_score_note: '',
  screening_score: '',
}

const BLANK_INVESTIGATIONS = [{ name: '', result: '', remarks: '' }]

export default function DoctorExamForm() {
  const { module, audience, me } = useOutletContext()
  const isVendorSide = (audience?.kind ?? 'vendor') === 'vendor'
  const toast = useToast()
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const { remove: removeFromSelection } = useExamSelection(module)

  const personId = params.get('person') || ''
  const vendorId = params.get('vendor') || ''
  const queue = useMemo(
    () => (params.get('queue') || '').split(',').map(s => s.trim()).filter(Boolean),
    [params],
  )

  const [detail, setDetail] = useState(null)
  const [busy, setBusy] = useState(false)

  const [form, setForm] = useState(BLANK)
  const [investigations, setInvestigations] = useState(BLANK_INVESTIGATIONS)
  const [conditions, setConditions] = useState('')
  const [surgeries, setSurgeries] = useState('')
  const [habits, setHabits] = useState('')
  // Never drafted — see the note at the top.
  const [capture, setCapture] = useState({ signature_data: '', capture_photo: '', geo_location: '' })
  const [reportFile, setReportFile] = useState(null)

  const set = (k, v) => setForm(f => ({ ...f, [k]: v }))

  /* ── The draft ────────────────────────────────────────────────────────── */

  const drafted = useMemo(
    () => ({ form, investigations, conditions, surgeries, habits }),
    [form, investigations, conditions, surgeries, habits],
  )

  const restore = (stored) => {
    setForm({ ...BLANK, ...(stored?.form ?? {}) })
    setInvestigations(stored?.investigations?.length ? stored.investigations : BLANK_INVESTIGATIONS)
    setConditions(stored?.conditions ?? '')
    setSurgeries(stored?.surgeries ?? '')
    setHabits(stored?.habits ?? '')
  }

  /**
   * Has anything actually been entered?
   *
   * Compared against the blank sheet rather than assumed, so merely opening
   * somebody does not mark them as having unfinished work. A DRAFT badge that
   * appears against every person a doctor glanced at is a badge nobody reads.
   */
  const dirty = useMemo(() => {
    const touched = Object.entries(form).some(([k, v]) => v !== BLANK[k])
    const hasInvestigation = investigations.some(i => i.name.trim() || i.result.trim() || i.remarks.trim())
    return touched || hasInvestigation || !!(conditions || surgeries || habits)
  }, [form, investigations, conditions, surgeries, habits])

  const { restoredAt, clear: clearDraft, dismiss } = useExamDraft(module, personId, drafted, restore, dirty)

  /** Back to a blank sheet. The stored draft goes with it, because a blank form has none. */
  const startFresh = () => {
    dismiss()
    setForm(BLANK)
    setInvestigations(BLANK_INVESTIGATIONS)
    setConditions(''); setSurgeries(''); setHabits('')
  }

  /* ── Who ──────────────────────────────────────────────────────────────── */

  const loadPerson = () => {
    if (!personId) { setDetail(null); return }
    const fetch = isVendorSide
      ? medicalApi.doctor.worker(module, personId)
      : medicalApi.doctor.person(module, personId)
    fetch.then(setDetail).catch(() => setDetail(null))
  }

  useEffect(() => {
    // Capture belongs to the person in front of the doctor, so it resets when
    // the form moves on — a photograph must never be carried to the next name.
    setCapture({ signature_data: '', capture_photo: '', geo_location: '' })
    setReportFile(null)
    loadPerson()
    window.scrollTo({ top: 0 })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [module, personId])

  const history = detail?.history

  // The two sides answer differently: a vendor register wraps its history and
  // names the newest record `latest`, the general one returns the list itself.
  // Reading only `latest` meant a re-examination of an internal employee, a
  // client or a visitor was never marked as one — it was filed as a first
  // examination every time, and the chain that links a re-test to the record it
  // supersedes was never built.
  const previous = Array.isArray(history) ? (history[0] ?? null) : (history?.latest ?? null)
  const isReexam = !!previous

  const historyRows = useMemo(() => {
    const h = detail?.history
    return Array.isArray(h) ? h : (h?.records ?? h?.items ?? [])
  }, [detail])

  const person = detail?.worker ?? detail?.subject ?? {}
  const personName = person.worker_name || person.name || 'This person'
  const personContext = [person.worker_code, person.designation, person.context, person.vendor_name]
    .filter(Boolean).join(' · ')

  /* ── Where in the queue ───────────────────────────────────────────────── */

  const position = queue.findIndex(id => String(id) === String(personId))
  const isQueued = queue.length > 1 && position >= 0
  const nextId = position >= 0 ? queue[position + 1] : undefined

  const goTo = (id) => navigate({
    pathname: '/doctor-portal/examine/form',
    search: new URLSearchParams({
      person: String(id),
      ...(queue.length ? { queue: queue.join(',') } : {}),
      ...(vendorId ? { vendor: vendorId } : {}),
    }).toString(),
  }, { replace: true })

  const backToList = () => navigate({
    pathname: '/doctor-portal/examine',
    search: vendorId ? `?vendor=${vendorId}` : '',
  })

  /* ── What the server insists on ───────────────────────────────────────── */

  const missingCapture = [
    !capture.geo_location && 'location',
    !capture.signature_data && 'signature',
    !capture.capture_photo && 'camera photo',
  ].filter(Boolean)

  const blocked = busy || !me?.is_signable || missingCapture.length > 0

  /* ── Save ─────────────────────────────────────────────────────────────── */

  const submit = async () => {
    if (!personId) return toast.error('Pick the person being examined.')
    if (!me?.is_signable) return toast.error('Add your licence number in My profile first.')
    if (missingCapture.length) {
      return toast.error(`Capture the ${missingCapture.join(', ')} before submitting — the certificate rests on all three.`)
    }

    const payload = {
      ...Object.fromEntries(Object.entries(form).filter(([, v]) => v !== '' && v !== null)),
      ...capture,
      is_reexam: isReexam ? 1 : 0,
      investigations: investigations.filter(i => i.name.trim()),
      medical_history: {
        conditions: splitList(conditions),
        surgeries: splitList(surgeries),
        habits: splitList(habits),
      },
      report_file: reportFile || undefined,
    }

    setBusy(true)
    try {
      const res = isVendorSide
        ? await medicalApi.doctor.examine(module, personId, payload)
        : await medicalApi.doctor.examinePerson(module, personId, payload)

      // Saved — so the draft has served its purpose and must go, or it would be
      // offered back on top of a record that already exists.
      clearDraft()
      removeFromSelection(personId)

      toast.success(
        (isReexam ? 'Re-examination recorded.' : 'Examination recorded.')
        + (nextId ? ' Moving to the next person.' : ''),
      )

      // The prescription, handed over immediately — the doctor's next move.
      //
      // This open happens after the save has returned, so it is outside the
      // click that started it and a popup blocker will stop it. That used to be
      // completely silent: no tab, no message, and a doctor who believed no
      // certificate had been produced. Now they are told where it is.
      if (res?.data?.id) {
        const pdf = await (isVendorSide
          ? medicalApi.doctor.certificate(module, res.data.id)
          : medicalApi.doctor.personCertificate(module, res.data.id)).catch(() => null)

        if (pdf && !pdf.opened) {
          toast.warning('Your browser blocked the certificate tab. The certificate is issued — open it from My examinations.')
        }
      }

      if (nextId) { startFresh(); goTo(nextId) } else { backToList() }
    } catch (e) { toast.error(e) } finally { setBusy(false) }
  }

  if (!personId) {
    return (
      <div className="pr-glass" style={{ padding: 20, borderRadius: 14 }}>
        <p style={{ margin: 0, fontSize: 13, color: 'var(--text-muted)' }}>Nobody chosen yet.</p>
        <button onClick={backToList} style={{ ...S.btnPrimary, marginTop: 12 }}>
          <ArrowLeft size={14} /> Choose who to examine
        </button>
      </div>
    )
  }

  return (
    <div style={{ paddingBottom: 92 }}>
      <style>{FORM_CSS}</style>

      {/* ── Who, where in the queue, and the way back ────────────────────── */}
      <header className="pr-glass" style={{ padding: 14, borderRadius: 14, marginBottom: 14 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 12, flexWrap: 'wrap' }}>
          <button onClick={backToList} style={{ ...S.btn, minHeight: 44 }}>
            <ArrowLeft size={14} /> Back to list
          </button>

          <div style={{ minWidth: 0, flex: '1 1 220px' }}>
            <div style={{ fontSize: 17, fontWeight: 900, color: 'var(--text-h)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
              {personName}
            </div>
            <div style={{ fontSize: 12, color: 'var(--text-muted)' }}>
              {personContext || audience?.label || '—'}
            </div>
          </div>

          <ClearancePill clearance={history?.clearance ?? detail?.clearance} />

          {isQueued && (
            <div style={{ textAlign: 'right' }}>
              <div style={{ fontSize: 11, color: 'var(--text-muted)', fontWeight: 700 }}>
                Person {position + 1} of {queue.length}
              </div>
              <div style={{ width: 120, height: 6, borderRadius: 3, background: 'var(--bg-input)', marginTop: 5, overflow: 'hidden' }}>
                <div style={{ width: `${((position + 1) / queue.length) * 100}%`, height: '100%', background: '#7C3AED' }} />
              </div>
            </div>
          )}
        </div>

        {isReexam && (
          <p style={{ margin: '10px 0 0', fontSize: 12, color: '#f59e0b', fontWeight: 700 }}>
            This is a re-examination — the previous result is on the right.
          </p>
        )}
      </header>

      {/* ── The draft ────────────────────────────────────────────────────── */}
      {restoredAt && (
        <div style={{
          display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap',
          padding: '10px 13px', borderRadius: 12, marginBottom: 14,
          background: 'rgba(16,185,129,0.10)', border: '1px solid rgba(16,185,129,0.35)',
        }}>
          <Save size={15} style={{ color: '#10b981', flexShrink: 0 }} />
          <span style={{ fontSize: 12.5, color: 'var(--text-body, #c8c3dd)', fontWeight: 600 }}>
            Picked up where you left off — saved {timeAgo(restoredAt)}. The signature, photo and location
            are always taken fresh.
          </span>
          <button type="button" onClick={startFresh} style={{ ...S.btn, marginLeft: 'auto', minHeight: 38 }}>
            <Undo2 size={13} /> Start blank
          </button>
        </div>
      )}

      <div className="dx-split" style={{ display: 'grid', gap: 14, alignItems: 'start' }}>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>

          <Card title="Outcome">
            <Row>
              <Field label="Fitness verdict">
                <select value={form.fitness_status} onChange={e => set('fitness_status', e.target.value)} style={{ ...S.select, width: '100%' }}>
                  {FITNESS.map(f => <option key={f} value={f}>{humanise(f)}</option>)}
                </select>
              </Field>
              <Field label="Examined on">
                <input type="date" value={form.exam_date} onChange={e => set('exam_date', e.target.value)} style={S.input} />
              </Field>
              <Field label="Valid until" hint="Blank = one year">
                <input type="date" value={form.valid_until} onChange={e => set('valid_until', e.target.value)} style={S.input} />
              </Field>
            </Row>
            <Field label="Restrictions">
              <input value={form.restrictions} onChange={e => set('restrictions', e.target.value)} placeholder="No work at height; no night shift" style={S.input} />
            </Field>
          </Card>

          <Card title="Vitals">
            <Row>
              <Field label="Height (cm)"><input type="number" value={form.height_cm} onChange={e => set('height_cm', e.target.value)} style={S.input} /></Field>
              <Field label="Weight (kg)"><input type="number" value={form.weight_kg} onChange={e => set('weight_kg', e.target.value)} style={S.input} /></Field>
              <Field label="BP systolic"><input type="number" value={form.bp_systolic} onChange={e => set('bp_systolic', e.target.value)} style={S.input} /></Field>
              <Field label="BP diastolic"><input type="number" value={form.bp_diastolic} onChange={e => set('bp_diastolic', e.target.value)} style={S.input} /></Field>
            </Row>
            <Row>
              <Field label="Pulse (bpm)"><input type="number" value={form.pulse_bpm} onChange={e => set('pulse_bpm', e.target.value)} style={S.input} /></Field>
              <Field label="SpO₂ (%)"><input type="number" value={form.spo2} onChange={e => set('spo2', e.target.value)} style={S.input} /></Field>
              <Field label="Temperature (°C)"><input type="number" step="0.1" value={form.temperature_c} onChange={e => set('temperature_c', e.target.value)} style={S.input} /></Field>
              <Field label="Resp. rate"><input type="number" value={form.respiratory_rate} onChange={e => set('respiratory_rate', e.target.value)} style={S.input} /></Field>
            </Row>
            <Row>
              <Field label="Blood group"><input value={form.blood_group} onChange={e => set('blood_group', e.target.value)} placeholder="B+" style={S.input} /></Field>
              <Field label="Vision (left)"><input value={form.vision_left} onChange={e => set('vision_left', e.target.value)} placeholder="6/6" style={S.input} /></Field>
              <Field label="Vision (right)"><input value={form.vision_right} onChange={e => set('vision_right', e.target.value)} placeholder="6/6" style={S.input} /></Field>
            </Row>
            <Row>
              <Field label="Colour vision">
                <select value={form.colour_vision} onChange={e => set('colour_vision', e.target.value)} style={{ ...S.select, width: '100%' }}>
                  <option value="Normal">Normal</option><option value="Deficient">Deficient</option>
                </select>
              </Field>
              <Field label="Hearing">
                <select value={form.hearing} onChange={e => set('hearing', e.target.value)} style={{ ...S.select, width: '100%' }}>
                  <option value="Normal">Normal</option><option value="Impaired">Impaired</option>
                </select>
              </Field>
              <Field label="Screening score" hint="Mental-health questionnaire, 0–60">
                <input type="number" value={form.screening_score} onChange={e => set('screening_score', e.target.value)} style={S.input} />
              </Field>
            </Row>
          </Card>

          <Card
            title="Investigations"
            action={
              <button type="button" onClick={() => setInvestigations(i => [...i, { name: '', result: '', remarks: '' }])} style={{ ...S.btn, padding: '4px 10px', fontSize: 11.5 }}>
                <Plus size={12} /> Add
              </button>
            }
          >
            {investigations.map((inv, i) => (
              <div key={i} className="dx-inv" style={{ display: 'grid', gap: 8, marginBottom: 8 }}>
                <input value={inv.name} placeholder="Chest X-ray" style={S.input}
                       onChange={e => setInvestigations(list => list.map((x, j) => j === i ? { ...x, name: e.target.value } : x))} />
                <input value={inv.result} placeholder="Normal" style={S.input}
                       onChange={e => setInvestigations(list => list.map((x, j) => j === i ? { ...x, result: e.target.value } : x))} />
                <input value={inv.remarks} placeholder="Remarks" style={S.input}
                       onChange={e => setInvestigations(list => list.map((x, j) => j === i ? { ...x, remarks: e.target.value } : x))} />
                <button type="button" onClick={() => setInvestigations(list => list.filter((_, j) => j !== i))} style={{ ...S.btn, padding: '0 10px' }}>
                  <Trash2 size={13} />
                </button>
              </div>
            ))}
          </Card>

          <Card title="Declared history">
            <Row>
              <Field label="Chronic conditions" hint="Comma separated"><input value={conditions} onChange={e => setConditions(e.target.value)} placeholder="Diabetes, Hypertension" style={S.input} /></Field>
              <Field label="Past surgery" hint="Comma separated"><input value={surgeries} onChange={e => setSurgeries(e.target.value)} style={S.input} /></Field>
              <Field label="Habits" hint="Comma separated"><input value={habits} onChange={e => setHabits(e.target.value)} placeholder="Tobacco, Alcohol" style={S.input} /></Field>
            </Row>
            <Row>
              <Field label="Allergies"><input value={form.allergies} onChange={e => set('allergies', e.target.value)} style={S.input} /></Field>
              <Field label="Current medication"><input value={form.current_medication} onChange={e => set('current_medication', e.target.value)} style={S.input} /></Field>
            </Row>
          </Card>

          <Card title="Opinion">
            <Field label="Remarks">
              <textarea value={form.doctor_remarks} onChange={e => set('doctor_remarks', e.target.value)} rows={3} style={{ ...S.input, resize: 'vertical' }} />
            </Field>
            <Row>
              <Field label="Health score override" hint="Leave blank — it is computed from the examination">
                <input type="number" step="0.1" min="1" max="10" value={form.health_score} onChange={e => set('health_score', e.target.value)} style={S.input} />
              </Field>
              <Field label="Why the override">
                <input value={form.health_score_note} onChange={e => set('health_score_note', e.target.value)} disabled={!form.health_score} style={S.input} />
              </Field>
            </Row>
            <Field label="Attach a report (optional)">
              <input type="file" accept=".pdf,.jpg,.jpeg,.png" onChange={e => setReportFile(e.target.files?.[0] ?? null)} style={{ ...S.input, padding: 6 }} />
            </Field>
          </Card>

          <Card title="Signature, photo and place">
            <p style={{ margin: '0 0 10px', fontSize: 11.5, color: 'var(--text-muted)', lineHeight: 1.5 }}>
              These three are taken fresh for every examination and are never restored from a draft —
              they are the proof that you were with this person, here, today.
            </p>
            <CaptureBlock value={capture} onChange={setCapture} />
          </Card>
        </div>

        <aside style={{ display: 'flex', flexDirection: 'column', gap: 14, position: 'sticky', top: 12 }}>
          {(history?.clearance?.message || detail?.clearance?.message) && (
            <Card title="Standing">
              <p style={{ margin: 0, fontSize: 12.5, color: 'var(--text-muted)' }}>
                {history?.clearance?.message ?? detail?.clearance?.message}
              </p>
            </Card>
          )}

          <ExamHistoryTimeline
            history={historyRows}
            title="Medical examination history"
            onOpenCertificate={(r) => (isVendorSide
              ? medicalApi.doctor.certificate(module, r.id)
              : medicalApi.doctor.personCertificate(module, r.id))}
          />

          <button type="button" onClick={loadPerson} style={{ ...S.btn, alignSelf: 'flex-start', minHeight: 40 }}>
            <RefreshCw size={13} /> Refresh history
          </button>
        </aside>
      </div>

      {/* ── The one bar that saves, always in reach ───────────────────────── */}
      <div className="dx-savebar">
        {missingCapture.length > 0 ? (
          <span style={{ fontSize: 12, fontWeight: 700, color: '#f59e0b' }}>
            Still needed: {missingCapture.join(', ')}
          </span>
        ) : (
          <span style={{ fontSize: 12, color: 'var(--text-muted)' }}>
            Everything you type is kept on this device until you save.
          </span>
        )}

        {nextId && (
          <button type="button" onClick={() => goTo(nextId)} style={{ ...S.btn, minHeight: 44 }}>
            Skip to next <ArrowRight size={13} />
          </button>
        )}

        <button onClick={submit} disabled={blocked}
          style={{ ...S.btnPrimary, padding: '12px 22px', minHeight: 48, fontSize: 14, opacity: blocked ? 0.5 : 1 }}>
          <ClipboardCheck size={16} />
          {busy ? 'Saving…' : nextId ? 'Save & next person' : isReexam ? 'Save re-examination' : 'Save examination'}
        </button>
      </div>
    </div>
  )
}

const splitList = (s) => s.split(',').map(x => x.trim()).filter(Boolean)

/** Plain words, because "2026-09-05T09:14:22Z" is not what "when" means to a person. */
function timeAgo(at) {
  const mins = Math.round((Date.now() - at) / 60000)
  if (mins < 1) return 'a moment ago'
  if (mins < 60) return `${mins} minute${mins === 1 ? '' : 's'} ago`
  const hours = Math.round(mins / 60)
  if (hours < 24) return `${hours} hour${hours === 1 ? '' : 's'} ago`
  const days = Math.round(hours / 24)
  return `${days} day${days === 1 ? '' : 's'} ago`
}

/**
 * Layout rules a style object cannot express.
 *
 * The split goes to one column on a tablet in portrait: at 768px two columns
 * leave the form too narrow to fill in and the history too narrow to read, and
 * that is the device the teams on site are actually holding.
 */
const FORM_CSS = `
.dx-split { grid-template-columns: minmax(0, 2fr) minmax(260px, 1fr); }
.dx-inv { grid-template-columns: 1.2fr 1fr 1.4fr auto; }

.dx-savebar {
  position: fixed; z-index: 45; left: 244px; right: 0; bottom: 0;
  display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
  padding: 12px 20px;
  background: var(--bg-card); border-top: 1px solid var(--border);
  box-shadow: 0 -10px 30px -14px rgba(0,0,0,.6);
}
.dx-savebar > :last-child { margin-left: auto; }

@media (max-width: 1100px) { .dx-split { grid-template-columns: minmax(0, 1fr); } }
@media (max-width: 1023px) { .dx-savebar { left: 0; padding: 10px 14px; } }
@media (max-width: 700px) {
  .dx-inv { grid-template-columns: 1fr 1fr; }
  .dx-savebar > :last-child { margin-left: 0; width: 100%; justify-content: center; }
}
`
