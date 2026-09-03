import { useEffect, useMemo, useState } from 'react'
import { useOutletContext, useSearchParams } from 'react-router-dom'
import { Building2, User, ClipboardPlus, Plus, Trash2, FileText, RefreshCw } from 'lucide-react'
import { useToast } from '@/hooks/useToast'
import { medicalApi } from '@/services/medicalApi'
import CaptureBlock from '@/components/medical/CaptureBlock'
import { S, FitnessPill, QcPill, ClearancePill, HealthScore, humanise } from '@/components/medical/MedicalBits'

/**
 * The Medical Examination Form — vendor, then worker, then the examination.
 *
 * Three deliberate choices:
 *
 *  - The worker list carries each worker's clearance state, so a doctor can see
 *    who is actually waiting on them before opening a form.
 *  - The history sits beside the form, not behind a tab. A re-examination is
 *    read against what was found last time, and hiding that invites repeating it.
 *  - The health score is computed by the server from what is entered. The field
 *    here is an OVERRIDE, left blank in the ordinary case, so a doctor who
 *    disagrees can say so and be recorded as having said so.
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

export default function DoctorExamination() {
  const { module, me } = useOutletContext()
  const toast = useToast()
  const [params, setParams] = useSearchParams()

  const [vendors, setVendors] = useState([])
  const [vendorId, setVendorId] = useState(params.get('vendor') || '')
  const [workers, setWorkers] = useState([])
  const [workerId, setWorkerId] = useState(params.get('worker') || '')
  const [detail, setDetail] = useState(null)

  const [form, setForm] = useState(BLANK)
  const [investigations, setInvestigations] = useState([{ name: '', result: '', remarks: '' }])
  const [conditions, setConditions] = useState('')
  const [surgeries, setSurgeries] = useState('')
  const [habits, setHabits] = useState('')
  const [capture, setCapture] = useState({ signature_data: '', capture_photo: '', geo_location: '' })
  const [reportFile, setReportFile] = useState(null)
  const [busy, setBusy] = useState(false)

  const set = (k, v) => setForm(f => ({ ...f, [k]: v }))

  /* ── Vendor → worker → history ────────────────────────────────────────── */

  useEffect(() => {
    setVendors([]); setWorkers([]); setDetail(null)
    medicalApi.doctor.vendors(module).then(setVendors).catch(() => setVendors([]))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [module])

  useEffect(() => {
    if (!vendorId) { setWorkers([]); return }
    medicalApi.doctor.workers(module, { vendor_id: vendorId }).then(setWorkers).catch(() => setWorkers([]))
  }, [module, vendorId])

  const loadWorker = (id) => {
    if (!id) { setDetail(null); return }
    medicalApi.doctor.worker(module, id).then(setDetail).catch(() => setDetail(null))
  }

  useEffect(() => { loadWorker(workerId) }, [module, workerId]) // eslint-disable-line react-hooks/exhaustive-deps

  const pickVendor = (id) => {
    setVendorId(id); setWorkerId(''); setDetail(null)
    setParams(id ? { vendor: id } : {})
  }

  const pickWorker = (id) => {
    setWorkerId(id)
    setParams(id ? { vendor: vendorId, worker: id } : { vendor: vendorId })
    // A re-examination starts from a blank sheet: carrying the last exam's
    // numbers forward would quietly turn a copy into a finding.
    setForm(BLANK)
    setInvestigations([{ name: '', result: '', remarks: '' }])
    setConditions(''); setSurgeries(''); setHabits('')
    setCapture({ signature_data: '', capture_photo: '', geo_location: '' })
  }

  const history = detail?.history
  const previous = history?.latest
  const isReexam = !!previous

  const worker = useMemo(
    () => workers.find(w => String(w.id) === String(workerId)),
    [workers, workerId],
  )

  /* ── Save ─────────────────────────────────────────────────────────────── */

  const submit = async () => {
    if (!workerId) return toast.error('Pick the worker being examined.')
    if (!me?.is_signable) return toast.error('Add your licence number in Profile first.')

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
      const res = await medicalApi.doctor.examine(module, workerId, payload)
      toast.success(isReexam ? 'Re-examination recorded.' : 'Examination recorded.')
      loadWorker(workerId)
      medicalApi.doctor.workers(module, { vendor_id: vendorId }).then(setWorkers)
      setForm(BLANK)
      setCapture({ signature_data: '', capture_photo: '', geo_location: '' })
      setInvestigations([{ name: '', result: '', remarks: '' }])
      setReportFile(null)
      // The doctor's next move is almost always to hand the prescription over.
      if (res?.data?.id) medicalApi.doctor.certificate(module, res.data.id)
    } catch (e) { toast.error(e) } finally { setBusy(false) }
  }

  return (
    <div>
      <header style={{ marginBottom: 16 }}>
        <h1 style={{ margin: 0, fontSize: 22, fontWeight: 900, color: 'var(--text-h)', display: 'flex', alignItems: 'center', gap: 8 }}>
          <ClipboardPlus size={20} /> {isReexam ? 'Re-examination' : 'Medical examination'}
        </h1>
        <p style={{ margin: '4px 0 0', color: 'var(--text-muted)', fontSize: 12.5 }}>
          {module === 'tpv' ? 'TPV vendors' : 'Purchase vendors'} · pick the vendor, then the worker, then record the examination.
        </p>
      </header>

      {/* ── Choose ────────────────────────────────────────────────────── */}
      <section className="pr-glass" style={{ padding: 14, borderRadius: 14, marginBottom: 14 }}>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(240px, 1fr))', gap: 12 }}>
          <div>
            <label style={S.label}><Building2 size={11} style={{ verticalAlign: -1 }} /> Vendor</label>
            <select value={vendorId} onChange={e => pickVendor(e.target.value)} style={{ ...S.select, width: '100%' }}>
              <option value="">Select a vendor…</option>
              {vendors.map(v => <option key={v.id} value={v.id}>{v.name}{v.code ? ` · ${v.code}` : ''}</option>)}
            </select>
          </div>
          <div>
            <label style={S.label}><User size={11} style={{ verticalAlign: -1 }} /> Worker</label>
            <select value={workerId} onChange={e => pickWorker(e.target.value)} disabled={!vendorId} style={{ ...S.select, width: '100%' }}>
              <option value="">{vendorId ? 'Select a worker…' : 'Pick a vendor first'}</option>
              {workers.map(w => <option key={w.id} value={w.id}>{w.name}{w.worker_code ? ` · ${w.worker_code}` : ''}</option>)}
            </select>
          </div>
        </div>

        {/* Who is actually waiting on a doctor. */}
        {vendorId && workers.length > 0 && !workerId && (
          <div style={{ marginTop: 12, maxHeight: 220, overflowY: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12.5 }}>
              <tbody>
                {workers.map(w => (
                  <tr key={w.id} onClick={() => pickWorker(String(w.id))} style={{ borderTop: '1px solid var(--border)', cursor: 'pointer' }}>
                    <td style={{ padding: '7px 8px', fontWeight: 700, color: 'var(--text-h)' }}>{w.name}</td>
                    <td style={{ padding: '7px 8px', color: 'var(--text-muted)' }}>{w.worker_code}</td>
                    <td style={{ padding: '7px 8px' }}><ClearancePill clearance={w.clearance} /></td>
                    <td style={{ padding: '7px 8px', color: 'var(--text-muted)' }}>{w.clearance?.message}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>

      {workerId && (
        <div style={{ display: 'grid', gridTemplateColumns: 'minmax(0, 2fr) minmax(260px, 1fr)', gap: 14, alignItems: 'start' }}>

          {/* ── The form ────────────────────────────────────────────── */}
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
                <div key={i} style={{ display: 'grid', gridTemplateColumns: '1.2fr 1fr 1.4fr auto', gap: 8, marginBottom: 8 }}>
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
              <CaptureBlock value={capture} onChange={setCapture} />
            </Card>

            <div style={{ display: 'flex', gap: 10, justifyContent: 'flex-end' }}>
              <button onClick={submit} disabled={busy || !me?.is_signable} style={{ ...S.btnPrimary, padding: '10px 22px', opacity: busy || !me?.is_signable ? 0.5 : 1 }}>
                {busy ? 'Saving…' : isReexam ? 'Record re-examination & issue' : 'Record examination & issue'}
              </button>
            </div>
          </div>

          {/* ── The worker, and what was found before ─────────────────── */}
          <aside style={{ display: 'flex', flexDirection: 'column', gap: 14, position: 'sticky', top: 12 }}>
            <Card title="Worker">
              <div style={{ fontSize: 15, fontWeight: 800, color: 'var(--text-h)' }}>{worker?.name || detail?.worker?.worker_name}</div>
              <div style={{ fontSize: 12, color: 'var(--text-muted)' }}>{worker?.worker_code} · {worker?.designation || '—'}</div>
              <div style={{ marginTop: 8 }}><ClearancePill clearance={history?.clearance} /></div>
              {history?.clearance?.message && (
                <p style={{ margin: '6px 0 0', fontSize: 12, color: 'var(--text-muted)' }}>{history.clearance.message}</p>
              )}
            </Card>

            <Card
              title="Medical history"
              action={
                <button type="button" onClick={() => loadWorker(workerId)} style={{ ...S.btn, padding: '4px 8px', fontSize: 11.5 }}>
                  <RefreshCw size={12} />
                </button>
              }
            >
              {history?.health_score != null && (
                <div style={{ marginBottom: 10 }}>
                  <div style={S.label}>Current score</div>
                  <HealthScore score={history.health_score} band={history.health_band} scale={history.score_scale} size="lg" />
                  {history.score_trend != null && (
                    <div style={{ fontSize: 11.5, color: history.score_trend >= 0 ? '#10b981' : '#ef4444', fontWeight: 700 }}>
                      {history.score_trend >= 0 ? '▲' : '▼'} {Math.abs(history.score_trend).toFixed(1)} since the last examination
                    </div>
                  )}
                </div>
              )}

              {(history?.records ?? []).length === 0 ? (
                <p style={{ margin: 0, fontSize: 12.5, color: 'var(--text-muted)' }}>No previous examination — this is their first.</p>
              ) : (
                <div style={{ display: 'flex', flexDirection: 'column', gap: 8, maxHeight: 320, overflowY: 'auto' }}>
                  {history.records.map(r => (
                    <div key={r.id} style={{ borderTop: '1px solid var(--border)', paddingTop: 8 }}>
                      <div style={{ display: 'flex', gap: 6, alignItems: 'center', flexWrap: 'wrap' }}>
                        <strong style={{ fontSize: 12.5, color: 'var(--text-h)' }}>{r.exam_date}</strong>
                        <FitnessPill status={r.fitness_status} />
                        <QcPill status={r.qc_status} />
                      </div>
                      <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginTop: 4 }}>
                        <HealthScore score={r.health_score} band={r.health_band} />
                        <button
                          type="button"
                          onClick={() => medicalApi.doctor.certificate(module, r.id)}
                          style={{ ...S.btn, padding: '2px 8px', fontSize: 11, marginLeft: 'auto' }}
                        >
                          <FileText size={11} /> PDF
                        </button>
                      </div>
                      {r.restrictions && <div style={{ fontSize: 11.5, color: 'var(--text-muted)', marginTop: 3 }}>{r.restrictions}</div>}
                    </div>
                  ))}
                </div>
              )}
            </Card>
          </aside>
        </div>
      )}
    </div>
  )
}

const splitList = (s) => s.split(',').map(x => x.trim()).filter(Boolean)

function Card({ title, action, children }) {
  return (
    <section className="pr-glass" style={{ padding: 14, borderRadius: 14 }}>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 10 }}>
        <h2 style={{ margin: 0, fontSize: 12.5, fontWeight: 800, color: 'var(--text-h)', textTransform: 'uppercase', letterSpacing: '0.05em' }}>{title}</h2>
        {action}
      </div>
      {children}
    </section>
  )
}

function Row({ children }) {
  return <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(140px, 1fr))', gap: 10, marginBottom: 10 }}>{children}</div>
}

function Field({ label, hint, children }) {
  return (
    <div>
      <label style={S.label}>{label}</label>
      {children}
      {hint && <div style={{ fontSize: 10.5, color: 'var(--text-muted)', marginTop: 3 }}>{hint}</div>}
    </div>
  )
}
