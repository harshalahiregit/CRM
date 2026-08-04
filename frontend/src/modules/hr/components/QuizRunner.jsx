import { useState, useEffect, useCallback } from 'react'
import {
  X, PlayCircle, Loader2, CheckCircle2, XCircle, Circle, CheckSquare, Square,
  Clock, Award, AlertTriangle, RotateCcw,
} from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { HrLoading } from '@/components/ui/HrState'

/**
 * Review comment #25 — the sitting half of "Quiz: how to set multiple questions
 * and their answer?"
 *
 * The Quiz Builder answers how a quiz is AUTHORED. This is the other end: an
 * assigned quiz being started, answered, submitted and reviewed. Before this the
 * engine could produce a quiz nobody could take — quizEngine.start / .submit /
 * .result had no caller, and hr_quiz_attempts was write-only from the product's
 * point of view.
 *
 * Every rule stays where it already lives. This component decides nothing:
 *   - which questions appear, and in what order → QuizService (shuffle_questions)
 *   - whether an attempt is allowed          → startAttempt (max_attempts)
 *   - marks, pass/fail                        → submitAttempt
 *   - whether the answer key may be shown     → show_correct_answers
 * The paper the server returns for an attempt deliberately carries NO is_correct,
 * so the answer key cannot leak into the browser while someone is sitting it.
 *
 * Resuming is the server's behaviour too: starting again while an attempt is open
 * returns that same attempt rather than creating a second one.
 */

const GRAD = 'linear-gradient(135deg,#7C3AED,#5b21b6)'

