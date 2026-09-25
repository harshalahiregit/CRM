import { useState, useEffect } from 'react'
import { Plus, Trash2, Save, RotateCcw, AlertTriangle, Loader2, ChevronDown, ChevronRight } from 'lucide-react'
import { purchaseApi } from '@/services/purchaseApi'

/**
 * The prequalification questionnaire, edited here instead of in a PHP file.
 *
 * Two reported issues had the same cause: "admin should be able to set the
 * pre-qualification questions", and "the form has too many drop-downs — how do
 * I set the drop-down pointers from settings?" Every drop-down on the vendor's
 * prequalification form IS a question below, and until now the only way to add,
 * remove or re-word one was a developer and a deploy.
 *
 * The shape is sections → questions → answers, and each answer carries a score.
 * A vendor's result is the sum of the scores they picked, out of the maximum
 * available, banded into Qualified / Conditional / Not Qualified — so the score
 * column is the whole reason a question exists, and it is shown on every row
 * rather than hidden behind an edit.
 */

const emptyAnswer = () => ({ key: '', label: '', points: 0 })
const emptyQuestion = () => ({ key: '', label: '', options: [emptyAnswer(), emptyAnswer()] })
const emptySection = () => ({ key: '', label: '', questions: [emptyQuestion()] })

/** The API's keyed objects → the arrays this editor works in (order matters here). */
const toForm = (sections = {}) =>
  Object.entries(sections).map(([key, s]) => ({
    key,
    label: s.label || '',
    questions: Object.entries(s.questions || {}).map(([qKey, q]) => ({
      key: qKey,
      label: q.label || '',
      options: Object.entries(q.options || {}).map(([oKey, o]) => ({
        key: oKey, label: o.label || '', points: o.points ?? 0,
      })),
    })),
  }))

/** Back to the keyed shape the API stores. The server re-normalises the keys. */
const toPayload = (form) => {
  const out = {}
  form.forEach((s, si) => {
    const sKey = s.key || s.label || `section_${si + 1}`
    const questions = {}
    s.questions.forEach((q, qi) => {
      const qKey = q.key || q.label || `question_${qi + 1}`
      const options = {}
      q.options.forEach((o, oi) => {
        const oKey = o.key || o.label || `answer_${oi + 1}`
        options[oKey] = { label: o.label, points: Number(o.points) || 0 }
      })
      questions[qKey] = { label: q.label, options }
    })
    out[sKey] = { label: s.label, questions }
  })
  return out
}

