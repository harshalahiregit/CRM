import { useState, useEffect, useCallback, useRef } from 'react'
import { useParams } from 'react-router-dom'
import {
  ClipboardCheck, Camera, RefreshCw, MapPin, Loader2, CheckCircle2, AlertTriangle,
  ShieldCheck, XCircle, Send, Save, Info,
} from 'lucide-react'
import { checklistFillApi } from '@/services/checklistFillApi'
import { KIT3D_STYLE, labelStyle, inputStyle } from '@/components/ui/kit3d'
import { answerIsRisky, fmtDate } from '@/modules/compliance/constants'

/**
 * Public checklist fill-in — phone-first.
 *
 * No auth: the 48-char token in the URL is the credential. Rendered for a
 * vendor's site supervisor standing on a site, on their own phone, probably in
 * sunlight and possibly with one glove off. Everything here is sized for a thumb.
 */
export default function ChecklistFill() {
  const { token } = useParams()
  const [form, setForm]         = useState(null)
  const [answers, setAnswers]   = useState({})
  const [loading, setLoading]   = useState(true)
  const [loadErr, setLoadErr]   = useState(null)
  const [submitting, setSub]    = useState(false)
  const [saveState, setSave]    = useState('idle')   // idle | saving | saved
  const [submitErr, setSubErr]  = useState(null)
  const [done, setDone]         = useState(false)
  const [selfie, setSelfie]     = useState(null)
  const geo = useGeolocation()

  useEffect(() => {
    checklistFillApi.form(token)
      .then(f => { setForm(f); setAnswers(f.responses || {}); setLoading(false) })
      .catch(e => { setLoadErr(e?.response?.data?.message || 'This checklist link is not valid.'); setLoading(false) })
  }, [token])

  const questions = (form?.template?.definition?.sections || []).flatMap(s => s.questions || [])
  const answered  = questions.filter(q => {
    const a = answers[q.key]
    return a?.na || (a?.value !== undefined && a?.value !== null && a?.value !== '')
  }).length

  const setAnswer = (key, patch) =>
    setAnswers(prev => ({ ...prev, [key]: { ...(prev[key] || {}), ...patch } }))

  const save = async () => {
    setSave('saving'); setSubErr(null)
    try {
      await checklistFillApi.save(token, answers)
      setSave('saved'); setTimeout(() => setSave('idle'), 2200)
    } catch (e) {
      setSave('idle'); setSubErr(e?.response?.data?.message || 'Could not save your progress.')
    }
  }

  const submit = async () => {
    setSub(true); setSubErr(null)
    try {
      await checklistFillApi.submit(token, {
        responses: answers,
        latitude:  geo.coords?.lat ?? null,
        longitude: geo.coords?.lng ?? null,
        selfie,
      })
      setDone(true)
    } catch (e) {
      setSubErr(e?.response?.data?.message || 'Could not submit. Please check your answers.')
    } finally {
      setSub(false)
    }
  }

  if (loading) return <Shell><Centered><Loader2 size={30} className="cf-spin" style={{ color: '#a78bfa' }} /><p style={muted}>Loading checklist…</p></Centered></Shell>

  if (loadErr) {
    return (
      <Shell>
        <Centered>
          <Disc color="#ef4444" icon={XCircle} />
          <h1 style={{ fontSize: 20, fontWeight: 900, color: 'var(--text-h)', margin: '14px 0 6px' }}>Link not valid</h1>
          <p style={{ ...muted, maxWidth: 300 }}>{loadErr}</p>
          <p style={{ ...muted, fontSize: 12, marginTop: 10 }}>If you already submitted this checklist, no further action is needed.</p>
        </Centered>
      </Shell>
    )
  }

  if (done) {
    return (
      <Shell>
        <Centered>
          <Disc color="#10b981" icon={CheckCircle2} />
          <h1 style={{ fontSize: 22, fontWeight: 900, color: 'var(--text-h)', margin: '14px 0 6px' }}>Submitted</h1>
          <p style={{ ...muted, maxWidth: 300 }}>Thank you. This checklist has gone to the safety team for review.</p>
          {/* No score shown on purpose — the risk band is for the approval chain,
              and echoing it here would let anyone with the link probe the rules. */}
          <div className="pr-glass" style={{ marginTop: 18, padding: '12px 16px', borderRadius: 14 }}>
            <p style={{ ...muted, fontSize: 12, margin: 0 }}>You can close this page.</p>
          </div>
        </Centered>
      </Shell>
    )
  }

  const fillable = form.fillable

  return (
    <Shell>
      <div style={{ padding: '18px 14px 40px', maxWidth: 560, margin: '0 auto' }}>
        {/* Header */}
        <div className="pr-glass" style={{ padding: 18, marginBottom: 14 }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 11 }}>
            <span style={{ width: 42, height: 42, borderRadius: 14, display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0, background: 'linear-gradient(145deg,#a78bfa,#7C3AED)', boxShadow: '0 8px 20px -4px #7C3AED88, inset 0 1px 0 rgba(255,255,255,.35)' }}>
              <ClipboardCheck size={20} color="#fff" />
            </span>
            <div style={{ minWidth: 0 }}>
              <p className="label-caps" style={{ margin: 0, fontSize: 10, fontWeight: 800, letterSpacing: '0.08em', color: '#a78bfa' }}>
                {form.template?.code || 'CHECKLIST'}
              </p>
              <h1 style={{ margin: '2px 0 0', fontSize: 17, fontWeight: 900, color: 'var(--text-h)', letterSpacing: '-0.01em', lineHeight: 1.25 }}>{form.title}</h1>
            </div>
          </div>
          {(form.subject || form.due_date) && (
            <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginTop: 12 }}>
              {form.subject && <Chip>{form.subject.label}: <strong>{form.subject.name}</strong></Chip>}
              {form.due_date && <Chip>Due {fmtDate(form.due_date)}</Chip>}
            </div>
          )}
          {/* Progress — a supervisor on a phone needs to know how much is left. */}
          <div style={{ marginTop: 14 }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 5 }}>
              <span style={{ fontSize: 11, color: 'var(--text-muted)', fontWeight: 600 }}>Progress</span>
              <span style={{ fontSize: 11, color: 'var(--text-h)', fontWeight: 800 }}>{answered} of {questions.length}</span>
            </div>
            <div className="pr-bar"><span style={{ width: `${questions.length ? (answered / questions.length) * 100 : 0}%` }} /></div>
          </div>
        </div>

        {!fillable && (
          <Banner tone="#f59e0b" icon={Info}>
            This checklist is <strong>{form.status?.replace('_', ' ')}</strong> and can no longer be edited.
          </Banner>
        )}

        {/* Sections */}
        {(form.template?.definition?.sections || []).map(section => (
          <div key={section.key} className="pr-glass" style={{ padding: 18, marginBottom: 14 }}>
            <h2 style={{ margin: '0 0 3px', fontSize: 14, fontWeight: 800, color: 'var(--text-h)' }}>{section.title}</h2>
            <p style={{ ...muted, fontSize: 11, margin: '0 0 14px' }}>{(section.questions || []).length} question{(section.questions || []).length === 1 ? '' : 's'}</p>
            <div style={{ display: 'flex', flexDirection: 'column', gap: 20 }}>
              {(section.questions || []).map(q => (
                <Question key={q.key} q={q} answer={answers[q.key] || {}} disabled={!fillable}
                  onChange={patch => setAnswer(q.key, patch)} />
              ))}
            </div>
          </div>
        ))}

        {fillable && (
          <>
            <SelfieCard file={selfie} onPick={setSelfie} onError={setSubErr} />
            <LocationCard geo={geo} />

            {submitErr && <Banner tone="#ef4444" icon={AlertTriangle}>{submitErr}</Banner>}

            <div style={{ display: 'flex', gap: 10, marginTop: 16 }}>
              <button onClick={save} disabled={saveState === 'saving'}
                style={{ ...btnBase, flex: '0 0 auto', padding: '0 18px', background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-muted)' }}>
                {saveState === 'saving' ? <Loader2 size={16} className="cf-spin" /> : <Save size={16} />}
                {saveState === 'saved' ? 'Saved' : 'Save'}
              </button>
              <button onClick={submit} disabled={submitting}
                style={{ ...btnBase, flex: 1, color: '#fff', border: 'none',
                  background: 'linear-gradient(145deg,#a78bfa,#7C3AED)',
                  boxShadow: '0 10px 24px -6px rgba(124,58,237,.6), inset 0 1px 0 rgba(255,255,255,.3)',
                  opacity: submitting ? 0.7 : 1 }}>
                {submitting ? <Loader2 size={17} className="cf-spin" /> : <Send size={17} />}
                {submitting ? 'Submitting…' : 'Submit checklist'}
              </button>
            </div>
            <p style={{ ...muted, fontSize: 11, textAlign: 'center', marginTop: 10 }}>
              Your answers are scored by the safety team after you submit.
            </p>
          </>
        )}
      </div>
    </Shell>
  )
}