export default function QuizRunner({ quizId, employeeId, employeeTrainingId = null, employeeName, onClose, onFinished, showToast }) {
  const [paper, setPaper]     = useState(null)   // the attempt being sat
  const [result, setResult]   = useState(null)   // the evaluated attempt
  const [answers, setAnswers] = useState({})     // { [question_id]: number[] }
  const [starting, setStarting] = useState(true)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError]     = useState(null)

  const start = useCallback(async () => {
    setStarting(true); setError(null)
    try {
      const p = await hrApi.quizEngine.start(quizId, {
        employee_id: employeeId,
        ...(employeeTrainingId ? { employee_training_id: employeeTrainingId } : {}),
      })
      setPaper(p)
      // A resumed attempt comes back with its questions but no saved answers —
      // the engine evaluates on submit only, so nothing is part-stored.
      setAnswers({})
    } catch (e) {
      // "no questions yet", "not active", "at most N attempt(s)" are all real
      // answers from the engine and are shown as-is rather than as a failure.
      setError(e?.response?.data?.message || 'Could not start this quiz.')
    }
    finally { setStarting(false) }
  }, [quizId, employeeId, employeeTrainingId])

  useEffect(() => { start() }, [start])

  const toggle = (q, optionId) => {
    const current = answers[q.id] || []
    if (q.question_type === 'multiple_choice') {
      setAnswers({ ...answers, [q.id]: current.includes(optionId) ? current.filter(x => x !== optionId) : [...current, optionId] })
    } else {
      // Single choice and true/false take exactly one.
      setAnswers({ ...answers, [q.id]: [optionId] })
    }
  }

  const answeredCount = (paper?.questions || []).filter(q => (answers[q.id] || []).length > 0).length
  const total = paper?.questions?.length || 0

  const submit = async () => {
    if (answeredCount < total && !window.confirm(
      `${total - answeredCount} question(s) are unanswered and will score zero. Submit anyway?`
    )) return

    setSubmitting(true)
    try {
      // Every question is sent, including the untouched ones with an empty
      // selection — the engine marks on what it receives, and omitting a question
      // would leave its row missing from the result rather than scored zero.
      const r = await hrApi.quizEngine.submit(paper.attempt_id, (paper.questions || []).map(q => ({
        question_id: q.id,
        selected_option_ids: answers[q.id] || [],
      })))
      setResult(r)
      onFinished?.()
    } catch (e) { showToast?.(e?.response?.data?.message || 'Could not submit the quiz', 'error') }
    finally { setSubmitting(false) }
  }

  return (
    <div className="fixed inset-0 z-[9999] flex justify-center items-start overflow-y-auto" style={{ padding:'24px 12px' }}>
      <div className="absolute inset-0" style={{ background:'rgba(0,0,0,0.55)' }} onClick={onClose}/>
      <div className="relative w-full" onClick={e=>e.stopPropagation()}
        style={{ maxWidth:720, background:'var(--bg-card,var(--bg-input))', border:'1px solid var(--border)', borderRadius:18, boxShadow:'0 20px 60px rgba(0,0,0,0.4)' }}>

        <div className="sticky top-0 z-10 flex items-center justify-between px-5 py-4"
          style={{ background:'var(--bg-card,var(--bg-input))', borderBottom:'1px solid var(--border)', borderTopLeftRadius:18, borderTopRightRadius:18 }}>
          <div className="min-w-0">
            <h2 className="font-black text-base truncate" style={{ color:'var(--text-h)' }}>
              {result?.quiz_name || paper?.quiz_name || 'Quiz'}
            </h2>
            <p className="text-[11px]" style={{ color:'var(--text-muted)' }}>
              {employeeName}
              {(result || paper) && ` · attempt ${result?.attempt_number ?? paper?.attempt_number}`}
            </p>
          </div>
          <button onClick={onClose} style={{ color:'var(--text-muted)' }}><X size={18}/></button>
        </div>

        <div className="p-5">
          {starting ? <HrLoading label="Preparing the quiz…" />
            : error ? (
              <div className="rounded-xl p-4 flex items-start gap-2.5" style={{ background:'rgba(251,191,36,0.08)', border:'1px solid rgba(251,191,36,0.3)' }}>
                <AlertTriangle size={15} style={{ color:'#fbbf24', flexShrink:0, marginTop:1 }}/>
                <div>
                  <p className="text-xs font-bold" style={{ color:'#fbbf24' }}>This quiz cannot be started</p>
                  <p className="text-[11px] mt-1" style={{ color:'var(--text-muted)' }}>{error}</p>
                </div>
              </div>
            )
            : result ? <Result result={result} onClose={onClose} />
            : paper ? (
              <>
                <div className="flex items-center gap-3 flex-wrap mb-4 text-[11px]" style={{ color:'var(--text-muted)' }}>
                  <span className="inline-flex items-center gap-1"><Award size={12}/> {paper.total_marks} marks</span>
                  <span className="inline-flex items-center gap-1"><CheckCircle2 size={12}/> pass at {paper.pass_percentage}%</span>
                  {paper.duration_minutes && <span className="inline-flex items-center gap-1"><Clock size={12}/> {paper.duration_minutes} min</span>}
                  <span className="ml-auto font-bold" style={{ color: answeredCount === total ? '#10b981' : '#a78bfa' }}>
                    {answeredCount} / {total} answered
                  </span>
                </div>

                <div className="space-y-3">
                  {paper.questions.map((q, i) => {
                    const picked = answers[q.id] || []
                    const multi = q.question_type === 'multiple_choice'
                    return (
                      <div key={q.id} className="rounded-xl p-4" style={{ background:'var(--bg-input)', border:'1px solid var(--border)' }}>
                        <div className="flex items-start gap-2 mb-2.5">
                          <span className="text-[11px] font-black flex-shrink-0" style={{ color:'#a78bfa' }}>{i+1}.</span>
                          <div className="min-w-0">
                            <p className="text-sm font-semibold" style={{ color:'var(--text-h)' }}>{q.question_text}</p>
                            <p className="text-[10px] mt-0.5" style={{ color:'var(--text-muted)' }}>
                              {q.effective_marks} mark{q.effective_marks === 1 ? '' : 's'}
                              {multi && ' · select all that apply'}
                            </p>
                          </div>
                        </div>
                        <div className="space-y-1.5 pl-5">
                          {q.options.map(o => {
                            const on = picked.includes(o.id)
                            const Icon = multi ? (on ? CheckSquare : Square) : (on ? CheckCircle2 : Circle)
                            return (
                              <button key={o.id} onClick={()=>toggle(q, o.id)}
                                className="w-full flex items-center gap-2 text-left px-2.5 py-2 rounded-lg transition-all"
                                style={{ background: on ? 'rgba(124,58,237,0.12)' : 'transparent', border:`1px solid ${on ? 'rgba(124,58,237,0.35)' : 'var(--border)'}` }}>
                                <Icon size={15} style={{ color: on ? '#a78bfa' : 'var(--text-muted)', flexShrink:0 }}/>
                                <span className="text-xs" style={{ color: on ? 'var(--text-h)' : 'var(--text-muted)' }}>{o.option_text}</span>
                              </button>
                            )
                          })}
                        </div>
                      </div>
                    )
                  })}
                </div>

                <div className="flex gap-3 pt-4">
                  <button onClick={onClose} className="flex-1 py-2.5 rounded-xl text-sm font-semibold"
                    style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>
                    Close
                  </button>
                  <button onClick={submit} disabled={submitting}
                    className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white inline-flex items-center justify-center gap-2"
                    style={{ background:GRAD, opacity:submitting?0.7:1 }}>
                    {submitting ? <Loader2 size={14} className="animate-spin"/> : <PlayCircle size={14}/>} Submit
                  </button>
                </div>
                <p className="text-[10px] text-center mt-2" style={{ color:'var(--text-muted)' }}>
                  Answers are marked when you submit; closing without submitting leaves this attempt open to resume.
                </p>
              </>
            ) : null}
        </div>
      </div>
    </div>
  )
}

