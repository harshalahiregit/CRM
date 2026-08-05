import { useState, useEffect, useCallback } from 'react'
import { FileQuestion, Plus, Shuffle, X, Trash2, Check, Save, Loader2 } from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import Modal from '@/components/ui/Modal'

/**
 * #10 part 4 — questions asked in one interview round, and their evaluation.
 *
 * Purely additive to the interview workflow: a round with no questions renders
 * an empty state and behaves exactly as every round did before this existed.
 * Scheduling, completion and feedback all stay with InterviewService.
 */

const TYPE_LABEL = {
  mcq: 'MCQ', subjective: 'Subjective', coding: 'Coding', practical: 'Practical',
  behavioural: 'Behavioural', technical: 'Technical', hr: 'HR',
}

export default function InterviewQuestionPanel({ roundId, manageHr, readOnly = false, showToast }) {
  const [data, setData]     = useState(null)
  const [loading, setLoading] = useState(true)
  const [attach, setAttach] = useState(false)
  const [saving, setSaving] = useState(false)
  const [dirty, setDirty]   = useState({})

  const load = useCallback(async () => {
    setLoading(true)
    try { setData(await hrApi.interviewQuestions.forRound(roundId)) }
    catch (e) { console.error(e); setData(null) }
    finally { setLoading(false) }
  }, [roundId])
  useEffect(() => { load() }, [load])

  const edit = (id, patch) => setDirty(d => ({ ...d, [id]: { ...(d[id] || {}), ...patch } }))

  const saveEvaluation = async () => {
    const answers = Object.entries(dirty).map(([id, v]) => ({ id: Number(id), ...v }))
    if (answers.length === 0) return
    setSaving(true)
    try {
      setData(await hrApi.interviewQuestions.evaluate(roundId, answers))
      setDirty({})
      showToast?.('Evaluation saved')
    } catch (e) { showToast?.(e?.response?.data?.message || 'Could not save evaluation', 'error') }
    finally { setSaving(false) }
  }

  const detach = async (rqId) => {
    try { setData(await hrApi.interviewQuestions.detach(roundId, rqId)); showToast?.('Question removed') }
    catch (e) { showToast?.(e?.response?.data?.message || 'Could not remove', 'error') }
  }

  if (loading) return <p className="text-xs" style={{ color:'var(--text-muted)' }}>Loading questions…</p>

  const questions = data?.questions || []
  const s = data?.summary

  return (
    <div className="card-3d" style={{ padding:'18px' }}>
      <div className="flex items-start justify-between gap-3 mb-3 flex-wrap">
        <div>
          <h3 className="font-bold text-sm flex items-center gap-2" style={{ color:'var(--text-h)' }}>
            <FileQuestion size={15} style={{ color:'#a78bfa' }}/> Interview Questions
          </h3>
          {s?.total > 0 && (
            <p className="text-[11px] mt-0.5" style={{ color:'var(--text-muted)' }}>
              {s.evaluated} of {s.total} evaluated · {s.total_score} / {s.total_marks} marks
              {s.percent !== null && <b style={{ color:'#10b981' }}> · {s.percent}%</b>}
            </p>
          )}
        </div>
        {manageHr && !readOnly && (
          <button onClick={()=>setAttach(true)}
            className="flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold"
            style={{ background:'rgba(124,58,237,0.12)', border:'1px solid rgba(124,58,237,0.4)', color:'#a78bfa' }}>
            <Plus size={13}/> Add Questions
          </button>
        )}
      </div>

      {questions.length === 0 ? (
        <p className="text-[11px]" style={{ color:'var(--text-muted)' }}>
          No questions attached. {manageHr && !readOnly
            ? 'Add them from the bank — pick a set, choose individually, or select at random.'
            : 'The interviewer will add them from the question bank.'}
        </p>
      ) : (
        <div className="space-y-2">
          {questions.map((q, i) => {
            const local = dirty[q.id] || {}
            const score = local.score !== undefined ? local.score : (q.score ?? '')
            const notes = local.answer_notes !== undefined ? local.answer_notes : (q.answer_notes ?? '')
            const selected = local.selected_options || q.selected_options || []

            return (
              <div key={q.id} className="rounded-xl p-3" style={{ background:'var(--bg-input)', border:'1px solid var(--border)' }}>
                <div className="flex items-start gap-2">
                  <span className="text-[11px] font-black flex-shrink-0" style={{ color:'var(--text-muted)', width:18 }}>{i + 1}.</span>
                  <div className="flex-1 min-w-0">
                    <p className="text-xs font-semibold" style={{ color:'var(--text-h)' }}>{q.question_text}</p>
                    <div className="flex items-center gap-1.5 mt-1 flex-wrap">
                      <span className="px-1.5 py-0.5 rounded text-[9px] font-bold" style={{ background:'rgba(124,58,237,0.12)', color:'#a78bfa' }}>
                        {TYPE_LABEL[q.question_type] || q.question_type}
                      </span>
                      {q.selection_mode === 'random' && (
                        <span className="px-1.5 py-0.5 rounded text-[9px] font-bold" style={{ background:'rgba(14,165,233,0.12)', color:'#0ea5e9' }}>Random</span>
                      )}
                      <span className="text-[10px]" style={{ color:'var(--text-muted)' }}>{q.marks} marks</span>
                    </div>
                  </div>
                  {manageHr && !readOnly && (
                    <button onClick={()=>detach(q.id)} title="Remove" style={{ color:'#f87171' }}><Trash2 size={12}/></button>
                  )}
                </div>

                {/* MCQ: pick what the candidate chose. Scoring follows from the
                    answer key unless the interviewer overrides it. */}
                {q.question_type === 'mcq' && q.options?.length > 0 && (
                  <div className="mt-2 space-y-1 pl-6">
                    {q.options.map((o, oi) => (
                      <label key={oi} className="flex items-center gap-2 text-[11px] cursor-pointer" style={{ color:'var(--text-h)' }}>
                        <input type="checkbox" disabled={readOnly} checked={selected.includes(o.text)}
                          onChange={e => edit(q.id, {
                            selected_options: e.target.checked
                              ? [...selected, o.text]
                              : selected.filter(x => x !== o.text),
                          })}/>
                        <span>{o.text}</span>
                        {q.score !== null && o.is_correct && <Check size={11} style={{ color:'#10b981' }}/>}
                      </label>
                    ))}
                    {q.is_correct !== null && q.is_correct !== undefined && (
                      <p className="text-[10px] font-bold" style={{ color: q.is_correct ? '#10b981' : '#f87171' }}>
                        {q.is_correct ? 'Correct' : 'Incorrect'}
                      </p>
                    )}
                  </div>
                )}

                {q.expected_answer && (
                  <p className="text-[10px] mt-2 pl-6" style={{ color:'var(--text-muted)' }}>
                    <b>Look for:</b> {q.expected_answer}
                  </p>
                )}

                {!readOnly && (
                  <div className="mt-2 pl-6 grid grid-cols-1 sm:grid-cols-4 gap-2">
                    <div>
                      <label className="label" style={{ fontSize:9 }}>Score (max {q.marks})</label>
                      <input type="number" min="0" max={q.marks} step="0.5" className="input-3d text-sm"
                        value={score} onChange={e=>edit(q.id, { score: e.target.value })}/>
                    </div>
                    <div className="sm:col-span-3">
                      <label className="label" style={{ fontSize:9 }}>Notes on the answer</label>
                      <input className="input-3d text-sm" value={notes}
                        onChange={e=>edit(q.id, { answer_notes: e.target.value })}
                        placeholder="What they actually said"/>
                    </div>
                  </div>
                )}
              </div>
            )
          })}

          {!readOnly && Object.keys(dirty).length > 0 && (
            <button onClick={saveEvaluation} disabled={saving}
              className="flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-bold text-white"
              style={{ background:'linear-gradient(135deg,#7C3AED,#5b21b6)', opacity:saving?0.7:1 }}>
              {saving ? <Loader2 size={14} className="animate-spin"/> : <Save size={14}/>}
              Save Evaluation ({Object.keys(dirty).length})
            </button>
          )}
        </div>
      )}

      {attach && <AttachModal roundId={roundId} onClose={()=>setAttach(false)}
        onDone={(d)=>{ setData(d); setAttach(false); showToast?.('Questions attached') }}
        onError={(m)=>showToast?.(m, 'error')} />}
    </div>
  )
}