/* ── Geolocation ──────────────────────────────────────────────────────────── */
/**
 * Wraps navigator.geolocation with the states a phone on a site actually hits.
 *
 * Location is best-effort by design: a supervisor inside a steel structure often
 * has no fix, and refusing the submission for that would just teach people to
 * fill the form in the car park — worse evidence, not better.
 */
function useGeolocation() {
  const [state, setState] = useState({ status: 'idle', coords: null, error: null })

  const request = useCallback(() => {
    if (!('geolocation' in navigator)) {
      setState({ status: 'unsupported', coords: null, error: 'This device cannot report a location.' })
      return
    }
    setState({ status: 'fetching', coords: null, error: null })
    navigator.geolocation.getCurrentPosition(
      pos => setState({
        status: 'ok',
        coords: { lat: pos.coords.latitude, lng: pos.coords.longitude, accuracy: pos.coords.accuracy },
        error: null,
      }),
      err => setState({
        status: 'error',
        coords: null,
        error: {
          1: 'Location permission was denied.',
          2: 'Your device could not get a fix — you may be indoors or under cover.',
          3: 'Getting your location took too long.',
        }[err.code] || 'Location is unavailable.',
      }),
      { enableHighAccuracy: true, timeout: 15000, maximumAge: 60000 },
    )
  }, [])

  useEffect(() => { request() }, [request])

  return { ...state, retry: request }
}