/** The evaluated attempt. Shows the key only when the quiz allows it. */
function Result({ result, onClose }) {
  const tone = result.passed ? '#10b981' : '#f87171'

  return (
    <div className="space-y-4">
      <div className="rounded-xl p-4 text-center" style={{ background:`${tone}14`, border:`1px solid ${tone}44` }}>
        <p className="font-black" style={{ fontSize:42, color:tone, lineHeight:1 }}>
          {result.percentage}<span style={{ fontSize:18 }}>%</span>
        </p>
        <p className="text-sm font-bold mt-1" style={{ color:tone }}>{result.passed ? 'Passed' : 'Not passed'}</p>
        <p className="text-[11px] mt-1" style={{ color:'var(--text-muted)' }}>
          {result.obtained_marks} of {result.total_marks} marks · pass mark {result.pass_percentage}%
        </p>
      </div>

      <div className="space-y-2">
        {result.questions.map((q, i) => {
          // is_correct is authoritative from the server; a question with no
          // answer key revealed still reports whether it was right.
          const c = q.is_correct ? '#10b981' : '#f87171'
          return (
            <div key={q.id} className="rounded-xl p-3.5" style={{ background:'var(--bg-input)', border:`1px solid ${c}33` }}>
              <div className="flex items-start gap-2">
                {q.is_correct
                  ? <CheckCircle2 size={14} style={{ color:'#10b981', flexShrink:0, marginTop:2 }}/>
                  : <XCircle size={14} style={{ color:'#f87171', flexShrink:0, marginTop:2 }}/>}
                <div className="min-w-0 flex-1">
                  <p className="text-xs font-semibold" style={{ color:'var(--text-h)' }}>{i+1}. {q.question_text}</p>
                  <p className="text-[10px] mt-0.5" style={{ color:'var(--text-muted)' }}>
                    {q.marks_awarded} / {q.effective_marks} marks
                  </p>

                  <div className="mt-2 space-y-1">
                    {q.options.map(o => {
                      const picked = (q.selected_option_ids || []).includes(o.id)
                      // is_correct is present on options ONLY when the quiz is set
                      // to reveal answers; undefined means "not disclosed", which is
                      // different from "wrong".
                      const revealed = o.is_correct !== undefined && o.is_correct !== null
                      const good = revealed && o.is_correct
                      return (
                        <div key={o.id} className="flex items-center gap-2">
                          <span style={{ width:6, height:6, borderRadius:99, flexShrink:0,
                            background: good ? '#10b981' : picked ? '#f87171' : 'var(--text-muted)' }}/>
                          <span className="text-[11px]" style={{ color: good ? '#10b981' : picked ? 'var(--text-h)' : 'var(--text-muted)' }}>
                            {o.option_text}
                            {picked && <span className="ml-1.5 text-[9px] font-bold" style={{ color:'#a78bfa' }}>YOUR ANSWER</span>}
                            {good && !picked && <span className="ml-1.5 text-[9px] font-bold" style={{ color:'#10b981' }}>CORRECT</span>}
                          </span>
                        </div>
                      )
                    })}
                  </div>

                  {q.explanation && (
                    <p className="text-[10px] mt-2 pt-2" style={{ color:'var(--text-muted)', borderTop:'1px dashed var(--border)' }}>
                      {q.explanation}
                    </p>
                  )}
                </div>
              </div>
            </div>
          )
        })}
      </div>

      <button onClick={onClose} className="w-full py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:GRAD }}>
        Done
      </button>
    </div>
  )
}

