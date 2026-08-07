import { useState, useEffect, useCallback } from 'react'
import {
  Plus, Pencil, Trash2, X, FileQuestion, ListChecks, CheckCircle2, Circle,
  Loader2, Layers, Percent, Clock, Repeat,
} from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { HrLoading, HrEmpty } from '@/components/ui/HrState'
import ListFilter, { applyListFilter } from '@/components/ui/ListFilter'

/**
 * Review comment #25 — "Quiz: how to set multiple questions and their answer?"
 *
 * The engine behind this (QuizService, the hr_quiz_* tables, 16 routes and
 * hrApi.quizEngine) has existed since Phase B and had no screen at all, so the
 * question the comment asks had no answer in the product: the only quiz UI was a
 * marks-entry form writing a score to the legacy hr_training_quizzes table.
 *
 * This is that missing screen and nothing else — every call goes through the
 * existing hrApi.quizEngine, and the legacy marks tab is left untouched so old
 * score records keep working exactly as before.
 *
 * Two views, because the engine models them separately: a QUESTION BANK that
 * owns the questions and their answer key, and QUIZZES that assemble questions
 * from that bank (optionally re-weighting a question for one quiz). Authoring a
 * question once and reusing it is the whole point of the split.
 */

const GRAD = 'linear-gradient(135deg,#7C3AED,#5b21b6)'

const TYPE_LABEL = {
  single_choice:   'Single choice',
  multiple_choice: 'Multiple choice',
  boolean:         'True / False',
}

const EMPTY_QUESTION = {
  question_text: '', question_type: 'single_choice', marks: 1,
  explanation: '', category_id: '', is_active: true,
  options: [{ option_text: '', is_correct: true }, { option_text: '', is_correct: false }],
}

const EMPTY_QUIZ = {
  name: '', code: '', training_program_id: '', description: '',
  pass_percentage: 60, duration_minutes: 30, max_attempts: 3,
  shuffle_questions: false, show_correct_answers: true, is_active: true,
  questions: [],
}

export default function QuizBuilder({ showToast }) {
  const [view, setView] = useState('bank')   // bank | quizzes

  return (
    <div className="space-y-4">
      <div className="flex gap-1.5 flex-wrap items-center">
        {[['bank', 'Question Bank', ListChecks], ['quizzes', 'Quizzes', FileQuestion]].map(([k, label, Icon]) => (
          <button key={k} onClick={() => setView(k)}
            className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold"
            style={{ background: view === k ? 'rgba(124,58,237,0.15)' : 'var(--bg-input)', color: view === k ? '#a78bfa' : 'var(--text-muted)' }}>
            <Icon size={13}/> {label}
          </button>
        ))}
      </div>

      {view === 'bank' ? <QuestionBank showToast={showToast} /> : <Quizzes showToast={showToast} />}
    </div>
  )
}

/* ══ Question bank ═══════════════════════════════════════════════════════ */