function LocationCard({ geo }) {
  const denied = geo.status === 'error' || geo.status === 'unsupported'

  return (
    <div className="pr-glass" style={{ padding: 16, marginBottom: 12 }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: geo.status === 'idle' ? 0 : 12 }}>
        <span style={{ width: 34, height: 34, borderRadius: 11, display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0,
          background: geo.status === 'ok' ? 'rgba(16,185,129,0.14)' : denied ? 'rgba(245,158,11,0.14)' : 'rgba(14,165,233,0.14)' }}>
          <MapPin size={16} style={{ color: geo.status === 'ok' ? '#10b981' : denied ? '#f59e0b' : '#0ea5e9' }} />
        </span>
        <div style={{ flex: 1, minWidth: 0 }}>
          <div style={{ fontSize: 13, fontWeight: 800, color: 'var(--text-h)' }}>Location</div>
          <div style={{ ...muted, fontSize: 11 }}>Recorded with your submission</div>
        </div>
      </div>

      {geo.status === 'fetching' && (
        <div style={{ display: 'flex', alignItems: 'center', gap: 9, padding: '11px 13px', borderRadius: 12, background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
          <Loader2 size={15} className="cf-spin" style={{ color: '#0ea5e9', flexShrink: 0 }} />
          <span style={{ fontSize: 12.5, color: 'var(--text-muted)' }}>Fetching secure location…</span>
        </div>
      )}

      {geo.status === 'ok' && (
        // Read-only chip: there is no input here, so the coordinates cannot be
        // typed over. See the note in the summary — this stops casual editing,
        // it is not proof of presence.
        <div style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '11px 13px', borderRadius: 12,
          background: 'rgba(16,185,129,0.08)', border: '1px solid rgba(16,185,129,0.32)',
          boxShadow: 'inset 0 1px 0 rgba(255,255,255,.08)' }}>
          <ShieldCheck size={15} style={{ color: '#10b981', flexShrink: 0 }} />
          <div style={{ flex: 1, minWidth: 0 }}>
            <div style={{ fontSize: 12.5, fontWeight: 800, color: '#10b981' }}>Location captured</div>
            <div style={{ fontSize: 11, color: 'var(--text-muted)', fontVariantNumeric: 'tabular-nums' }}>
              {geo.coords.lat.toFixed(5)}, {geo.coords.lng.toFixed(5)}
              {geo.coords.accuracy != null && ` · ±${Math.round(geo.coords.accuracy)}m`}
            </div>
          </div>
        </div>
      )}

      {denied && (
        <div style={{ padding: '11px 13px', borderRadius: 12, background: 'rgba(245,158,11,0.08)', border: '1px solid rgba(245,158,11,0.32)' }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 6 }}>
            <AlertTriangle size={14} style={{ color: '#f59e0b', flexShrink: 0 }} />
            <span style={{ fontSize: 12.5, fontWeight: 700, color: '#f59e0b' }}>{geo.error}</span>
          </div>
          {/* The dead end to avoid: a supervisor who denied the prompt cannot
              re-grant it from here, so say what to do AND let them continue. */}
          <p style={{ ...muted, fontSize: 11, margin: '0 0 9px' }}>
            You can still submit without it. To include your location, allow access in your browser settings and try again.
          </p>
          <button onClick={geo.retry}
            style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '7px 12px', borderRadius: 9, cursor: 'pointer',
              background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-h)', fontSize: 12, fontWeight: 700 }}>
            <RefreshCw size={12} /> Try again
          </button>
        </div>
      )}
    </div>
  )
}

