import { useState, useEffect, useCallback } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { ArrowLeft, LogOut, Loader2, Save, Send, Star, CheckCircle } from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { HrLoading } from '@/components/ui/HrState'

// The 13 free-text questions, in the order of the reference exit-interview form.
// Everything hr_employees already knows is prefilled above and never asked here.
const QUESTIONS = [
  ['reason_for_leaving',       'What prompted you to look for a new job?'],
  ['return_circumstances',     'In which circumstances could you return to the company and work again?'],
  ['recognition_feedback',     'Do you think management adequately recognised your contributions?'],
  ['policies_feedback',        'Were company policies difficult to understand or follow?'],
  ['jd_changed_feedback',      'Do you feel your job description changed since you were hired?'],
  ['tools_resources_feedback', 'Did you have the tools, resources and working conditions to do your job well?'],
  ['training_feedback',        'Did you have the training you needed to be successful?'],
  ['best_part',                'What was the best part of your job here?'],
  ['improvements',             'What can the organization improve on?'],
  ['morale_suggestions',       'Any suggestions for improving employee morale?'],
  ['looking_forward_to',       'What are you most looking forward to in your new job?'],
  ['ideal_replacement',        'How would you describe the perfect candidate to replace you?'],
  ['would_recommend',          'Would you recommend working at our company to a friend?'],
]

const EMPTY = Object.fromEntries([...QUESTIONS.map(([k]) => [k, '']),
  ['organization_or_project', ''], ['personal_mobile', ''], ['personal_email', ''], ['exit_date', ''], ['rating', 0]])

