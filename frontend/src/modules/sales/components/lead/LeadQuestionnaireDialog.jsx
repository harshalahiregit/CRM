import { useState, useEffect, useMemo } from 'react'
import { createPortal } from 'react-dom'
import { X, Loader2, HelpCircle } from 'lucide-react'
import { leadApi } from '@/services/leadApi'
import { leadSettingsApi } from '@/services/leadSettingsApi'
import { useToast } from '@/hooks/useToast'
import { GRAD } from '@/components/ui/brand'

/**
 * Record a questionnaire response against a lead — SIR-000035.
 *
 * The feature was built and had no door. Five routes exist and are wired all the
 * way through the API client — list/create/update/delete a questionnaire, and
 * submit a response for a lead — and not one React component had ever called
 * any of them. The Questionnaires tab rendered responses that nothing in the
 * product could produce.
 *
 * ── ANSWERS ARE KEYED BY LABEL ───────────────────────────────────────────────
 * `answers` is a free-form JSON column, so the key is a decision, not a schema.
 * It is the field's LABEL because that is what the existing display reads: the
 * tab does Object.entries(answers) and prints the key as the question. Keying by
 * field id would store correctly and render a column of meaningless numbers.
 *
 * The cost is that renaming a field orphans the answers already recorded under
 * the old label. That is the right trade here — a response is a record of what
 * was asked at the time, and the question as worded is part of the answer.
 *
 * ── RE-OPENING AN EXISTING RESPONSE ──────────────────────────────────────────
 * The endpoint is updateOrCreate on (tenant, questionnaire, lead), so there is
 * exactly one response per questionnaire per lead and submitting again amends
 * it. The form is seeded from the existing answers to match, otherwise saving a
 * one-field correction would silently blank every other answer.
 */

/** `file` is absent on purpose — see the note where it is filtered out. */
const TEXTUAL = ['text', 'email', 'phone', 'number', 'date']