/* ── Selfie ───────────────────────────────────────────────────────────────── */
const MAX_SELFIE_MB = 5

function SelfieCard({ file, onPick, onError }) {
  const inputRef = useRef(null)
  const [preview, setPreview] = useState(null)

  // Object URLs leak until revoked — a supervisor retaking three times on a
  // phone shouldn't strand three full-size bitmaps in memory.
  useEffect(() => {
    if (!file) { setPreview(null); return }
    const url = URL.createObjectURL(file)
    setPreview(url)

    return () => URL.revokeObjectURL(url)
  }, [file])

  const pick = (e) => {
    const f = e.target.files?.[0]
    if (!f) return
    if (f.size > MAX_SELFIE_MB * 1024 * 1024) {
      onError(`That photo is ${(f.size / 1024 / 1024).toFixed(1)} MB — the limit is ${MAX_SELFIE_MB} MB. Try again with a lower camera quality.`)
      e.target.value = ''
      return
    }
    onError(null)
    onPick(f)
  }

  const open = () => inputRef.current?.click()

  return (
    <div className="pr-glass" style={{ padding: 16, marginBottom: 12 }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 12 }}>
        <span style={{ width: 34, height: 34, borderRadius: 11, display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0,
          background: file ? 'rgba(16,185,129,0.14)' : 'rgba(124,58,237,0.14)' }}>
          <Camera size={16} style={{ color: file ? '#10b981' : '#a78bfa' }} />
        </span>
        <div style={{ flex: 1, minWidth: 0 }}>
          <div style={{ fontSize: 13, fontWeight: 800, color: 'var(--text-h)' }}>Photo of you on site</div>
          <div style={{ ...muted, fontSize: 11 }}>Confirms who completed this walk</div>
        </div>
      </div>

      {/* capture="user" asks the phone for the front camera directly rather than
          a file browser. Desktop quietly falls back to a file picker. */}
      <input ref={inputRef} type="file" accept="image/*" capture="user" onChange={pick}
        style={{ display: 'none' }} data-testid="selfie-input" />

      {!preview ? (
        <button onClick={open} className="pr-node" data-testid="selfie-trigger"
          style={{ width: '100%', minHeight: 132, cursor: 'pointer', borderRadius: 14,
            display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', gap: 8,
            background: 'linear-gradient(150deg, rgba(124,58,237,.16), rgba(124,58,237,.04))',
            border: '1.5px dashed rgba(124,58,237,.45)', boxShadow: 'inset 0 1px 0 rgba(255,255,255,.1)' }}>
          <span style={{ width: 46, height: 46, borderRadius: '50%', display: 'flex', alignItems: 'center', justifyContent: 'center',
            background: 'linear-gradient(145deg,#a78bfa,#7C3AED)', boxShadow: '0 8px 20px -4px #7C3AED99, inset 0 1px 0 rgba(255,255,255,.4)' }}>
            <Camera size={21} color="#fff" />
          </span>
          <span style={{ fontSize: 13.5, fontWeight: 800, color: 'var(--text-h)' }}>Take a photo</span>
          <span style={{ ...muted, fontSize: 11 }}>Opens your camera · optional</span>
        </button>
      ) : (
        <div className="pr-pop">
          <div style={{ position: 'relative', borderRadius: 14, overflow: 'hidden', border: '1px solid rgba(16,185,129,0.4)', boxShadow: '0 12px 30px -12px rgba(0,0,0,.5)' }}>
            <img src={preview} alt="Your submission photo" style={{ display: 'block', width: '100%', maxHeight: 260, objectFit: 'cover' }} />
            <div style={{ position: 'absolute', top: 9, left: 9, display: 'inline-flex', alignItems: 'center', gap: 5, padding: '4px 9px', borderRadius: 999,
              background: 'rgba(16,185,129,0.9)', backdropFilter: 'blur(6px)' }}>
              <CheckCircle2 size={12} color="#fff" />
              <span style={{ fontSize: 10.5, fontWeight: 800, color: '#fff' }}>Captured</span>
            </div>
          </div>
          <div style={{ display: 'flex', gap: 8, marginTop: 9 }}>
            <button onClick={open} data-testid="selfie-retake"
              style={{ ...btnBase, height: 42, flex: 1, fontSize: 12.5, background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-h)' }}>
              <RefreshCw size={14} /> Retake
            </button>
            <button onClick={() => onPick(null)}
              style={{ ...btnBase, height: 42, flex: '0 0 auto', padding: '0 14px', fontSize: 12.5, background: 'transparent', border: '1px solid var(--border)', color: 'var(--text-muted)' }}>
              Remove
            </button>
          </div>
        </div>
      )}
    </div>
  )
}