function QuestionBank({ showToast }) {
  const [rows, setRows]       = useState([])
  const [types, setTypes]     = useState([])
  const [categories, setCategories] = useState([])
  const [loading, setLoading] = useState(true)
  const [modal, setModal]     = useState(null)
  const [saving, setSaving]   = useState(false)
  const [search, setSearch]   = useState('')
  const [typeF, setTypeF]     = useState('All')

  const load = useCallback(() => {
    setLoading(true)
    hrApi.quizEngine.questions()
      .then(setRows)
      .catch(e => showToast?.(e?.response?.data?.message || 'Could not load the question bank', 'error'))
      .finally(() => setLoading(false))
  }, [showToast])

  useEffect(() => { load() }, [load])
  useEffect(() => {
    hrApi.quizEngine.meta().then(m => setTypes(m.question_types || [])).catch(() => {})
    // Questions are categorised with the SAME training categories the rest of L&D
    // uses — no separate quiz-category master.
    hrApi.learning.categories.list({ status: 'Active' }).then(r => setCategories(r.data || [])).catch(() => {})
  }, [])

  const shown = applyListFilter(rows, {
    search, fields: ['question_text', 'category_name'],
    matchers: [[typeF, (r, v) => r.question_type === v]],
  })

  const save = async () => {
    const f = modal.form
    if (!f.question_text.trim()) return showToast?.('Question text is required', 'error')

    const options = f.options.filter(o => o.option_text.trim() !== '')
    if (options.length < 2) return showToast?.('Add at least two answer options', 'error')
    if (!options.some(o => o.is_correct)) return showToast?.('Mark at least one option as correct', 'error')
    // The server enforces this too; catching it here keeps the user out of a
    // round trip that can only fail.
    if (f.question_type !== 'multiple_choice' && options.filter(o => o.is_correct).length > 1) {
      return showToast?.('A single-choice question can only have one correct answer', 'error')
    }

    setSaving(true)
    try {
      await hrApi.quizEngine.saveQuestion(modal.editing, {
        ...f,
        marks: Number(f.marks) || 1,
        category_id: f.category_id === '' ? null : Number(f.category_id),
        options,
      })
      showToast?.(`Question ${modal.editing ? 'updated' : 'added'}`)
      setModal(null); load()
    } catch (e) { showToast?.(e?.response?.data?.message || 'Could not save the question', 'error') }
    finally { setSaving(false) }
  }

  const remove = async (r) => {
    if (!window.confirm('Delete this question?')) return
    try { await hrApi.quizEngine.removeQuestion(r.id); showToast?.('Question deleted'); load() }
    catch (e) {
      // A question already answered in an attempt cannot be deleted — the server
      // says so, and that message is more useful than a generic failure.
      showToast?.(e?.response?.data?.message || 'Could not delete the question', 'error')
    }
  }

  const openEdit = (r) => setModal({
    editing: r.id,
    form: {
      question_text: r.question_text, question_type: r.question_type,
      marks: r.marks, explanation: r.explanation || '',
      category_id: r.category_id ?? '', is_active: r.is_active,
      options: (r.options || []).map(o => ({ option_text: o.option_text, is_correct: !!o.is_correct })),
    },
  })

  return (
    <div className="space-y-4">
      <ListFilter
        search={search} setSearch={setSearch} placeholder="Question text or category…"
        selects={[{ key:'type', label:'Type', value:typeF, onChange:setTypeF,
          options:[{ value:'All', label:'All types' }, ...types.map(t => ({ value:t, label:TYPE_LABEL[t] || t }))] }]}
        onClear={()=>{ setSearch(''); setTypeF('All') }}
        right={
          <button onClick={()=>setModal({ editing:null, form:{ ...EMPTY_QUESTION, options: EMPTY_QUESTION.options.map(o=>({...o})) } })}
            className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:GRAD }}>
            <Plus size={15}/> Add Question
          </button>
        }
      />

      {loading ? <HrLoading label="Loading questions…" />
        : shown.length === 0
          ? <HrEmpty icon={ListChecks} title={rows.length ? 'No matching questions' : 'No questions yet'}
              hint={rows.length ? 'Nothing matches these filters.' : 'Add questions here, then assemble them into quizzes.'} />
          : (
            <div className="space-y-2">
              {shown.map(q => (
                <div key={q.id} className="card-3d" style={{ padding:'14px 16px' }}>
                  <div className="flex items-start justify-between gap-3">
                    <div className="flex-1 min-w-0">
                      <p className="text-sm font-semibold" style={{ color:'var(--text-h)' }}>{q.question_text}</p>
                      <div className="flex items-center gap-2 flex-wrap mt-1.5">
                        <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:'rgba(124,58,237,0.1)', color:'#a78bfa' }}>
                          {TYPE_LABEL[q.question_type] || q.question_type}
                        </span>
                        <span className="text-[10px]" style={{ color:'var(--text-muted)' }}>{q.marks} mark{q.marks === 1 ? '' : 's'}</span>
                        {q.category_name && <span className="text-[10px]" style={{ color:'var(--text-muted)' }}>· {q.category_name}</span>}
                        {!q.is_active && <span className="text-[10px] font-bold" style={{ color:'#f59e0b' }}>· Inactive</span>}
                      </div>
                    </div>
                    <div className="flex gap-1.5 flex-shrink-0">
                      <button onClick={()=>openEdit(q)} className="p-1.5 rounded-lg" style={{ background:'rgba(124,58,237,0.1)', color:'#a78bfa' }}><Pencil size={13}/></button>
                      <button onClick={()=>remove(q)} className="p-1.5 rounded-lg" style={{ background:'rgba(239,68,68,0.1)', color:'#f87171' }}><Trash2 size={13}/></button>
                    </div>
                  </div>

                  {/* The answer key is shown here because this IS the authoring
                      view — the paper an employee sits never carries it. */}
                  <div className="mt-2.5 space-y-1">
                    {(q.options || []).map((o, i) => (
                      <div key={o.id ?? i} className="flex items-center gap-2">
                        {o.is_correct
                          ? <CheckCircle2 size={13} style={{ color:'#10b981', flexShrink:0 }}/>
                          : <Circle size={13} style={{ color:'var(--text-muted)', flexShrink:0 }}/>}
                        <span className="text-[11px]" style={{ color: o.is_correct ? '#10b981' : 'var(--text-muted)' }}>{o.option_text}</span>
                      </div>
                    ))}
                  </div>

                  {q.explanation && (
                    <p className="text-[10px] mt-2 pt-2" style={{ color:'var(--text-muted)', borderTop:'1px dashed var(--border)' }}>
                      {q.explanation}
                    </p>
                  )}
                </div>
              ))}
            </div>
          )}

      {modal && (
        <QuestionModal
          modal={modal} setModal={setModal} types={types} categories={categories}
          saving={saving} onSave={save} onClose={()=>setModal(null)}
        />
      )}
    </div>
  )
}