/**
 * The quizzes attached to a training programme, with this employee's history and
 * the entry point to sit one. Rendered inside the assignment drawer, which is
 * where an assigned quiz belongs — the assignment already knows the employee and
 * the programme, so nothing has to be picked twice.
 */
export function AssignmentQuizzes({ assignment, showToast }) {
  const [quizzes, setQuizzes] = useState([])
  const [history, setHistory] = useState(null)
  const [loading, setLoading] = useState(true)
  const [running, setRunning] = useState(null)   // quiz being sat
  const [viewing, setViewing] = useState(null)   // attempt being reviewed

  const programId = assignment?.training_program_id
  const employeeId = assignment?.employee_id

  const load = useCallback(async () => {
    if (!programId || !employeeId) { setLoading(false); return }
    setLoading(true)
    try {
      const [qs, h] = await Promise.all([
        hrApi.quizEngine.list({ training_program_id: programId }),
        hrApi.quizEngine.history(employeeId),
      ])
      setQuizzes(qs); setHistory(h)
    } catch { /* a missing quiz list must not break the drawer */ }
    finally { setLoading(false) }
  }, [programId, employeeId])

  useEffect(() => { load() }, [load])

  if (loading) return <p className="text-xs" style={{ color:'var(--text-muted)' }}>Loading quizzes…</p>
  if (!programId) return null

  const attemptsFor = (quizId) => (history?.attempts || []).filter(a => a.quiz_id === quizId)

  return (
    <div>
      <p className="label-caps mb-2 flex items-center gap-1.5"><Award size={12}/> Quizzes</p>

      {quizzes.length === 0 ? (
        <p className="text-xs px-3 py-2 rounded-xl" style={{ background:'var(--bg-input)', color:'var(--text-muted)' }}>
          No quiz is attached to this programme. Create one under <b>Learning &amp; Development → Quiz Builder</b>.
        </p>
      ) : (
        <div className="space-y-2">
          {quizzes.map(q => {
            const attempts = attemptsFor(q.id)
            const best = attempts.length ? Math.max(...attempts.map(a => a.percentage)) : null
            const passed = attempts.some(a => a.passed)
            const open = attempts.find(a => a.status === 'In Progress')
            const exhausted = q.max_attempts !== null && q.max_attempts !== undefined && attempts.length >= q.max_attempts && !open

            return (
              <div key={q.id} className="rounded-xl p-3" style={{ background:'var(--bg-input)', border:'1px solid var(--border)' }}>
                <div className="flex items-start justify-between gap-3">
                  <div className="min-w-0">
                    <p className="text-xs font-bold" style={{ color:'var(--text-h)' }}>{q.name}</p>
                    <p className="text-[10px] mt-0.5" style={{ color:'var(--text-muted)' }}>
                      {q.question_count ?? 0} question{q.question_count === 1 ? '' : 's'} · pass at {q.pass_percentage}%
                      {q.max_attempts ? ` · ${attempts.length}/${q.max_attempts} attempts` : ` · ${attempts.length} attempt(s)`}
                    </p>
                  </div>
                  <div className="flex items-center gap-1.5 flex-shrink-0">
                    {best !== null && (
                      <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg"
                        style={passed ? { background:'rgba(16,185,129,0.14)', color:'#10b981' } : { background:'rgba(239,68,68,0.12)', color:'#f87171' }}>
                        best {best}%
                      </span>
                    )}
                    {!q.is_active ? (
                      <span className="text-[10px]" style={{ color:'var(--text-muted)' }}>Inactive</span>
                    ) : exhausted ? (
                      <span className="text-[10px]" style={{ color:'var(--text-muted)' }}>No attempts left</span>
                    ) : (
                      <button onClick={()=>setRunning(q)}
                        className="px-3 py-1.5 rounded-lg text-[11px] font-bold text-white inline-flex items-center gap-1.5"
                        style={{ background:GRAD }}>
                        {open ? <><RotateCcw size={11}/> Resume</> : <><PlayCircle size={11}/> Start</>}
                      </button>
                    )}
                  </div>
                </div>

                {attempts.length > 0 && (
                  <div className="mt-2 pt-2 space-y-1" style={{ borderTop:'1px dashed var(--border)' }}>
                    {attempts.map(a => (
                      <div key={a.id} className="flex items-center gap-2 text-[10px]">
                        <span style={{ color:'var(--text-muted)', width:64 }}>Attempt {a.attempt_number}</span>
                        <span className="font-bold" style={{ color: a.passed ? '#10b981' : a.status === 'In Progress' ? '#f59e0b' : '#f87171' }}>
                          {a.status === 'In Progress' ? 'In progress' : `${a.percentage}% · ${a.passed ? 'Passed' : 'Failed'}`}
                        </span>
                        {a.status !== 'In Progress' && (
                          <button onClick={()=>setViewing(a.id)} className="ml-auto font-bold" style={{ color:'#a78bfa' }}>Review</button>
                        )}
                      </div>
                    ))}
                  </div>
                )}
              </div>
            )
          })}
        </div>
      )}

      {running && (
        <QuizRunner
          quizId={running.id} employeeId={employeeId} employeeTrainingId={assignment.id}
          employeeName={assignment.employee_name} showToast={showToast}
          onClose={()=>{ setRunning(null); load() }}
          onFinished={load}
        />
      )}

      {viewing && <AttemptReview attemptId={viewing} onClose={()=>setViewing(null)} employeeName={assignment.employee_name} />}
    </div>
  )
}