/* ── Question renderers ───────────────────────────────────────────────────── */
function Question({ q, answer, onChange, disabled }) {
  const na    = !!answer.na
  const risky = !na && answerIsRisky(q, answer.value)
  const needsRemark = risky && !!q.remark_when_risky

  return (
    <div style={{ opacity: na ? 0.55 : 1 }}>
      <div style={{ display: 'flex', alignItems: 'flex-start', gap: 8, marginBottom: 9 }}>
        <div style={{ flex: 1, minWidth: 0 }}>
          <label style={{ fontSize: 13.5, fontWeight: 700, color: 'var(--text-h)', lineHeight: 1.35, display: 'block' }}>
            {q.label}
            {q.required && <span style={{ color: '#ef4444', marginLeft: 4 }}>*</span>}
          </label>
          {q.hint && <p style={{ ...muted, fontSize: 11, margin: '3px 0 0' }}>{q.hint}</p>}
        </div>
        {q.allow_na && (
          <button onClick={() => !disabled && onChange({ na: !na, value: null })} disabled={disabled}
            style={{ flexShrink: 0, padding: '4px 9px', borderRadius: 999, fontSize: 10.5, fontWeight: 800, cursor: disabled ? 'default' : 'pointer',
              background: na ? 'rgba(148,163,184,.22)' : 'transparent', border: '1px solid var(--border)',
              color: na ? 'var(--text-h)' : 'var(--text-muted)' }}>
            N/A
          </button>
        )}
      </div>

      {!na && (
        <>
          {q.type === 'boolean' && <BooleanField q={q} value={answer.value} disabled={disabled} onChange={v => onChange({ value: v })} />}
          {q.type === 'choice'  && <ChoiceField  q={q} value={answer.value} disabled={disabled} onChange={v => onChange({ value: v })} />}
          {q.type === 'number'  && (
            <input type="number" inputMode="numeric" value={answer.value ?? ''} disabled={disabled}
              min={q.min} max={q.max} onChange={e => onChange({ value: e.target.value === '' ? null : Number(e.target.value) })}
              style={{ ...inputStyle, height: 46, fontSize: 15 }} />
          )}
          {q.type === 'text' && (
            <textarea rows={3} value={answer.value ?? ''} disabled={disabled}
              onChange={e => onChange({ value: e.target.value })}
              style={{ ...inputStyle, minHeight: 74, resize: 'vertical', fontSize: 14, lineHeight: 1.5 }} />
          )}
          {q.type === 'date' && (
            <input type="date" value={answer.value ?? ''} disabled={disabled}
              onChange={e => onChange({ value: e.target.value })}
              style={{ ...inputStyle, height: 46, fontSize: 15 }} />
          )}
        </>
      )}

      {/* Revealed as they answer, not sprung on them in a 422 after submitting.
          The server re-checks this — the client is just being polite. */}
      {needsRemark && !disabled && (
        <div className="pr-pop" style={{ marginTop: 9 }}>
          <label style={{ ...labelStyle, color: '#f59e0b', marginBottom: 5 }}>Explain this answer *</label>
          <textarea rows={2} value={answer.remark ?? ''} onChange={e => onChange({ remark: e.target.value })}
            placeholder="What did you see, and what happens next?"
            style={{ ...inputStyle, minHeight: 58, resize: 'vertical', fontSize: 13.5, borderColor: 'rgba(245,158,11,.5)' }} />
        </div>
      )}
    </div>
  )
}

