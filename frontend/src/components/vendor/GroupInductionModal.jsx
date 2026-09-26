import { useEffect, useMemo, useRef, useState } from 'react'
import { Overlay, ModalFooter, Field, TextInput, SelectInput, inputStyle, InfoBox, PRIMARY_GRADIENT } from '@/components/ui/kit3d'
import { useToast } from '@/components/ui/Toast'
import WorkerBulkPicker from './WorkerBulkPicker'

/**
 * Group Induction Session — ONE modal for every surface.
 *
 * TPV admin, TPV vendor portal, Purchase admin and Purchase vendor portal all
 * open this; only `engine` and `api` differ. It used to be four hand-copied
 * modals that preselected the whole roster, drew every worker as a chip and
 * asked each worker to sign a canvas — unusable at 1000 people.
 *
 * Now:
 *  - the picker (WorkerBulkPicker) starts with nothing selected (or just the
 *    worker whose wizard opened it) and defaults to workers READY for induction;
 *  - the TRAINER signs once, and the server stores that one signature on every
 *    selected worker's record;
 *  - one request saves the whole group (`bulkInduction`), and the server answers
 *    with who was saved and who was skipped and why — shown here as a summary.
 *
 * Closes only on ✕ / Cancel / Done, never on a backdrop click.
 */

export const INDUCTION_TYPES = [
  ['General Safety', 'General Safety'],
  ['Activity Specific', 'Activity Specific'],
  ['Site Specific', 'Site Specific'],
  ['Client Specific', 'Client Specific'],
  ['Emergency & Evacuation', 'Emergency & Evacuation'],
  ['Fire Safety', 'Fire Safety'],
  ['PPE Usage', 'PPE Usage'],
  ['Toolbox Talk', 'Toolbox Talk'],
]
export const TRAINER_PRESETS = [
  { group: 'Safety Team', items: ['Safety Officer – Rahul Sharma', 'Safety Supervisor – Priya Patel', 'HSE Lead – Amit Verma', 'HSSE Manager – Neha Singh', 'Safety Inspector – Ravi Kumar'] },
  { group: 'HR Team', items: ['HR Manager – Sunita Joshi', 'HR Executive – Deepak Nair', 'HR Coordinator – Anjali Mehta'] },
  { group: 'Site Management', items: ['Site Engineer – Vikram Rao', 'Project Manager – Suresh Pillai', 'Site Supervisor – Mohan Das'] },
  { group: 'Custom', items: ['Other / Custom Trainer...'] },
]
export const INDUCTION_TOPICS = ['Site Safety Rules', 'PPE Usage', 'Work at Height', 'Emergency Response', 'Fire Safety', 'First Aid', 'Manual Handling', 'Permit to Work']
const CUSTOM_TRAINER = 'Other / Custom Trainer...'
const MAX_WORKERS = 1000

/* ── Readiness, mirrored from the server's clearance rules ───────────────────
 * The server is the judge (a worker it refuses comes back as skipped, with the
 * reason); this only decides the picker's default "ready" view. It follows
 * MedicalClearanceMessage: a Fit / Fit-with-restrictions certificate that is
 * not waiting on, rejected or held by the quality team, and not expired.
 * A project-level "medical not applicable" bypass is not visible in the list
 * payload, so such workers show under "Show all" — and still save. */
const PASSING = ['Fit', 'Fit_With_Restrictions']
const QC_BLOCKING = ['Pending', 'Rejected', 'Hold']
const today = () => new Date().toISOString().slice(0, 10)

function medicalCleared(m, expiry) {
  if (!m) return false
  if (QC_BLOCKING.includes(m.qc_status)) return false
  if (!PASSING.includes(m.fitness_status)) return false
  return !(expiry && String(expiry).slice(0, 10) < today())
}

function finish(base, { inducted, cleared, locked, lockedLabel }) {
  const reason = inducted ? 'inducted' : locked ? 'locked' : !cleared ? 'medical' : 'ready'
  const reasonLabel = {
    inducted: 'Already inducted',
    locked: lockedLabel || 'Not editable',
    medical: 'Medical not cleared',
    ready: 'Ready',
  }[reason]
  return { ...base, ready: reason === 'ready', reason, reasonLabel }
}

/** A TPV worker row (tpvApi / portalApi `workers.list`). */
function normaliseTpv(w) {
  const m = w.medical
  const cleared = Number(w.medical_status) === 2 || medicalCleared(m, m?.valid_until)
  return finish({
    id: w.id, name: w.name, code: w.worker_code, status: w.status,
    trade: w.trade, skill: w.skill_category, site: w.site, project: w.project,
    vendor: w.vendor?.company_name,
  }, {
    inducted: !!w.induction && w.induction.passed !== false,
    cleared,
    // saveInduction refuses anything but a Draft worker.
    locked: w.status && w.status !== 'Draft',
    lockedLabel: w.status ? `${w.status} — not editable` : undefined,
  })
}