/** Read-only review of a finished attempt — the same Result view, fetched by id. */
function AttemptReview({ attemptId, employeeName, onClose }) {
  const [result, setResult] = useState(null)
  const [error, setError]   = useState(null)

  useEffect(() => {
    hrApi.quizEngine.result(attemptId)
      .then(setResult)
      .catch(e => setError(e?.response?.data?.message || 'Could not load this attempt'))
  }, [attemptId])

  return (
    <div className="fixed inset-0 z-[9999] flex justify-center items-start overflow-y-auto" style={{ padding:'24px 12px' }}>
      <div className="absolute inset-0" style={{ background:'rgba(0,0,0,0.55)' }} onClick={onClose}/>
      <div className="relative w-full" onClick={e=>e.stopPropagation()}
        style={{ maxWidth:720, background:'var(--bg-card,var(--bg-input))', border:'1px solid var(--border)', borderRadius:18 }}>
        <div className="sticky top-0 z-10 flex items-center justify-between px-5 py-4"
          style={{ background:'var(--bg-card,var(--bg-input))', borderBottom:'1px solid var(--border)', borderTopLeftRadius:18, borderTopRightRadius:18 }}>
          <div className="min-w-0">
            <h2 className="font-black text-base truncate" style={{ color:'var(--text-h)' }}>{result?.quiz_name || 'Attempt'}</h2>
            <p className="text-[11px]" style={{ color:'var(--text-muted)' }}>
              {employeeName}{result && ` · attempt ${result.attempt_number}`}
            </p>
          </div>
          <button onClick={onClose} style={{ color:'var(--text-muted)' }}><X size={18}/></button>
        </div>
        <div className="p-5">
          {error ? <p className="text-xs" style={{ color:'#f87171' }}>{error}</p>
            : !result ? <HrLoading label="Loading attempt…" />
            : <Result result={result} onClose={onClose} />}
        </div>
      </div>
    </div>
  )
}