export default function PrequalificationCatalogueTab({ canEdit }) {
  const [form, setForm] = useState([])
  const [customised, setCustomised] = useState(false)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [err, setErr] = useState('')
  const [msg, setMsg] = useState('')
  const [open, setOpen] = useState(() => new Set([0]))

  const load = () => {
    setLoading(true); setErr('')
    purchaseApi.prequalificationCatalogue.get()
      .then(d => { setForm(toForm(d.sections)); setCustomised(!!d.customised) })
      .catch(e => setErr(e?.message || 'Could not load the questionnaire.'))
      .finally(() => setLoading(false))
  }
  useEffect(load, [])

  const toggle = (i) => setOpen(s => {
    const next = new Set(s); next.has(i) ? next.delete(i) : next.add(i); return next
  })

  const editSection = (si, patch) => setForm(f => f.map((s, i) => i === si ? { ...s, ...patch } : s))
  const editQuestion = (si, qi, patch) => setForm(f => f.map((s, i) => i !== si ? s : {
    ...s, questions: s.questions.map((q, j) => j === qi ? { ...q, ...patch } : q),
  }))
  const editOption = (si, qi, oi, patch) => setForm(f => f.map((s, i) => i !== si ? s : {
    ...s,
    questions: s.questions.map((q, j) => j !== qi ? q : {
      ...q, options: q.options.map((o, k) => k === oi ? { ...o, ...patch } : o),
    }),
  }))

  const save = async () => {
    setSaving(true); setErr(''); setMsg('')
    try {
      const res = await purchaseApi.prequalificationCatalogue.save(toPayload(form))
      setForm(toForm(res.sections)); setCustomised(true)
      setMsg('Questionnaire saved. New assessments use it from now on.')
    } catch (e) {
      // The server checks the things that would break scoring — a question with
      // one answer, answers that all score the same, an empty section — and its
      // message names the offending question. Showing it verbatim is the point.
      setErr(e?.message || 'The questionnaire could not be saved.')
    } finally { setSaving(false) }
  }

  const reset = async () => {
    setSaving(true); setErr(''); setMsg('')
    try {
      const res = await purchaseApi.prequalificationCatalogue.reset()
      setForm(toForm(res.sections)); setCustomised(false)
      setMsg('Back to the standard questionnaire.')
    } catch (e) {
      setErr(e?.message || 'Could not reset the questionnaire.')
    } finally { setSaving(false) }
  }

  if (loading) return <div style={{ padding: 24, color: 'var(--text-muted)' }}><Loader2 size={18} className="animate-spin" /></div>

  return (
    <div>
      <div style={{ display: 'flex', alignItems: 'flex-start', gap: 12, marginBottom: 14, flexWrap: 'wrap' }}>
        <div style={{ flex: 1, minWidth: 260 }}>
          <h3 style={{ margin: 0, fontSize: 14, fontWeight: 800, color: 'var(--text-h)' }}>Prequalification questionnaire</h3>
          <p style={{ margin: '4px 0 0', fontSize: 12, color: 'var(--text-muted)', lineHeight: 1.5 }}>
            Every drop-down on a vendor’s prequalification form is a question here. Each answer
            carries a score; a vendor’s result is what they scored out of the maximum available.
            {customised
              ? ' This workspace is using its own questionnaire.'
              : ' This workspace is using the standard questionnaire.'}
          </p>
        </div>
        {canEdit && (
          <div style={{ display: 'flex', gap: 8 }}>
            {customised && (
              <button onClick={reset} disabled={saving} style={btn()}>
                <RotateCcw size={13} /> Back to standard
              </button>
            )}
            <button onClick={save} disabled={saving} style={btn(true)}>
              <Save size={13} /> {saving ? 'Saving…' : 'Save questionnaire'}
            </button>
          </div>
        )}
      </div>

      {err && (
        <p style={notice('var(--color-danger-500)')}>
          <AlertTriangle size={13} style={{ marginRight: 6, verticalAlign: -2 }} />{err}
        </p>
      )}
      {msg && <p style={notice('#10b981')}>{msg}</p>}

      {form.map((section, si) => (
        <div key={si} style={{ border: '1px solid var(--border)', borderRadius: 12, marginBottom: 10, overflow: 'hidden' }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '10px 12px', background: 'var(--bg-input)' }}>
            <button onClick={() => toggle(si)} style={iconBtn} aria-label={open.has(si) ? 'Collapse' : 'Expand'}>
              {open.has(si) ? <ChevronDown size={15} /> : <ChevronRight size={15} />}
            </button>
            <input value={section.label} disabled={!canEdit}
              onChange={e => editSection(si, { label: e.target.value })}
              placeholder="Section name" style={{ ...inp, fontWeight: 700, flex: 1 }} />
            <span style={{ fontSize: 11, color: 'var(--text-muted)', whiteSpace: 'nowrap' }}>
              {section.questions.length} question{section.questions.length === 1 ? '' : 's'}
            </span>
            {canEdit && (
              <button onClick={() => setForm(f => f.filter((_, i) => i !== si))}
                style={iconBtn} aria-label="Remove section"><Trash2 size={14} style={{ color: 'var(--color-danger-500)' }} /></button>
            )}
          </div>

          {open.has(si) && (
            <div style={{ padding: 12 }}>
              {section.questions.map((q, qi) => (
                <div key={qi} style={{ marginBottom: 12, paddingBottom: 12, borderBottom: '1px dashed var(--border)' }}>
                  <div style={{ display: 'flex', gap: 8, marginBottom: 6 }}>
                    <input value={q.label} disabled={!canEdit}
                      onChange={e => editQuestion(si, qi, { label: e.target.value })}
                      placeholder="Question" style={{ ...inp, flex: 1 }} />
                    {canEdit && (
                      <button onClick={() => editSection(si, { questions: section.questions.filter((_, j) => j !== qi) })}
                        style={iconBtn} aria-label="Remove question"><Trash2 size={13} style={{ color: 'var(--color-danger-500)' }} /></button>
                    )}
                  </div>

                  {q.options.map((o, oi) => (
                    <div key={oi} style={{ display: 'flex', gap: 8, marginBottom: 5, paddingLeft: 16 }}>
                      <input value={o.label} disabled={!canEdit}
                        onChange={e => editOption(si, qi, oi, { label: e.target.value })}
                        placeholder="Answer" style={{ ...inp, flex: 1 }} />
                      <input type="number" min="0" value={o.points} disabled={!canEdit}
                        onChange={e => editOption(si, qi, oi, { points: e.target.value })}
                        title="Score for this answer" style={{ ...inp, width: 82, textAlign: 'right' }} />
                      {canEdit && q.options.length > 2 && (
                        <button onClick={() => editQuestion(si, qi, { options: q.options.filter((_, k) => k !== oi) })}
                          style={iconBtn} aria-label="Remove answer"><Trash2 size={12} style={{ color: 'var(--text-muted)' }} /></button>
                      )}
                    </div>
                  ))}

                  {canEdit && (
                    <button onClick={() => editQuestion(si, qi, { options: [...q.options, emptyAnswer()] })}
                      style={{ ...linkBtn, marginLeft: 16 }}><Plus size={11} /> Add answer</button>
                  )}
                </div>
              ))}

              {canEdit && (
                <button onClick={() => editSection(si, { questions: [...section.questions, emptyQuestion()] })}
                  style={linkBtn}><Plus size={12} /> Add question</button>
              )}
            </div>
          )}
        </div>
      ))}

      {canEdit && (
        <button onClick={() => { setForm(f => [...f, emptySection()]); setOpen(s => new Set([...s, form.length])) }}
          style={btn()}><Plus size={13} /> Add section</button>
      )}

      {!canEdit && (
        <p style={{ fontSize: 12, color: 'var(--text-muted)' }}>
          Only an admin can change the questionnaire.
        </p>
      )}
    </div>
  )
}