export default function ExitInterview() {
  const { id } = useParams()
  const navigate = useNavigate()
  const [prefill, setPrefill] = useState(null)
  const [form, setForm]       = useState(EMPTY)
  const [record, setRecord]   = useState(null)
  const [loading, setLoading] = useState(true)
  const [busy, setBusy]       = useState(false)
  const [toast, setToast]     = useState(null)
  // #44 — the template for this exit, and the answers given against it.
  const [questionnaire, setQuestionnaire] = useState(null)
  const [answers, setAnswers] = useState({})

  const showToast = (msg, type = 'success') => { setToast({ msg, type }); setTimeout(() => setToast(null), 3000) }

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const r = await hrApi.employees.exitInterview(id)
      setPrefill(r.prefill)
      setRecord(r.record)
      // Reopen a saved draft rather than starting blank.
      setForm(f => ({
        ...f,
        ...Object.fromEntries(Object.entries(r.record || {}).filter(([k]) => k in EMPTY)),
        organization_or_project: r.record?.organization_or_project || r.prefill?.organization_or_project || '',
        exit_date: r.record?.exit_date?.slice(0, 10) || '',
        rating: r.record?.rating || 0,
      }))
      // #44 — the questionnaire that applies to this exit, if the tenant has
      // defined any. Null is a normal answer and simply leaves the fixed form
      // below as the whole interview.
      const template = await hrApi.exit.questionnaires.resolve(r.record?.exit_type_id).catch(() => null)
      setQuestionnaire(template)
      if (template) {
        setAnswers(Object.fromEntries((r.record?.answers || []).map(a => [a.question_id, a])))
      }
    } catch (e) { showToast(e.response?.data?.message || 'Failed to load exit interview', 'error') }
    finally { setLoading(false) }
  }, [id])
  useEffect(() => { load() }, [load])

  const save = async (submit) => {
    if (submit && !form.rating) return showToast('Please rate your overall experience before submitting', 'error')
    setBusy(true)
    try {
      const saved = await hrApi.employees.saveExitInterview(id, {
        ...form, rating: form.rating || null, submit,
        // #44 — sent only when a template applies, so an interview on the
        // original fixed form posts exactly the payload it always did.
        ...(questionnaire ? {
          questionnaire_id: questionnaire.id,
          answers: questionnaire.questions.map(q => ({ question_id: q.id, ...(answers[q.id] || {}) })),
        } : {}),
      })
      setRecord(saved)
      showToast(submit ? 'Exit interview submitted' : 'Draft saved')
    } catch (e) { showToast(e.response?.data?.message || 'Failed to save', 'error') }
    finally { setBusy(false) }
  }

  if (loading) return <HrLoading label="Loading exit interview…" />
  if (!prefill) return <p style={{ padding: 24, color: 'var(--text-muted)' }}>Employee not found.</p>

  const submitted = record?.status === 'Submitted'
  const set = (k) => (e) => setForm(f => ({ ...f, [k]: e.target.value }))

  return (
    <div className="space-y-5 animate-[tiltIn_0.35s_ease_forwards]">
      {toast && (
        <div className="fixed top-5 right-5 z-[9999] px-5 py-3 rounded-2xl text-sm font-semibold text-white shadow-2xl"
          style={{ background: toast.type === 'success' ? 'linear-gradient(135deg,#10b981,#059669)' : 'linear-gradient(135deg,#f87171,#ef4444)' }}>{toast.msg}</div>
      )}

      <button onClick={() => navigate(`/app/hr/employees/${id}`)} className="flex items-center gap-1.5 text-xs font-semibold" style={{ color: 'var(--text-muted)' }}>
        <ArrowLeft size={12} /> Back to Employee
      </button>

      {/* Header */}
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div>
          <p className="label-caps mb-1">HR Module</p>
          <h1 className="font-black" style={{ fontSize: 'clamp(1.3rem,2vw,1.7rem)', color: 'var(--text-h)', letterSpacing: '-0.02em' }}>
            Exit <span className="text-gradient">Interview</span>
          </h1>
        </div>
        {submitted && (
          <span className="flex items-center gap-1.5 text-xs font-bold px-3 py-1.5 rounded-xl" style={{ background: 'rgba(16,185,129,0.12)', color: '#10b981', border: '1px solid rgba(16,185,129,0.3)' }}>
            <CheckCircle size={13} /> Submitted
          </span>
        )}
      </div>

      {/* Known details — read from hr_employees, never retyped (SPK-1 auto-prefill) */}
      <div className="card-3d" style={{ padding: '18px' }}>
        <h3 className="font-bold text-sm mb-3 flex items-center gap-2" style={{ color: 'var(--text-h)' }}>
          <LogOut size={14} style={{ color: '#a78bfa' }} /> Employee Details
          <span className="text-[10px] font-medium" style={{ color: 'var(--text-muted)' }}>· from the employee record</span>
        </h3>
        <div className="grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-3">
          {[
            ['Full Name', prefill.full_name], ['Employee ID', prefill.employee_code],
            ['Department', prefill.department], ['Designation', prefill.designation],
            ['Reporting Manager', prefill.reporting_manager], ['Joining Date', prefill.joining_date],
            ['Work Email', prefill.work_email], ['Employment Status', prefill.employment_status],
          ].map(([k, v]) => (
            <div key={k}>
              <p className="text-[9px] font-bold uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>{k}</p>
              <p className="text-xs font-semibold mt-0.5" style={{ color: 'var(--text-h)' }}>{v || '—'}</p>
            </div>
          ))}
        </div>
      </div>

      {/* Only what the employee record does not already hold */}
      <div className="card-3d" style={{ padding: '18px' }}>
        <h3 className="font-bold text-sm mb-3" style={{ color: 'var(--text-h)' }}>Your Details</h3>
        <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
          <div><label className="label">Organization / Project</label>
            <input className="input-3d text-sm" disabled={submitted} value={form.organization_or_project} onChange={set('organization_or_project')} /></div>
          <div><label className="label">Exit Date</label>
            <input type="date" className="input-3d text-sm" disabled={submitted} value={form.exit_date} onChange={set('exit_date')} /></div>
          <div><label className="label">Personal Mobile</label>
            <input className="input-3d text-sm" disabled={submitted} placeholder="Personal contact number" value={form.personal_mobile} onChange={set('personal_mobile')} /></div>
          <div><label className="label">Personal Email</label>
            <input type="email" className="input-3d text-sm" disabled={submitted} placeholder="you@personal.com" value={form.personal_email} onChange={set('personal_email')} /></div>
        </div>
      </div>

      {/* #44 — the templated questionnaire for this exit, when one is defined.
          It appears ABOVE the standard feedback block because it is the form HR
          chose for this kind of exit; the block below stays as the common core. */}
      {questionnaire && (
        <div className="card-3d" style={{ padding: '18px' }}>
          <div className="mb-4">
            <h3 className="font-bold text-sm" style={{ color: 'var(--text-h)' }}>{questionnaire.name}</h3>
            {questionnaire.description && (
              <p className="text-[11px] mt-0.5" style={{ color: 'var(--text-muted)' }}>{questionnaire.description}</p>
            )}
          </div>
          <div className="space-y-4">
            {questionnaire.questions.map(q => (
              <TemplatedQuestion key={q.id} q={q} disabled={submitted}
                value={answers[q.id] || {}}
                onChange={patch => setAnswers(a => ({ ...a, [q.id]: { ...(a[q.id] || {}), ...patch } }))} />
            ))}
          </div>
        </div>
      )}

      {/* Questions */}
      <div className="card-3d" style={{ padding: '18px' }}>
        <h3 className="font-bold text-sm mb-4" style={{ color: 'var(--text-h)' }}>Your Feedback</h3>
        <div className="space-y-3">
          {QUESTIONS.map(([k, q]) => (
            <div key={k}>
              <label className="label">{q}</label>
              <textarea className="input-3d text-sm" rows={2} disabled={submitted} value={form[k] || ''} onChange={set(k)} />
            </div>
          ))}

          <div>
            <label className="label">Rate your overall experience with us</label>
            <div className="flex gap-1.5">
              {[1, 2, 3, 4, 5].map(n => (
                <button key={n} type="button" disabled={submitted}
                  onClick={() => setForm(f => ({ ...f, rating: n }))}
                  style={{ cursor: submitted ? 'default' : 'pointer', background: 'none', border: 'none', padding: 0 }}>
                  <Star size={24} style={{ color: n <= form.rating ? '#fbbf24' : 'var(--border)', fill: n <= form.rating ? '#fbbf24' : 'none' }} />
                </button>
              ))}
            </div>
          </div>
        </div>
      </div>

      {!submitted && (
        <div className="flex gap-3">
          <button onClick={() => save(false)} disabled={busy} className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold"
            style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>
            {busy ? <Loader2 size={14} className="animate-spin" /> : <Save size={14} />} Save Draft
          </button>
          <button onClick={() => save(true)} disabled={busy} className="flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-bold text-white"
            style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', boxShadow: '0 4px 14px rgba(124,58,237,0.4)' }}>
            {busy ? <Loader2 size={14} className="animate-spin" /> : <Send size={14} />} Submit Exit Interview
          </button>
        </div>
      )}
      {submitted && (
        <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
          Submitted on {record?.submitted_at ? new Date(record.submitted_at).toLocaleString('en-IN', { dateStyle: 'medium', timeStyle: 'short' }) : '—'}. This form is now read-only.
        </p>
      )}
    </div>
  )
}