function QuestionModal({ modal, setModal, types, categories, saving, onSave, onClose }) {
  const f = modal.form
  const setF = (patch) => setModal(m => ({ ...m, form: { ...m.form, ...patch } }))
  const setOpt = (i, patch) => setF({ options: f.options.map((o, ix) => ix === i ? { ...o, ...patch } : o) })

  /**
   * Choosing "correct" behaves like the question type says it does: on a
   * single-choice or true/false question, marking one option unmarks the rest.
   * Letting the UI stage a state the server will reject is a worse experience
   * than the radio behaviour the type already implies.
   */
  const toggleCorrect = (i) => {
    if (f.question_type === 'multiple_choice') return setOpt(i, { is_correct: !f.options[i].is_correct })
    setF({ options: f.options.map((o, ix) => ({ ...o, is_correct: ix === i })) })
  }

  const changeType = (t) => {
    if (t === 'boolean') {
      // True/False has a fixed pair of options — anything typed before is not
      // meaningful for this type.
      return setF({ question_type: t, options: [{ option_text:'True', is_correct:true }, { option_text:'False', is_correct:false }] })
    }
    // Collapsing to single choice: keep only the first correct answer.
    if (t !== 'multiple_choice') {
      const first = f.options.findIndex(o => o.is_correct)
      return setF({ question_type: t, options: f.options.map((o, ix) => ({ ...o, is_correct: ix === (first < 0 ? 0 : first) })) })
    }
    setF({ question_type: t })
  }

  const isBoolean = f.question_type === 'boolean'

  return (
    <div className="modal-backdrop">
      <div className="modal-box" onClick={e=>e.stopPropagation()} style={{ maxWidth:620, width:'95%', maxHeight:'92vh', overflowY:'auto' }}>
        <div className="flex items-center justify-between mb-4">
          <h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>{modal.editing ? 'Edit Question' : 'Add Question'}</h2>
          <button onClick={onClose} style={{ color:'var(--text-muted)' }}><X size={18}/></button>
        </div>

        <div className="space-y-3">
          <div>
            <label className="label">Question *</label>
            <textarea rows={3} className="input-3d text-sm resize-none" value={f.question_text}
              onChange={e=>setF({ question_text:e.target.value })} placeholder="e.g. Which of these are safety hazards on a scaffold?"/>
          </div>

          <div className="grid grid-cols-3 gap-3">
            <div>
              <label className="label">Type</label>
              <select className="input-3d text-sm" value={f.question_type} onChange={e=>changeType(e.target.value)}>
                {(types.length ? types : ['single_choice','multiple_choice','boolean']).map(t =>
                  <option key={t} value={t}>{TYPE_LABEL[t] || t}</option>)}
              </select>
            </div>
            <div>
              <label className="label">Marks</label>
              <input type="number" min="0" step="0.5" className="input-3d text-sm" value={f.marks}
                onChange={e=>setF({ marks:e.target.value })}/>
            </div>
            <div>
              <label className="label">Category</label>
              <select className="input-3d text-sm" value={f.category_id} onChange={e=>setF({ category_id:e.target.value })}>
                <option value="">— None —</option>
                {categories.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
              </select>
            </div>
          </div>

          <div>
            <div className="flex items-center justify-between mb-1.5">
              <label className="label" style={{ marginBottom:0 }}>
                Answer options * <span style={{ fontWeight:400, textTransform:'none', letterSpacing:0 }}>
                  — tick the correct {f.question_type === 'multiple_choice' ? 'answers' : 'answer'}
                </span>
              </label>
              {!isBoolean && f.options.length < 10 && (
                <button onClick={()=>setF({ options:[...f.options, { option_text:'', is_correct:false }] })}
                  className="text-[11px] font-bold" style={{ color:'#a78bfa' }}>+ Add option</button>
              )}
            </div>

            <div className="space-y-2">
              {f.options.map((o, i) => (
                <div key={i} className="flex items-center gap-2">
                  <button onClick={()=>toggleCorrect(i)} title="Mark as correct" className="flex-shrink-0">
                    {o.is_correct
                      ? <CheckCircle2 size={18} style={{ color:'#10b981' }}/>
                      : <Circle size={18} style={{ color:'var(--text-muted)' }}/>}
                  </button>
                  <input className="input-3d text-sm flex-1" value={o.option_text} disabled={isBoolean}
                    placeholder={`Option ${i+1}`} onChange={e=>setOpt(i, { option_text:e.target.value })}/>
                  {!isBoolean && f.options.length > 2 && (
                    <button onClick={()=>setF({ options: f.options.filter((_, ix) => ix !== i) })}
                      className="p-1.5 rounded-lg flex-shrink-0" style={{ background:'rgba(239,68,68,0.1)', color:'#f87171' }}>
                      <Trash2 size={12}/>
                    </button>
                  )}
                </div>
              ))}
            </div>
          </div>

          <div>
            <label className="label">Explanation <span style={{ fontWeight:400, textTransform:'none', letterSpacing:0 }}>(shown after the attempt, optional)</span></label>
            <textarea rows={2} className="input-3d text-sm resize-none" value={f.explanation}
              onChange={e=>setF({ explanation:e.target.value })}/>
          </div>

          <label className="flex items-center gap-2 text-xs font-semibold" style={{ color:'var(--text-h)' }}>
            <input type="checkbox" checked={f.is_active} onChange={e=>setF({ is_active:e.target.checked })}/> Active
          </label>
        </div>

        <div className="flex gap-3 pt-4">
          <button onClick={onClose} className="flex-1 py-2.5 rounded-xl text-sm font-semibold"
            style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Cancel</button>
          <button onClick={onSave} disabled={saving} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white inline-flex items-center justify-center gap-2"
            style={{ background:GRAD, opacity:saving?0.7:1 }}>
            {saving && <Loader2 size={13} className="animate-spin"/>} Save
          </button>
        </div>
      </div>
    </div>
  )
}

/* ══ Quizzes ═════════════════════════════════════════════════════════════ */

function Quizzes({ showToast }) {
  const [rows, setRows]     = useState([])
  const [bank, setBank]     = useState([])
  const [programs, setPrograms] = useState([])
  const [loading, setLoading] = useState(true)
  const [modal, setModal]   = useState(null)
  const [saving, setSaving] = useState(false)
  const [search, setSearch] = useState('')
  const [statusF, setStatusF] = useState('All')

  const load = useCallback(() => {
    setLoading(true)
    hrApi.quizEngine.list()
      .then(setRows)
      .catch(e => showToast?.(e?.response?.data?.message || 'Could not load quizzes', 'error'))
      .finally(() => setLoading(false))
  }, [showToast])

  useEffect(() => { load() }, [load])
  useEffect(() => {
    hrApi.quizEngine.questions().then(setBank).catch(() => {})
    hrApi.learning.programs.list({ status:'Active' }).then(r => setPrograms(r.data || [])).catch(() => {})
  }, [])

  const shown = applyListFilter(rows, {
    search, fields: ['name', 'code', 'program_name'],
    matchers: [[statusF, (r, v) => (r.is_active === false ? 'Inactive' : 'Active') === v]],
  })

  const openCreate = () => setModal({ editing:null, form:{ ...EMPTY_QUIZ, questions: [] } })

  const openEdit = async (r) => {
    try {
      const full = await hrApi.quizEngine.get(r.id)
      setModal({
        editing: full.id,
        form: {
          name: full.name, code: full.code || '',
          training_program_id: full.training_program_id ?? '',
          description: full.description || '',
          pass_percentage: full.pass_percentage, duration_minutes: full.duration_minutes ?? '',
          max_attempts: full.max_attempts ?? '', shuffle_questions: full.shuffle_questions,
          show_correct_answers: full.show_correct_answers, is_active: full.is_active,
          questions: (full.questions || []).map(q => ({
            question_id: q.id,
            marks_override: q.marks_override ?? '',
            question_text: q.question_text, marks: q.marks,
          })),
        },
      })
    } catch (e) { showToast?.(e?.response?.data?.message || 'Could not load the quiz', 'error') }
  }

  const save = async () => {
    const f = modal.form
    if (!f.name.trim()) return showToast?.('Quiz name is required', 'error')
    if (f.questions.length === 0) return showToast?.('Add at least one question to the quiz', 'error')

    setSaving(true)
    try {
      await hrApi.quizEngine.save(modal.editing, {
        name: f.name, code: f.code || null,
        training_program_id: f.training_program_id === '' ? null : Number(f.training_program_id),
        description: f.description || null,
        pass_percentage: Number(f.pass_percentage) || 0,
        duration_minutes: f.duration_minutes === '' ? null : Number(f.duration_minutes),
        max_attempts: f.max_attempts === '' ? null : Number(f.max_attempts),
        shuffle_questions: !!f.shuffle_questions,
        show_correct_answers: !!f.show_correct_answers,
        is_active: !!f.is_active,
        questions: f.questions.map(q => ({
          question_id: q.question_id,
          marks_override: q.marks_override === '' ? null : Number(q.marks_override),
        })),
      })
      showToast?.(`Quiz ${modal.editing ? 'updated' : 'created'}`)
      setModal(null); load()
    } catch (e) { showToast?.(e?.response?.data?.message || 'Could not save the quiz', 'error') }
    finally { setSaving(false) }
  }

  const remove = async (r) => {
    if (!window.confirm(`Delete "${r.name}"?`)) return
    try { await hrApi.quizEngine.remove(r.id); showToast?.('Quiz deleted'); load() }
    catch (e) { showToast?.(e?.response?.data?.message || 'Could not delete the quiz', 'error') }
  }

  return (
    <div className="space-y-4">
      <ListFilter
        search={search} setSearch={setSearch} placeholder="Quiz name, code or programme…"
        selects={[{ key:'status', label:'Status', value:statusF, onChange:setStatusF, options:['All','Active','Inactive'] }]}
        onClear={()=>{ setSearch(''); setStatusF('All') }}
        right={
          <button onClick={openCreate} className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:GRAD }}>
            <Plus size={15}/> Create Quiz
          </button>
        }
      />

      {loading ? <HrLoading label="Loading quizzes…" />
        : shown.length === 0
          ? <HrEmpty icon={FileQuestion} title={rows.length ? 'No matching quizzes' : 'No quizzes yet'}
              hint={rows.length ? 'Nothing matches these filters.' : 'Create a quiz and pick questions from the bank.'} />
          : (
            <div className="card-3d overflow-x-auto" style={{ padding:'6px' }}>
              <table className="w-full text-sm" style={{ minWidth:820 }}>
                <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>
                  {['Quiz','Programme','Questions','Total Marks','Pass %','Attempts','Status','Actions'].map(h =>
                    <th key={h} className={`text-left px-3 py-3 label-caps whitespace-nowrap ${h==='Actions'?'text-right':''}`}>{h}</th>)}
                </tr></thead>
                <tbody>
                  {shown.map(q => (
                    <tr key={q.id} style={{ borderBottom:'1px solid var(--border)' }}>
                      <td className="px-3 py-2.5">
                        <span className="font-bold" style={{ color:'var(--text-h)' }}>{q.name}</span>
                        {q.code && <span className="ml-2 text-[10px] font-mono" style={{ color:'#a78bfa' }}>{q.code}</span>}
                      </td>
                      <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{q.program_name || '—'}</td>
                      <td className="px-3 py-2.5" style={{ color:'var(--text-h)' }}>{q.question_count ?? '—'}</td>
                      <td className="px-3 py-2.5" style={{ color:'var(--text-h)' }}>{q.total_marks ?? '—'}</td>
                      <td className="px-3 py-2.5 font-bold" style={{ color:'#7C3AED' }}>{q.pass_percentage}%</td>
                      <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{q.max_attempts ?? '—'}</td>
                      <td className="px-3 py-2.5">
                        <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg"
                          style={q.is_active ? { background:'rgba(16,185,129,0.12)', color:'#10b981' } : { background:'var(--bg-input)', color:'var(--text-muted)' }}>
                          {q.is_active ? 'Active' : 'Inactive'}
                        </span>
                      </td>
                      <td className="px-3 py-2.5">
                        <div className="flex gap-1.5 justify-end">
                          <button onClick={()=>openEdit(q)} className="p-1.5 rounded-lg" style={{ background:'rgba(124,58,237,0.1)', color:'#a78bfa' }}><Pencil size={13}/></button>
                          <button onClick={()=>remove(q)} className="p-1.5 rounded-lg" style={{ background:'rgba(239,68,68,0.1)', color:'#f87171' }}><Trash2 size={13}/></button>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

      {modal && (
        <QuizModal
          modal={modal} setModal={setModal} bank={bank} programs={programs}
          saving={saving} onSave={save} onClose={()=>setModal(null)}
        />
      )}
    </div>
  )
}

function QuizModal({ modal, setModal, bank, programs, saving, onSave, onClose }) {
  const f = modal.form
  const setF = (patch) => setModal(m => ({ ...m, form: { ...m.form, ...patch } }))
  const [pick, setPick] = useState('')

  const chosenIds = new Set(f.questions.map(q => q.question_id))
  const available = bank.filter(q => !chosenIds.has(q.id) && q.is_active)

  const addQuestion = () => {
    if (!pick) return
    const q = bank.find(x => x.id === Number(pick))
    if (!q) return
    setF({ questions: [...f.questions, { question_id: q.id, question_text: q.question_text, marks: q.marks, marks_override: '' }] })
    setPick('')
  }

  // What the paper is worth, computed the way the server computes it: an
  // override replaces the bank's marks for this quiz only.
  const totalMarks = f.questions.reduce((sum, q) =>
    sum + (q.marks_override === '' || q.marks_override === null ? Number(q.marks || 0) : Number(q.marks_override)), 0)

  return (
    <div className="modal-backdrop">
      <div className="modal-box" onClick={e=>e.stopPropagation()} style={{ maxWidth:720, width:'95%', maxHeight:'92vh', overflowY:'auto' }}>
        <div className="flex items-center justify-between mb-4">
          <h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>{modal.editing ? 'Edit Quiz' : 'Create Quiz'}</h2>
          <button onClick={onClose} style={{ color:'var(--text-muted)' }}><X size={18}/></button>
        </div>

        <div className="grid grid-cols-2 gap-3">
          <div><label className="label">Name *</label>
            <input className="input-3d text-sm" value={f.name} onChange={e=>setF({ name:e.target.value })}/></div>
          <div><label className="label">Code</label>
            <input className="input-3d text-sm" value={f.code} onChange={e=>setF({ code:e.target.value })}/></div>

          <div className="col-span-2"><label className="label">Training Programme</label>
            <select className="input-3d text-sm" value={f.training_program_id} onChange={e=>setF({ training_program_id:e.target.value })}>
              <option value="">— None —</option>
              {programs.map(p => <option key={p.id} value={p.id}>{p.program_name}</option>)}
            </select>
          </div>

          <div><label className="label"><Percent size={11} className="inline mr-1"/>Pass %</label>
            <input type="number" min="0" max="100" className="input-3d text-sm" value={f.pass_percentage} onChange={e=>setF({ pass_percentage:e.target.value })}/></div>
          <div><label className="label"><Clock size={11} className="inline mr-1"/>Duration (min)</label>
            <input type="number" min="1" className="input-3d text-sm" value={f.duration_minutes} onChange={e=>setF({ duration_minutes:e.target.value })}/></div>
          <div><label className="label"><Repeat size={11} className="inline mr-1"/>Max attempts</label>
            <input type="number" min="1" className="input-3d text-sm" value={f.max_attempts} onChange={e=>setF({ max_attempts:e.target.value })}/></div>
          <div className="flex items-end">
            <label className="flex items-center gap-2 text-xs font-semibold pb-2.5" style={{ color:'var(--text-h)' }}>
              <input type="checkbox" checked={f.is_active} onChange={e=>setF({ is_active:e.target.checked })}/> Active
            </label>
          </div>

          <div className="col-span-2"><label className="label">Description</label>
            <textarea rows={2} className="input-3d text-sm resize-none" value={f.description} onChange={e=>setF({ description:e.target.value })}/></div>

          <label className="flex items-center gap-2 text-xs font-semibold" style={{ color:'var(--text-h)' }}>
            <input type="checkbox" checked={f.shuffle_questions} onChange={e=>setF({ shuffle_questions:e.target.checked })}/> Shuffle questions
          </label>
          <label className="flex items-center gap-2 text-xs font-semibold" style={{ color:'var(--text-h)' }}>
            <input type="checkbox" checked={f.show_correct_answers} onChange={e=>setF({ show_correct_answers:e.target.checked })}/> Show answers after submission
          </label>
        </div>

        {/* ── Questions on this quiz ── */}
        <div className="mt-5">
          <div className="flex items-center justify-between gap-3 mb-2">
            <p className="text-[11px] font-bold uppercase flex items-center gap-1.5" style={{ color:'var(--text-muted)', letterSpacing:'0.04em' }}>
              <Layers size={12}/> Questions ({f.questions.length}) · {totalMarks} marks
            </p>
          </div>

          <div className="flex gap-2 mb-2.5">
            <select className="input-3d text-sm flex-1" value={pick} onChange={e=>setPick(e.target.value)}>
              <option value="">
                {available.length ? 'Pick a question from the bank…' : 'No more active questions in the bank'}
              </option>
              {available.map(q => <option key={q.id} value={q.id}>{q.question_text.slice(0, 90)}{q.question_text.length > 90 ? '…' : ''}</option>)}
            </select>
            <button onClick={addQuestion} disabled={!pick} className="px-4 py-2 rounded-xl text-xs font-bold text-white flex-shrink-0"
              style={{ background:GRAD, opacity: pick ? 1 : 0.5 }}>Add</button>
          </div>

          {f.questions.length === 0 ? (
            <p className="text-[11px] px-3 py-2.5 rounded-xl" style={{ background:'var(--bg-input)', color:'var(--text-muted)' }}>
              No questions on this quiz yet. Add them from the bank above — a quiz with no questions cannot be sat.
            </p>
          ) : (
            <div className="space-y-1.5">
              {f.questions.map((q, i) => (
                <div key={q.question_id} className="flex items-center gap-2 px-3 py-2 rounded-xl" style={{ background:'var(--bg-input)' }}>
                  <span className="text-[10px] font-bold flex-shrink-0" style={{ color:'var(--text-muted)', width:18 }}>{i+1}.</span>
                  <span className="text-[11px] flex-1 min-w-0 truncate" style={{ color:'var(--text-h)' }}>{q.question_text}</span>
                  <input type="number" min="0" step="0.5" placeholder={String(q.marks ?? '')} value={q.marks_override}
                    title="Marks for this quiz only — leave blank to use the bank's value"
                    onChange={e=>setF({ questions: f.questions.map((x, ix) => ix === i ? { ...x, marks_override:e.target.value } : x) })}
                    className="input-3d text-xs flex-shrink-0" style={{ width:74, padding:'4px 8px' }}/>
                  <button onClick={()=>setF({ questions: f.questions.filter((_, ix) => ix !== i) })}
                    className="p-1.5 rounded-lg flex-shrink-0" style={{ background:'rgba(239,68,68,0.1)', color:'#f87171' }}>
                    <Trash2 size={12}/>
                  </button>
                </div>
              ))}
            </div>
          )}
        </div>

        <div className="flex gap-3 pt-4">
          <button onClick={onClose} className="flex-1 py-2.5 rounded-xl text-sm font-semibold"
            style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Cancel</button>
          <button onClick={onSave} disabled={saving} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white inline-flex items-center justify-center gap-2"
            style={{ background:GRAD, opacity:saving?0.7:1 }}>
            {saving && <Loader2 size={13} className="animate-spin"/>} Save
          </button>
        </div>
      </div>
    </div>
  )
}