/* ── chrome ──────────────────────────────────────────────────────────────── */

const inp = {
  padding: '7px 9px', borderRadius: 8, fontSize: 12.5,
  background: 'var(--bg-card)', border: '1px solid var(--border)',
  color: 'var(--text-h)', outline: 'none',
}

const iconBtn = { background: 'none', border: 'none', cursor: 'pointer', display: 'flex', padding: 2, color: 'var(--text-muted)' }

const linkBtn = {
  display: 'inline-flex', alignItems: 'center', gap: 4, background: 'none', border: 'none',
  cursor: 'pointer', fontSize: 11.5, fontWeight: 700, color: '#7C3AED', padding: 0,
}

const btn = (primary = false) => ({
  display: 'inline-flex', alignItems: 'center', gap: 6,
  fontSize: 12, fontWeight: 700, padding: '7px 12px', borderRadius: 9, cursor: 'pointer',
  background: primary ? '#7C3AED' : 'var(--bg-input)',
  color: primary ? '#fff' : 'var(--text-body)',
  border: primary ? 'none' : '1px solid var(--border)',
})

const notice = (color) => ({
  fontSize: 12, color, background: `color-mix(in srgb, ${color} 10%, transparent)`,
  border: `1px solid color-mix(in srgb, ${color} 30%, transparent)`,
  borderRadius: 9, padding: '8px 10px', margin: '0 0 12px',
})