function BooleanField({ q, value, onChange, disabled }) {
  const opts = [[true, q.true_label || 'Yes'], [false, q.false_label || 'No']]

  return (
    <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 8 }}>
      {opts.map(([v, label]) => {
        const on = value === v
        // Colour follows meaning, not the value: which answer is the safe one is
        // the template's call (risk_when), so "No" is green when no is correct.
        const isRisk = v === (q.risk_when ?? false)
        const tone = isRisk ? '#ef4444' : '#10b981'

        return (
          <button key={String(v)} onClick={() => !disabled && onChange(v)} disabled={disabled} className="pr-node"
            data-testid={`q-${q.key}-${String(v)}`}
            style={{ height: 48, borderRadius: 12, cursor: disabled ? 'default' : 'pointer', fontSize: 14, fontWeight: 800,
              background: on ? `${tone}22` : 'var(--bg-input)',
              border: on ? `1.5px solid ${tone}` : '1px solid var(--border)',
              color: on ? tone : 'var(--text-muted)',
              boxShadow: on ? `0 6px 16px -6px ${tone}88, inset 0 1px 0 rgba(255,255,255,.1)` : 'none' }}>
            {label}
          </button>
        )
      })}
    </div>
  )
}

function ChoiceField({ q, value, onChange, disabled }) {
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 7 }}>
      {(q.options || []).map(o => {
        const on = value === o.value
        const risky = Number(o.risk || 0) > 0
        const tone = risky ? (o.critical ? '#ef4444' : '#f59e0b') : '#10b981'

        return (
          <button key={o.value} onClick={() => !disabled && onChange(o.value)} disabled={disabled} className="pr-node"
            data-testid={`q-${q.key}-${o.value}`}
            style={{ minHeight: 46, borderRadius: 12, cursor: disabled ? 'default' : 'pointer', textAlign: 'left',
              padding: '10px 13px', display: 'flex', alignItems: 'center', gap: 10,
              background: on ? `${tone}1e` : 'var(--bg-input)',
              border: on ? `1.5px solid ${tone}` : '1px solid var(--border)',
              boxShadow: on ? `0 6px 16px -6px ${tone}77` : 'none' }}>
            <span style={{ width: 16, height: 16, borderRadius: '50%', flexShrink: 0, display: 'flex', alignItems: 'center', justifyContent: 'center',
              border: `2px solid ${on ? tone : 'var(--border)'}` }}>
              {on && <span style={{ width: 7, height: 7, borderRadius: '50%', background: tone }} />}
            </span>
            <span style={{ fontSize: 13.5, fontWeight: on ? 800 : 600, color: on ? 'var(--text-h)' : 'var(--text-muted)' }}>
              {o.label || o.value}
            </span>
          </button>
        )
      })}
    </div>
  )
}

/* ── Chrome ───────────────────────────────────────────────────────────────── */
const muted = { color: 'var(--text-muted)', fontSize: 13, margin: '8px 0 0' }
const btnBase = {
  height: 50, borderRadius: 13, cursor: 'pointer', fontSize: 14.5, fontWeight: 800,
  display: 'inline-flex', alignItems: 'center', justifyContent: 'center', gap: 7,
}

function Shell({ children }) {
  return (
    <div style={{ minHeight: '100vh', background: 'var(--bg-global)' }}>
      <style>{KIT3D_STYLE}</style>
      <style>{`
        @keyframes cfSpin { to { transform: rotate(360deg) } }
        .cf-spin { animation: cfSpin .9s linear infinite; }
      `}</style>
      {children}
    </div>
  )
}

const Centered = ({ children }) => (
  <div style={{ minHeight: '100vh', display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', padding: 24, textAlign: 'center' }}>
    {children}
  </div>
)

const Disc = ({ color, icon: Icon }) => (
  <div style={{ width: 84, height: 84, borderRadius: '50%', display: 'flex', alignItems: 'center', justifyContent: 'center',
    background: `${color}1a`, border: `2px solid ${color}55`, boxShadow: `0 16px 40px -12px ${color}88` }}>
    <Icon size={40} style={{ color }} />
  </div>
)

const Chip = ({ children }) => (
  <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5, padding: '4px 10px', borderRadius: 999,
    background: 'var(--bg-input)', border: '1px solid var(--border)', fontSize: 11, color: 'var(--text-muted)' }}>
    {children}
  </span>
)

const Banner = ({ tone, icon: Icon, children }) => (
  <div style={{ display: 'flex', alignItems: 'flex-start', gap: 9, padding: '11px 13px', borderRadius: 12, marginBottom: 12,
    background: `${tone}12`, border: `1px solid ${tone}55` }}>
    <Icon size={15} style={{ color: tone, flexShrink: 0, marginTop: 1 }} />
    <span style={{ fontSize: 12.5, color: 'var(--text-h)', lineHeight: 1.45 }}>{children}</span>
  </div>
)