export default function LeadQuestionnaireDialog({ lead, onClose, onSaved }) {
  const toast = useToast()
  const [list, setList] = useState(null)      // null = still loading
  const [pickedId, setPickedId] = useState('')
  const [answers, setAnswers] = useState({})
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    leadSettingsApi.questionnaires.list()
      .then(r => {
        const active = (r || []).filter(q => q.is_active !== false)
        setList(active)
        if (active.length === 1) setPickedId(String(active[0].id))
      })
      .catch(() => setList([]))
  }, [])

  const picked = useMemo(
    () => (list || []).find(q => String(q.id) === String(pickedId)) || null,
    [list, pickedId],
  )

  // Seed from any response already recorded for this questionnaire, because the
  // endpoint amends rather than appends.
  useEffect(() => {
    if (!picked) { setAnswers({}); return }
    const existing = (lead.questionnaire_responses || [])
      .find(r => String(r.questionnaire_id) === String(picked.id))
    setAnswers(existing?.answers ? { ...existing.answers } : {})
  }, [picked, lead.questionnaire_responses])

  const setAnswer = (label, value) => setAnswers(p => ({ ...p, [label]: value }))

  /* A file input would need an upload pipeline this endpoint does not have —
     `answers` is a JSON column, not an attachment store. Dropping the field is
     honest; rendering a picker that quietly discarded the file would not be. */
  const fields = (picked?.fields || [])
    .filter(f => f.field_type !== 'file')
    .slice()
    .sort((a, b) => (a.sort_order ?? 0) - (b.sort_order ?? 0))

  const hiddenFileFields = (picked?.fields || []).filter(f => f.field_type === 'file').length

  const submit = async () => {
    if (!picked) return toast.error('Pick a questionnaire first')

    const missing = fields.filter(f => f.is_required && !String(answers[f.label] ?? '').trim())
    if (missing.length) return toast.error(`${missing[0].label} is required`)

    setBusy(true)
    try {
      await leadApi.submitQuestionnaire(lead.id, { questionnaire_id: picked.id, answers })
      toast.success('Response recorded')
      onSaved?.()
      onClose()
    } catch (e) {
      toast.error(e.message || 'Could not record the response')
    } finally {
      setBusy(false)
    }
  }

  const renderField = (f) => {
    const value = answers[f.label] ?? ''
    const opts = Array.isArray(f.options) ? f.options : []

    if (f.field_type === 'textarea') {
      return <textarea className="input-3d text-sm" rows={3} placeholder={f.placeholder || ''}
        value={value} onChange={e => setAnswer(f.label, e.target.value)} />
    }

    if (f.field_type === 'select' || f.field_type === 'radio') {
      return (
        <select className="input-3d text-sm" value={value} onChange={e => setAnswer(f.label, e.target.value)}>
          <option value="">Select…</option>
          {opts.map(o => <option key={o} value={o}>{o}</option>)}
        </select>
      )
    }

    if (f.field_type === 'checkbox') {
      return (
        <label className="flex items-center gap-2 text-xs" style={{ color: 'var(--text-muted)' }}>
          <input type="checkbox" checked={value === true || value === 'Yes'}
            onChange={e => setAnswer(f.label, e.target.checked ? 'Yes' : 'No')} />
          {f.placeholder || 'Yes'}
        </label>
      )
    }

    if (f.field_type === 'multi_select') {
      // Stored as a comma-joined string so the read-only tab, which prints
      // String(value), shows "A, B" rather than the word "Array".
      const chosen = String(value || '').split(',').map(s => s.trim()).filter(Boolean)
      const toggle = (o) => setAnswer(
        f.label,
        (chosen.includes(o) ? chosen.filter(c => c !== o) : [...chosen, o]).join(', '),
      )
      return (
        <div className="flex flex-wrap gap-1.5">
          {opts.map(o => (
            <button key={o} type="button" onClick={() => toggle(o)}
              className="px-2.5 py-1 rounded-lg text-[11px] font-semibold transition-colors"
              style={chosen.includes(o)
                ? { background: 'rgba(124,58,237,0.14)', color: '#a78bfa', border: '1px solid rgba(124,58,237,0.3)' }
                : { background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>
              {o}
            </button>
          ))}
        </div>
      )
    }

    const type = TEXTUAL.includes(f.field_type)
      ? ({ phone: 'tel' }[f.field_type] || f.field_type)
      : 'text'

    return <input type={type} className="input-3d text-sm" placeholder={f.placeholder || ''}
      value={value} onChange={e => setAnswer(f.label, e.target.value)} />
  }

  return createPortal(
    <div className="fixed inset-0 z-[130] flex items-center justify-center p-4" style={{ background: 'rgba(0,0,0,0.55)' }}>
      <div className="w-full max-w-lg rounded-2xl flex flex-col" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', maxHeight: '86vh' }}>

        <div className="flex items-start justify-between gap-3 p-5 pb-3">
          <div>
            <p className="font-bold text-sm" style={{ color: 'var(--text-h)' }}>Record a questionnaire response</p>
            <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{lead.name}</p>
          </div>
          <button onClick={onClose} className="w-7 h-7 rounded-lg flex items-center justify-center flex-shrink-0"
            style={{ border: '1px solid var(--border)' }}>
            <X size={13} style={{ color: 'var(--text-muted)' }} />
          </button>
        </div>

        <div className="px-5 pb-4 overflow-y-auto">

          {list === null && (
            <p className="text-xs flex items-center gap-2" style={{ color: 'var(--text-muted)' }}>
              <Loader2 size={13} className="animate-spin" /> Loading questionnaires…
            </p>
          )}

          {list !== null && list.length === 0 && (
            <div className="text-center py-6">
              <HelpCircle size={22} style={{ color: 'var(--text-muted)', margin: '0 auto 8px' }} />
              <p className="text-xs font-semibold" style={{ color: 'var(--text-h)' }}>No questionnaires yet</p>
              <p className="text-[11px] mt-1" style={{ color: 'var(--text-muted)' }}>
                A questionnaire has to be created before a response can be recorded against a lead.
              </p>
            </div>
          )}

          {list !== null && list.length > 0 && (
            <>
              <label className="label">Questionnaire</label>
              <select className="input-3d text-sm" value={pickedId} onChange={e => setPickedId(e.target.value)}>
                <option value="">Select…</option>
                {list.map(q => <option key={q.id} value={q.id}>{q.title}</option>)}
              </select>

              {picked?.description && (
                <p className="text-[11px] mt-2" style={{ color: 'var(--text-muted)' }}>{picked.description}</p>
              )}

              {picked && fields.length === 0 && (
                <p className="text-xs mt-4" style={{ color: 'var(--text-muted)' }}>
                  This questionnaire has no questions that can be answered here.
                </p>
              )}

              <div className="space-y-3 mt-4">
                {fields.map(f => (
                  <div key={f.id ?? f.label}>
                    <label className="label">
                      {f.label}{f.is_required ? ' *' : ''}
                    </label>
                    {renderField(f)}
                  </div>
                ))}
              </div>

              {hiddenFileFields > 0 && (
                <p className="text-[11px] mt-3" style={{ color: 'var(--text-muted)' }}>
                  {hiddenFileFields} file question{hiddenFileFields > 1 ? 's are' : ' is'} not shown —
                  responses are stored as text, so attachments belong on the Attachments tab.
                </p>
              )}
            </>
          )}
        </div>

        <div className="flex justify-end gap-2 p-5 pt-3" style={{ borderTop: '1px solid var(--border)' }}>
          <button onClick={onClose} className="px-4 py-2 rounded-xl text-xs font-bold"
            style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>
            Cancel
          </button>
          <button onClick={submit} disabled={busy || !picked || fields.length === 0}
            className="px-4 py-2 rounded-xl text-xs font-bold text-white inline-flex items-center gap-1.5 disabled:opacity-50"
            style={{ background: GRAD }}>
            {busy && <Loader2 size={11} className="animate-spin" />} Save Response
          </button>
        </div>

      </div>
    </div>,
    document.body,
  )
}