/**
 * #44 — one templated question, rendered by its type.
 *
 * Each type writes to its own key on the answer, matching the typed columns the
 * API stores them in: a rating that arrives as text cannot be averaged, and
 * averaging exit ratings is the point of exit reporting.
 */
function TemplatedQuestion({ q, value, onChange, disabled }) {
  const label = (
    <label className="label">
      {q.question_text}{q.is_required && <span style={{ color: '#f87171' }}> *</span>}
    </label>
  )

  if (q.question_type === 'rating') {
    const max = q.rating_max || 5
    return (
      <div>
        {label}
        <div className="flex items-center gap-1.5 mt-1">
          {Array.from({ length: max }, (_, i) => i + 1).map(n => (
            <button key={n} type="button" disabled={disabled}
              onClick={() => onChange({ answer_rating: n })}
              className="rounded-lg text-xs font-bold"
              style={{ width: 34, height: 34,
                       background: value.answer_rating >= n ? 'linear-gradient(135deg,#7C3AED,#5b21b6)' : 'var(--bg-input)',
                       color: value.answer_rating >= n ? '#fff' : 'var(--text-muted)',
                       border: '1px solid var(--border)', cursor: disabled ? 'default' : 'pointer' }}>
              {n}
            </button>
          ))}
        </div>
      </div>
    )
  }

  if (q.question_type === 'boolean') {
    return (
      <div>
        {label}
        <div className="flex items-center gap-2 mt-1">
          {[['Yes', true], ['No', false]].map(([text, val]) => (
            <button key={text} type="button" disabled={disabled}
              onClick={() => onChange({ answer_boolean: val })}
              className="px-4 py-1.5 rounded-xl text-xs font-bold"
              style={{ background: value.answer_boolean === val ? 'linear-gradient(135deg,#7C3AED,#5b21b6)' : 'var(--bg-input)',
                       color: value.answer_boolean === val ? '#fff' : 'var(--text-muted)',
                       border: '1px solid var(--border)', cursor: disabled ? 'default' : 'pointer' }}>
              {text}
            </button>
          ))}
        </div>
      </div>
    )
  }

  if (q.question_type === 'single_choice' || q.question_type === 'multiple_choice') {
    const multiple = q.question_type === 'multiple_choice'
    const selected = value.answer_options || []
    const toggle = (opt) => {
      if (disabled) return
      onChange({ answer_options: multiple
        ? (selected.includes(opt) ? selected.filter(o => o !== opt) : [...selected, opt])
        : [opt] })
    }
    return (
      <div>
        {label}
        <div className="flex flex-wrap gap-2 mt-1">
          {(q.options || []).map(opt => (
            <button key={opt} type="button" disabled={disabled} onClick={() => toggle(opt)}
              className="px-3 py-1.5 rounded-xl text-xs font-semibold"
              style={{ background: selected.includes(opt) ? 'linear-gradient(135deg,#7C3AED,#5b21b6)' : 'var(--bg-input)',
                       color: selected.includes(opt) ? '#fff' : 'var(--text-muted)',
                       border: '1px solid var(--border)', cursor: disabled ? 'default' : 'pointer' }}>
              {opt}
            </button>
          ))}
        </div>
        {multiple && <p className="text-[10px] mt-1" style={{ color: 'var(--text-muted)' }}>Choose as many as apply.</p>}
      </div>
    )
  }

  return (
    <div>
      {label}
      <textarea className="input-3d text-sm" rows={2} disabled={disabled}
        value={value.answer_text || ''} onChange={e => onChange({ answer_text: e.target.value })} />
    </div>
  )
}