/** Manual selection, a saved set, or a random draw — the three the comment asks for. */
function AttachModal({ roundId, onClose, onDone, onError }) {
  const [mode, setMode]     = useState('set')
  const [sets, setSets]     = useState([])
  const [bank, setBank]     = useState([])
  const [setId, setSetId]   = useState('')
  const [picked, setPicked] = useState([])
  const [search, setSearch] = useState('')
  const [random, setRandom] = useState({ count: 5, difficulty: 'All', types: [], skills: '' })
  const [busy, setBusy]     = useState(false)

  useEffect(() => {
    hrApi.interviewQuestions.sets({ is_active: 1 }).then(setSets).catch(() => setSets([]))
    hrApi.interviewQuestions.list({ is_active: 1 }).then(setBank).catch(() => setBank([]))
  }, [])

  const submit = async () => {
    setBusy(true)
    try {
      const payload = mode === 'set' ? { set_id: setId }
        : mode === 'manual' ? { question_ids: picked }
        : { random: {
            count: Number(random.count) || 5,
            difficulty: random.difficulty,
            types: random.types.length ? random.types : undefined,
            skills: random.skills ? random.skills.split(',').map(s => s.trim()).filter(Boolean) : undefined,
          } }
      onDone(await hrApi.interviewQuestions.attach(roundId, payload))
    } catch (e) { onError(e?.response?.data?.message || 'Could not attach questions') }
    finally { setBusy(false) }
  }

  const visible = bank.filter(q => !search || q.question_text.toLowerCase().includes(search.toLowerCase()))
  const canSubmit = mode === 'set' ? !!setId : mode === 'manual' ? picked.length > 0 : true

  return (
    <Modal open onClose={onClose} className="max-w-xl" style={{ maxHeight:'92vh', overflowY:'auto' }}>
      <div className="flex items-center justify-between mb-4">
        <h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>Add Questions</h2>
        <button onClick={onClose} style={{ color:'var(--text-muted)' }}><X size={18}/></button>
      </div>

      <div className="flex gap-1.5 mb-4 flex-wrap">
        {[['set', 'From a Set'], ['manual', 'Choose Manually'], ['random', 'Random']].map(([k, l]) => (
          <button key={k} onClick={()=>setMode(k)}
            className="px-3 py-1.5 rounded-xl text-xs font-bold"
            style={{ background: mode === k ? 'linear-gradient(135deg,#7C3AED,#5b21b6)' : 'var(--bg-input)',
                     color: mode === k ? '#fff' : 'var(--text-muted)',
                     border: mode === k ? 'none' : '1px solid var(--border)' }}>{l}</button>
        ))}
      </div>

      {mode === 'set' && (
        <div>
          <label className="label">Question Set</label>
          <select className="input-3d text-sm" value={setId} onChange={e=>setSetId(e.target.value)}>
            <option value="">Select a set…</option>
            {sets.map(s => <option key={s.id} value={s.id}>{s.name} · {s.question_count} questions</option>)}
          </select>
        </div>
      )}

      {mode === 'manual' && (
        <div className="space-y-2">
          <input className="input-3d text-sm" placeholder="Search the bank…" value={search} onChange={e=>setSearch(e.target.value)}/>
          <div className="space-y-1" style={{ maxHeight:'44vh', overflowY:'auto' }}>
            {visible.map(q => (
              <label key={q.id} className="flex items-start gap-2 px-2.5 py-2 rounded-lg cursor-pointer" style={{ background:'var(--bg-input)' }}>
                <input type="checkbox" className="mt-0.5" checked={picked.includes(q.id)}
                  onChange={()=>setPicked(p => p.includes(q.id) ? p.filter(x => x !== q.id) : [...p, q.id])}/>
                <span className="text-[11px]" style={{ color:'var(--text-h)' }}>{q.question_text}</span>
              </label>
            ))}
          </div>
        </div>
      )}

      {mode === 'random' && (
        <div className="space-y-3">
          <div className="grid grid-cols-2 gap-3">
            <div><label className="label">How many</label>
              <input type="number" min="1" max="50" className="input-3d text-sm" value={random.count}
                onChange={e=>setRandom(r => ({ ...r, count: e.target.value }))}/></div>
            <div><label className="label">Difficulty</label>
              <select className="input-3d text-sm" value={random.difficulty} onChange={e=>setRandom(r => ({ ...r, difficulty: e.target.value }))}>
                <option value="All">Any</option>
                {['easy','medium','hard','expert'].map(d => <option key={d} value={d}>{d}</option>)}
              </select></div>
          </div>
          <div><label className="label">Skills (comma separated)</label>
            <input className="input-3d text-sm" value={random.skills} onChange={e=>setRandom(r => ({ ...r, skills: e.target.value }))} placeholder="React, SQL"/></div>
          <div>
            <label className="label">Types</label>
            <div className="flex flex-wrap gap-1.5">
              {Object.entries(TYPE_LABEL).map(([k, v]) => (
                <button key={k} onClick={()=>setRandom(r => ({ ...r,
                  types: r.types.includes(k) ? r.types.filter(x => x !== k) : [...r.types, k] }))}
                  className="px-2.5 py-1 rounded-lg text-[11px] font-bold"
                  style={{ background: random.types.includes(k) ? 'rgba(124,58,237,0.15)' : 'var(--bg-input)',
                           color: random.types.includes(k) ? '#a78bfa' : 'var(--text-muted)',
                           border:'1px solid var(--border)' }}>{v}</button>
              ))}
            </div>
          </div>
          <p className="text-[10px]" style={{ color:'var(--text-muted)' }}>
            Only active questions are drawn — deactivating one is how it stays out of interviews.
          </p>
        </div>
      )}

      <div className="flex gap-3 pt-4">
        <button onClick={onClose} className="flex-1 py-2.5 rounded-xl text-sm font-semibold"
          style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Cancel</button>
        <button onClick={submit} disabled={busy || !canSubmit}
          className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white flex items-center justify-center gap-2"
          style={{ background:'linear-gradient(135deg,#7C3AED,#5b21b6)', opacity:(busy || !canSubmit)?0.7:1 }}>
          {busy ? <Loader2 size={14} className="animate-spin"/> : mode === 'random' ? <Shuffle size={14}/> : <Plus size={14}/>}
          Attach
        </button>
      </div>
    </Modal>
  )
}