/** A Purchase worker row (admin list or the portal's, which carries readiness). */
function normalisePurchase(w) {
  const m = w.latest_medical ?? w.medicals?.[0] ?? null
  const ind = w.latest_induction ?? w.inductions?.[0] ?? null
  return finish({
    id: w.id, name: w.full_name, code: w.worker_code, status: w.status,
    trade: w.trade, skill: w.skill_category, site: w.site, project: w.project,
    vendor: w.vendor?.company_name,
  }, {
    inducted: w.readiness ? !!w.readiness.induction_ok : ind?.status === 'Completed',
    cleared: w.readiness ? !!w.readiness.medical_ok : medicalCleared(m, m?.expiry_date ?? m?.valid_until),
    locked: false,
  })
}

function errorText(e, fallback) {
  const data = e?.response?.data
  const firstField = data?.errors && Object.values(data.errors)[0]
  if (Array.isArray(firstField) && firstField[0]) return firstField[0]
  return data?.message || e?.message || fallback
}

/* ── Trainer signature pad ─────────────────────────────────────────────── */
function SignaturePad({ canvasRef, onInk }) {
  const drawing = useRef(false)

  // Scale from CSS pixels to canvas pixels, so the ink lands under the pen even
  // when the canvas is shrunk to fit a narrow screen.
  const point = (e) => {
    const c = canvasRef.current
    const r = c.getBoundingClientRect()
    const t = e.touches?.[0] ?? e
    return [(t.clientX - r.left) * (c.width / r.width), (t.clientY - r.top) * (c.height / r.height)]
  }
  const start = (e) => {
    const c = canvasRef.current; if (!c) return
    const ctx = c.getContext('2d')
    ctx.lineWidth = 2.4; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#111827'
    const [x, y] = point(e)
    ctx.beginPath(); ctx.moveTo(x, y)
    drawing.current = true
  }
  const move = (e) => {
    if (!drawing.current) return
    const c = canvasRef.current; if (!c) return
    const [x, y] = point(e)
    const ctx = c.getContext('2d')
    ctx.lineTo(x, y); ctx.stroke()
    onInk(true)
  }
  const stop = () => { drawing.current = false }

  return (
    <canvas ref={canvasRef} width={560} height={130}
      onMouseDown={start} onMouseMove={move} onMouseUp={stop} onMouseLeave={stop}
      onTouchStart={start} onTouchMove={move} onTouchEnd={stop}
      style={{ background: '#fff', border: '2px dashed var(--border)', borderRadius: 8, cursor: 'crosshair', display: 'block', width: '100%', maxWidth: 560, touchAction: 'none' }} />
  )
}

/**
 * @param {'tpv'|'purchase'} engine
 * @param {object}   api            the surface's client (tpvApi / portalApi / purchaseApi / purchasePortalApi)
 * @param {Array}    [workers]      the raw roster, if the caller already has it
 * @param {Function} [loadWorkers]  async () => raw roster, when it does not
 * @param {Array}    [preselectIds] ids to start selected — the wizard's own worker, or the list page's ticks
 * @param {Function} onClose        closed without saving anything
 * @param {Function} onCompleted    closed after a save ({saved, skipped}) — the caller refreshes
 */
export default function GroupInductionModal({ engine, api, workers: given, loadWorkers, preselectIds = [], onClose, onCompleted }) {
  const toast = useToast()
  const [raw, setRaw]         = useState(given || null)
  const [loadError, setLoadError] = useState('')
  const [selectedIds, setSelectedIds] = useState(() => [...new Set(preselectIds)])

  const [f, setF] = useState({
    induction_type: 'General Safety',
    trainer: 'Safety Officer – Rahul Sharma',
    custom_trainer: '',
    location: 'Site Office',
    duration_minutes: 15,
  })
  const [topics, setTopics] = useState(['Site Safety Rules', 'PPE Usage', 'Emergency Response'])
  const [hasInk, setHasInk] = useState(false)
  const [saving, setSaving] = useState(false)
  const [result, setResult] = useState(null)
  const canvasRef = useRef(null)

  // Loaded once, when the modal opens — a caller passing an inline function
  // must not trigger a reload of a 1000-row roster on every render.
  const loaderRef = useRef(loadWorkers)
  useEffect(() => {
    if (given || !loaderRef.current) return
    let live = true
    loaderRef.current()
      .then(list => { if (live) setRaw(Array.isArray(list) ? list : []) })
      .catch(e => { if (live) { setRaw([]); setLoadError(errorText(e, 'Could not load the workers list.')) } })
    return () => { live = false }
  }, [given])

  const workers = useMemo(
    () => (raw || []).map(engine === 'purchase' ? normalisePurchase : normaliseTpv),
    [raw, engine],
  )
  const byId = useMemo(() => new Map(workers.map(w => [w.id, w])), [workers])

  const set = (k) => (e) => setF(p => ({ ...p, [k]: e.target.value }))
  const toggleTopic = (t) => setTopics(p => (p.includes(t) ? p.filter(x => x !== t) : [...p, t]))
  const clearSignature = () => {
    const c = canvasRef.current; if (!c) return
    c.getContext('2d').clearRect(0, 0, c.width, c.height)
    setHasInk(false)
  }

  const trainer = (f.trainer === CUSTOM_TRAINER ? f.custom_trainer : f.trainer).trim()
  const count = selectedIds.length
  const blocker =
    count === 0 ? 'Select at least one worker.'
      : count > MAX_WORKERS ? `A session can hold at most ${MAX_WORKERS} workers.`
        : !trainer ? 'Trainer name is required.'
          : !f.location.trim() ? 'Location is required.'
            : !hasInk ? 'The trainer must sign below.'
              : null

  const buildPayload = (signature) => {
    const duration = Number(f.duration_minutes) || 15
    if (engine === 'purchase') {
      return {
        worker_ids: selectedIds,
        induction_date: today(),
        training_date: today(),
        status: 'Completed',
        passed: true,
        conducted_by: trainer,
        trainer_name: trainer,
        duration_minutes: duration,
        topics,
        // purchase_worker_inductions keeps type/location in remarks; the column is 500.
        remarks: [
          `Type: ${f.induction_type}`,
          `Location: ${f.location.trim()}`,
          `Duration: ${duration} min`,
          `Topics: ${topics.length ? topics.join(', ') : '—'}`,
          'Group session — trainer signed once for all attendees',
        ].join('\n').slice(0, 500),
        signature_data: signature,
      }
    }
    return {
      worker_ids: selectedIds,
      induction_type: f.induction_type,
      trainer,
      location: f.location.trim(),
      training_date: today(),
      duration_minutes: duration,
      topics,
      passed: true,
      signature_data: signature,
    }
  }

  const save = async () => {
    if (blocker) { toast.error(blocker); return }
    const signature = canvasRef.current?.toDataURL('image/png')
    setSaving(true)
    try {
      const payload = buildPayload(signature)
      const res = engine === 'purchase'
        ? await api.workforce.bulkInduction(payload)
        : await api.workers.bulkInduction(payload)
      setResult({ saved: res?.saved ?? [], skipped: res?.skipped ?? [] })
    } catch (e) {
      toast.error(errorText(e, 'Group induction could not be saved.'))
    } finally {
      setSaving(false)
    }
  }

  const close = () => {
    if (saving) return
    if (result) onCompleted?.(result)
    else onClose?.()
  }

  /* ── After the save: what happened, per worker ───────────────────────── */
  if (result) {
    const { saved, skipped } = result
    return (
      <Overlay onClose={close} width={640}>
        <h2 style={{ fontSize: 18, fontWeight: 900, color: 'var(--text-h)', margin: '0 0 14px' }}>👥 Group Induction — Result</h2>
        <div style={{ padding: '12px 16px', borderRadius: 10, background: saved.length ? 'rgba(16,185,129,0.12)' : 'rgba(239,68,68,0.10)', border: `1px solid ${saved.length ? 'rgba(16,185,129,0.35)' : 'rgba(239,68,68,0.3)'}`, marginBottom: 14 }}>
          <strong style={{ fontSize: 14, color: saved.length ? '#10b981' : '#ef4444' }}>
            Saved for {saved.length} worker{saved.length === 1 ? '' : 's'}.
          </strong>
          {skipped.length > 0 && (
            <span style={{ fontSize: 13, color: 'var(--text-h)', marginLeft: 6 }}>{skipped.length} skipped:</span>
          )}
        </div>
        {skipped.length > 0 && (
          <div style={{ maxHeight: 320, overflowY: 'auto', border: '1px solid var(--border)', borderRadius: 10, background: 'var(--bg-card)' }}>
            {skipped.map(s => {
              const w = byId.get(s.id)
              return (
                <div key={s.id} style={{ padding: '8px 14px', borderBottom: '1px solid var(--border)' }}>
                  <div style={{ fontSize: 12.5, fontWeight: 800, color: 'var(--text-h)' }}>
                    {s.name || w?.name || `Worker #${s.id}`}
                    {w?.code && <span style={{ color: 'var(--text-muted)', fontWeight: 500 }}> ({w.code})</span>}
                  </div>
                  <div style={{ fontSize: 12, color: '#f59e0b', marginTop: 2 }}>{s.reason}</div>
                </div>
              )
            })}
          </div>
        )}
        <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: 18 }}>
          <button type="button" onClick={close}
            style={{ padding: '9px 24px', borderRadius: 9, border: 'none', background: PRIMARY_GRADIENT, color: '#fff', fontWeight: 700, cursor: 'pointer', fontSize: 13 }}>
            Done
          </button>
        </div>
      </Overlay>
    )
  }

  /* ── The session ─────────────────────────────────────────────────────── */
  return (
    <Overlay onClose={close} width={860}>
      <h2 style={{ fontSize: 18, fontWeight: 900, color: 'var(--text-h)', margin: '0 0 4px' }}>
        👥 Group Induction Session ({count} Selected)
      </h2>
      <p style={{ fontSize: 12, color: 'var(--text-muted)', margin: '0 0 14px' }}>
        Pick the attendees, fill the session once, and the trainer signs once for everyone.
      </p>

      {raw === null ? (
        <div style={{ padding: 24, textAlign: 'center', fontSize: 13, color: 'var(--text-muted)', border: '1px solid var(--border)', borderRadius: 12, marginBottom: 16 }}>
          Loading workers…
        </div>
      ) : (
        <>
          {loadError && <InfoBox tone="danger">{loadError}</InfoBox>}
          <WorkerBulkPicker workers={workers} selectedIds={selectedIds} onChange={setSelectedIds} max={MAX_WORKERS} />
        </>
      )}

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(170px, 1fr))', gap: 12, marginBottom: 14 }}>
        <Field label="Induction Type *">
          <SelectInput value={f.induction_type} onChange={set('induction_type')} pairs options={INDUCTION_TYPES} />
        </Field>
        <Field label="Trainer *">
          <select value={f.trainer} onChange={set('trainer')} style={inputStyle}>
            {TRAINER_PRESETS.map(grp => (
              <optgroup key={grp.group} label={grp.group}>
                {grp.items.map(item => <option key={item} value={item}>{item}</option>)}
              </optgroup>
            ))}
          </select>
          {f.trainer === CUSTOM_TRAINER && (
            <input type="text" value={f.custom_trainer} onChange={set('custom_trainer')} placeholder="Enter trainer full name..." style={{ ...inputStyle, marginTop: 6 }} />
          )}
        </Field>
        <Field label="Location *"><TextInput value={f.location} onChange={set('location')} placeholder="e.g. Site Office" /></Field>
        <Field label="Duration (min)"><TextInput type="number" min={1} max={1440} value={f.duration_minutes} onChange={set('duration_minutes')} /></Field>
      </div>

      <h3 style={{ fontSize: 13, fontWeight: 800, color: 'var(--text-h)', margin: '0 0 8px' }}>📚 Topics Covered</h3>
      <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8, marginBottom: 16 }}>
        {INDUCTION_TOPICS.map(t => (
          <button type="button" key={t} onClick={() => toggleTopic(t)} style={{ padding: '5px 12px', borderRadius: 20, border: '1.5px solid', fontSize: 11, fontWeight: 800, cursor: 'pointer', background: topics.includes(t) ? '#7c3aed' : 'var(--bg-input)', color: topics.includes(t) ? '#fff' : 'var(--text-muted)', borderColor: topics.includes(t) ? '#7c3aed' : 'var(--border)' }}>
            {topics.includes(t) ? '✓ ' : '+ '}{t}
          </button>
        ))}
      </div>

      <div style={{ padding: 12, borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-input)' }}>
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 8, gap: 8, flexWrap: 'wrap' }}>
          <strong style={{ fontSize: 12.5, color: 'var(--text-h)' }}>✍ Trainer Signature *</strong>
          <span style={{ fontSize: 11.5, color: hasInk ? '#10b981' : 'var(--text-muted)', fontWeight: 700 }}>
            {hasInk ? `✓ Signed — applies to all ${count} selected` : `${trainer || 'The trainer'} signs once for the whole group`}
          </span>
        </div>
        <SignaturePad canvasRef={canvasRef} onInk={setHasInk} />
        <button type="button" onClick={clearSignature} style={{ marginTop: 8, padding: '5px 12px', borderRadius: 6, background: '#ef4444', color: '#fff', border: 'none', cursor: 'pointer', fontSize: 11, fontWeight: 800 }}>Clear signature</button>
      </div>

      {blocker && count > 0 && (
        <p style={{ margin: '12px 0 0', fontSize: 12, color: 'var(--text-muted)', textAlign: 'right' }}>{blocker}</p>
      )}
      <ModalFooter onClose={close} onConfirm={save} loading={saving} disabled={!!blocker}
        confirmLabel={`Save Group Induction (${count} Worker${count === 1 ? '' : 's'})`} />
    </Overlay>
  )
}
