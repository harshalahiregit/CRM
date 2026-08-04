import { useState, useEffect, useCallback } from 'react'
import { useTheme } from '@/context/ThemeContext'
import {
  FileQuestion, Plus, Search, Sparkles, X, Trash2, Power, Pencil,
  Layers, Check, AlertTriangle, Loader2,
} from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { useMasterData } from '@/modules/hr/useMasterData'
import { HrLoading, HrEmpty } from '@/components/ui/HrState'
import Modal from '@/components/ui/Modal'

/**
 * Review comment #10 — "no option to set various questions and no AI generated
 * question relevant to profile".
 *
 * The bank, its filters, and AI generation. Generated questions arrive as DRAFTS
 * in a review step — they are editable and individually dismissable before
 * anything is saved, because a bank quietly filled by a model is a bank nobody
 * trusts.
 */

const TYPE_LABEL = {
  mcq: 'MCQ', subjective: 'Subjective', coding: 'Coding', practical: 'Practical',
  behavioural: 'Behavioural', technical: 'Technical', hr: 'HR',
}
const TYPE_COLOUR = {
  mcq: '#0ea5e9', subjective: '#a78bfa', coding: '#10b981', practical: '#f59e0b',
  behavioural: '#ec4899', technical: '#6366f1', hr: '#14b8a6',
}
const DIFF_COLOUR = { easy: '#10b981', medium: '#f59e0b', hard: '#f87171', expert: '#a855f7' }

const EMPTY = {
  question_text: '', question_type: 'subjective', category: '', designation_id: '',
  skills: '', tags: '', difficulty: 'medium', experience_min: '', experience_max: '',
  options: [], expected_answer: '', marks: 5, is_active: true,
}

export default function InterviewQuestionBank() {
  useTheme()
  const [tab, setTab] = useState('bank')
  const [toast, setToast] = useState(null)
  const showToast = (msg, type = 'success') => { setToast({ msg, type }); setTimeout(() => setToast(null), 3000) }

  return (
    <div className="space-y-6 animate-[tiltIn_0.35s_ease_forwards]">
      {toast && (
        <div className="fixed top-5 right-5 z-[9999] px-5 py-3 rounded-2xl text-sm font-semibold text-white shadow-2xl"
          style={{ background: toast.type === 'success' ? 'linear-gradient(135deg,#10b981,#059669)' : 'linear-gradient(135deg,#f87171,#ef4444)' }}>
          {toast.msg}
        </div>
      )}

      <div>
        <p className="label-caps mb-1">HR Recruitment</p>
        <h1 className="font-black flex items-center gap-2" style={{ fontSize:'clamp(1.3rem,2vw,1.7rem)', color:'var(--text-h)', letterSpacing:'-0.02em' }}>
          <FileQuestion size={22} style={{ color:'#a78bfa' }}/> Interview <span className="text-gradient">Question Bank</span>
        </h1>
        <p className="text-sm mt-0.5" style={{ color:'var(--text-muted)' }}>
          Reusable questions by skill, role, difficulty and experience — attached to interview rounds and scored there.
        </p>
      </div>

      <div className="flex gap-1.5 flex-wrap">
        {[['bank', 'Question Bank', FileQuestion], ['sets', 'Question Sets', Layers]].map(([key, label, Icon]) => (
          <button key={key} onClick={()=>setTab(key)}
            className="flex items-center gap-2 px-3.5 py-2 rounded-xl text-sm font-bold transition-all"
            style={{ background: tab === key ? 'linear-gradient(135deg,#7C3AED,#5b21b6)' : 'var(--bg-input)',
                     color: tab === key ? '#fff' : 'var(--text-muted)',
                     border: tab === key ? 'none' : '1px solid var(--border)' }}>
            <Icon size={15}/> {label}
          </button>
        ))}
      </div>

      {tab === 'bank' ? <Bank showToast={showToast} /> : <Sets showToast={showToast} />}
    </div>
  )
}

/* ── Bank ─────────────────────────────────────────────────────────────── */

function Bank({ showToast }) {
  const [rows, setRows]       = useState([])
  const [meta, setMeta]       = useState({ types: [], difficulties: [], categories: [] })
  const [loading, setLoading] = useState(true)
  const [filters, setFilters] = useState({ search: '', question_type: 'All', difficulty: 'All', category: 'All', designation_id: 'All', is_active: 'All', skills: '', experience: '' })
  const [modal, setModal]     = useState(null)
  const [aiOpen, setAiOpen]   = useState(false)
  const { masters } = useMasterData()

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const clean = Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== '' && v !== 'All'))
      const [list, m] = await Promise.all([hrApi.interviewQuestions.list(clean), hrApi.interviewQuestions.meta()])
      setRows(list); setMeta(m)
    } catch (e) { console.error(e); setRows([]) }
    finally { setLoading(false) }
  }, [filters])
  useEffect(() => { load() }, [load])

  const set = (k) => (e) => setFilters(f => ({ ...f, [k]: e.target.value }))

  const act = async (fn, ok) => {
    try { await fn(); showToast(ok); load() }
    catch (e) { showToast(e?.response?.data?.message || 'Action failed', 'error') }
  }

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-end gap-2 flex-wrap">
        <button onClick={()=>setAiOpen(true)}
          className="flex items-center gap-1.5 px-3.5 py-2 rounded-xl text-sm font-bold"
          style={{ background:'rgba(124,58,237,0.12)', border:'1px solid rgba(124,58,237,0.4)', color:'#a78bfa' }}>
          <Sparkles size={15}/> Generate with AI
        </button>
        <button onClick={()=>setModal({ id:null, form:{ ...EMPTY } })}
          className="flex items-center gap-1.5 px-3.5 py-2 rounded-xl text-sm font-bold text-white"
          style={{ background:'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>
          <Plus size={15}/> Add Question
        </button>
      </div>

      {/* Every facet the comment asked for, in one bar. */}
      <div className="card-3d flex items-center gap-2.5 flex-wrap" style={{ padding:'12px 16px' }}>
        <div style={{ position:'relative', flex:1, minWidth:200 }}>
          <Search size={14} style={{ position:'absolute', left:10, top:'50%', transform:'translateY(-50%)', color:'var(--text-muted)' }}/>
          <input className="input-3d text-sm" style={{ paddingLeft:32 }} placeholder="Search question or answer…"
            value={filters.search} onChange={set('search')}/>
        </div>
        <select className="input-3d text-sm" style={{ width:'auto' }} value={filters.question_type} onChange={set('question_type')}>
          <option value="All">All Types</option>
          {meta.types.map(t => <option key={t} value={t}>{TYPE_LABEL[t] || t}</option>)}
        </select>
        <select className="input-3d text-sm" style={{ width:'auto' }} value={filters.difficulty} onChange={set('difficulty')}>
          <option value="All">All Difficulty</option>
          {meta.difficulties.map(d => <option key={d} value={d}>{d}</option>)}
        </select>
        <select className="input-3d text-sm" style={{ width:'auto' }} value={filters.category} onChange={set('category')}>
          <option value="All">All Categories</option>
          {meta.categories.map(c => <option key={c} value={c}>{c}</option>)}
        </select>
        <select className="input-3d text-sm" style={{ width:'auto' }} value={filters.designation_id} onChange={set('designation_id')}>
          <option value="All">All Roles</option>
          {(masters.designations || []).map(d => <option key={d.id} value={d.id}>{d.name}</option>)}
        </select>
        <input className="input-3d text-sm" style={{ width:130 }} placeholder="Skills…" value={filters.skills} onChange={set('skills')}/>
        <input className="input-3d text-sm" style={{ width:110 }} type="number" min="0" placeholder="Exp (yrs)" value={filters.experience} onChange={set('experience')}/>
        <select className="input-3d text-sm" style={{ width:'auto' }} value={filters.is_active} onChange={set('is_active')}>
          <option value="All">Active &amp; Inactive</option>
          <option value="1">Active only</option>
          <option value="0">Inactive only</option>
        </select>
      </div>

      {loading ? <HrLoading label="Loading questions…" />
        : rows.length === 0 ? (
          <HrEmpty icon={FileQuestion} title="No questions match"
            hint="Add one manually, or generate a set from a job posting with AI." />
        ) : (
          <div className="space-y-2">
            {rows.map(q => (
              <div key={q.id} className="card-3d" style={{ padding:'14px 16px', opacity: q.is_active ? 1 : 0.55 }}>
                <div className="flex items-start justify-between gap-3">
                  <div className="min-w-0 flex-1">
                    <p className="text-sm font-semibold" style={{ color:'var(--text-h)' }}>{q.question_text}</p>
                    <div className="flex items-center gap-1.5 mt-2 flex-wrap">
                      <Tag colour={TYPE_COLOUR[q.question_type]}>{TYPE_LABEL[q.question_type] || q.question_type}</Tag>
                      <Tag colour={DIFF_COLOUR[q.difficulty]}>{q.difficulty}</Tag>
                      {q.category && <Tag colour="#64748b">{q.category}</Tag>}
                      {q.designation && <Tag colour="#7C3AED">{q.designation}</Tag>}
                      {q.source === 'ai' && <Tag colour="#a78bfa"><Sparkles size={9}/> AI</Tag>}
                      {!q.is_active && <Tag colour="#94a3b8">Inactive</Tag>}
                      {(q.experience_min !== null || q.experience_max !== null) && (
                        <Tag colour="#0ea5e9">
                          {q.experience_min ?? 0}–{q.experience_max ?? '∞'} yrs
                        </Tag>
                      )}
                      <span className="text-[10px]" style={{ color:'var(--text-muted)' }}>{q.marks} marks</span>
                    </div>
                    {q.skills?.length > 0 && (
                      <p className="text-[10px] mt-1.5" style={{ color:'var(--text-muted)' }}>
                        Skills: {q.skills.join(', ')}
                      </p>
                    )}
                    {q.question_type === 'mcq' && q.options?.length > 0 && (
                      <div className="mt-2 space-y-0.5">
                        {q.options.map((o, i) => (
                          <p key={i} className="text-[11px] flex items-center gap-1.5"
                            style={{ color: o.is_correct ? '#10b981' : 'var(--text-muted)' }}>
                            {o.is_correct ? <Check size={10}/> : <span style={{ width:10 }}/>} {o.text}
                          </p>
                        ))}
                      </div>
                    )}
                  </div>

                  <div className="flex items-center gap-1.5 flex-shrink-0">
                    <IconBtn onClick={()=>setModal({ id:q.id, form:toForm(q) })} colour="#a78bfa" title="Edit"><Pencil size={12}/></IconBtn>
                    <IconBtn onClick={()=>act(()=>hrApi.interviewQuestions.toggle(q.id), q.is_active ? 'Deactivated' : 'Activated')}
                      colour={q.is_active ? '#f59e0b' : '#10b981'} title={q.is_active ? 'Deactivate' : 'Activate'}><Power size={12}/></IconBtn>
                    <IconBtn onClick={()=>{ if (window.confirm('Remove this question? If it has already been asked in an interview it will be retired instead.')) act(()=>hrApi.interviewQuestions.remove(q.id), 'Removed') }}
                      colour="#f87171" title="Remove"><Trash2 size={12}/></IconBtn>
                  </div>
                </div>
              </div>
            ))}
          </div>
        )}

      {modal && <QuestionModal modal={modal} designations={masters.designations || []} onClose={()=>setModal(null)}
        onSaved={(m)=>{ setModal(null); showToast(m); load() }} onError={(m)=>showToast(m, 'error')} />}

      {aiOpen && <AiModal designations={masters.designations || []} onClose={()=>setAiOpen(false)}
        onSaved={(m)=>{ setAiOpen(false); showToast(m); load() }} onError={(m)=>showToast(m, 'error')} />}
    </div>
  )
}

const toForm = (q) => ({
  ...q,
  skills: (q.skills || []).join(', '),
  tags: (q.tags || []).join(', '),
  designation_id: q.designation_id || '',
  experience_min: q.experience_min ?? '',
  experience_max: q.experience_max ?? '',
  options: q.options || [],
})

function Tag({ colour, children }) {
  return (
    <span className="px-1.5 py-0.5 rounded text-[9px] font-bold inline-flex items-center gap-1"
      style={{ background:`${colour}1a`, color:colour }}>{children}</span>
  )
}

function IconBtn({ onClick, colour, title, children }) {
  return (
    <button onClick={onClick} title={title} className="rounded-lg px-2 py-1"
      style={{ background:'var(--bg-input)', border:'1px solid var(--border)', color:colour }}>{children}</button>
  )
}

/* ── Add / edit one question ──────────────────────────────────────────── */

function QuestionModal({ modal, designations, onClose, onSaved, onError }) {
  const [form, setForm] = useState({ ...EMPTY, ...modal.form })
  const [saving, setSaving] = useState(false)
  const set = (k) => (e) => setForm(f => ({ ...f, [k]: e.target.value }))
  const isMcq = form.question_type === 'mcq'

  const setOption = (i, patch) => setForm(f => ({
    ...f, options: f.options.map((o, idx) => idx === i ? { ...o, ...patch } : o),
  }))
  const addOption = () => setForm(f => ({ ...f, options: [...(f.options || []), { text:'', is_correct:false }] }))
  const removeOption = (i) => setForm(f => ({ ...f, options: f.options.filter((_, idx) => idx !== i) }))

  const save = async () => {
    setSaving(true)
    try {
      await hrApi.interviewQuestions.save(modal.id, form)
      onSaved(modal.id ? 'Question updated' : 'Question added')
    } catch (e) { onError(e?.response?.data?.message || 'Could not save') }
    finally { setSaving(false) }
  }

  return (
    <Modal open onClose={onClose} className="max-w-xl" style={{ maxHeight:'92vh', overflowY:'auto' }}>
      <div className="flex items-center justify-between mb-4">
        <h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>{modal.id ? 'Edit Question' : 'Add Question'}</h2>
        <button onClick={onClose} style={{ color:'var(--text-muted)' }}><X size={18}/></button>
      </div>

      <div className="space-y-3">
        <div><label className="label">Question *</label>
          <textarea className="input-3d text-sm" rows={3} value={form.question_text} onChange={set('question_text')}/></div>

        <div className="grid grid-cols-2 gap-3">
          <div><label className="label">Type</label>
            <select className="input-3d text-sm" value={form.question_type} onChange={set('question_type')}>
              {Object.entries(TYPE_LABEL).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
            </select></div>
          <div><label className="label">Difficulty</label>
            <select className="input-3d text-sm" value={form.difficulty} onChange={set('difficulty')}>
              {['easy','medium','hard','expert'].map(d => <option key={d} value={d}>{d}</option>)}
            </select></div>
        </div>

        <div className="grid grid-cols-2 gap-3">
          <div><label className="label">Category</label>
            <input className="input-3d text-sm" value={form.category || ''} onChange={set('category')} placeholder="e.g. System Design"/></div>
          <div><label className="label">Role / Designation</label>
            <select className="input-3d text-sm" value={form.designation_id || ''} onChange={set('designation_id')}>
              <option value="">Any role</option>
              {designations.map(d => <option key={d.id} value={d.id}>{d.name}</option>)}
            </select></div>
        </div>

        <div className="grid grid-cols-2 gap-3">
          <div><label className="label">Skills (comma separated)</label>
            <input className="input-3d text-sm" value={form.skills} onChange={set('skills')} placeholder="React, TypeScript"/></div>
          <div><label className="label">Tags (comma separated)</label>
            <input className="input-3d text-sm" value={form.tags} onChange={set('tags')} placeholder="frontend, senior"/></div>
        </div>

        <div className="grid grid-cols-3 gap-3">
          <div><label className="label">Exp. from (yrs)</label>
            <input type="number" min="0" step="0.5" className="input-3d text-sm" value={form.experience_min} onChange={set('experience_min')}/></div>
          <div><label className="label">Exp. to (yrs)</label>
            <input type="number" min="0" step="0.5" className="input-3d text-sm" value={form.experience_max} onChange={set('experience_max')}/></div>
          <div><label className="label">Marks</label>
            <input type="number" min="0" className="input-3d text-sm" value={form.marks} onChange={set('marks')}/></div>
        </div>

        {isMcq ? (
          <div>
            <div className="flex items-center justify-between mb-1.5">
              <label className="label" style={{ marginBottom:0 }}>Options</label>
              <button onClick={addOption} className="text-[11px] font-bold" style={{ color:'#a78bfa' }}>+ Add option</button>
            </div>
            {/* More than one may be correct — that IS "multiple correct answers
                where applicable", so this is a checkbox, not a radio. */}
            <div className="space-y-1.5">
              {(form.options || []).map((o, i) => (
                <div key={i} className="flex items-center gap-2">
                  <input type="checkbox" checked={!!o.is_correct} onChange={e=>setOption(i, { is_correct: e.target.checked })} title="Correct answer"/>
                  <input className="input-3d text-sm" value={o.text} onChange={e=>setOption(i, { text: e.target.value })} placeholder={`Option ${i + 1}`}/>
                  <button onClick={()=>removeOption(i)} style={{ color:'#f87171' }}><Trash2 size={12}/></button>
                </div>
              ))}
            </div>
            <p className="text-[10px] mt-1" style={{ color:'var(--text-muted)' }}>
              Tick every option that is correct. At least two options and one correct answer are required.
            </p>
          </div>
        ) : (
          <div><label className="label">Expected Answer</label>
            <textarea className="input-3d text-sm" rows={3} value={form.expected_answer || ''} onChange={set('expected_answer')}
              placeholder="What a strong answer contains — this is what lets two interviewers score alike."/></div>
        )}

        <label className="flex items-center gap-2 text-xs font-semibold cursor-pointer" style={{ color:'var(--text-muted)' }}>
          <input type="checkbox" checked={form.is_active !== false} onChange={e=>setForm(f => ({ ...f, is_active: e.target.checked }))}/> Active
        </label>

        <div className="flex gap-3 pt-1">
          <button onClick={onClose} className="flex-1 py-2.5 rounded-xl text-sm font-semibold"
            style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Cancel</button>
          <button onClick={save} disabled={saving || !form.question_text?.trim()}
            className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white"
            style={{ background:'linear-gradient(135deg,#7C3AED,#5b21b6)', opacity:(saving || !form.question_text?.trim())?0.7:1 }}>
            {saving ? 'Saving…' : 'Save Question'}
          </button>
        </div>
      </div>
    </Modal>
  )
}

/* ── AI generation ────────────────────────────────────────────────────── */

function AiModal({ designations, onClose, onSaved, onError }) {
  const [input, setInput] = useState({
    job_posting_id: '', designation: '', skills: '', experience_min: '', experience_max: '',
    count: 8, difficulty: '', types: ['technical', 'behavioural'], job_description: '',
  })
  const [jobs, setJobs]       = useState([])
  const [drafts, setDrafts]   = useState(null)
  const [meta, setMeta]       = useState(null)
  const [busy, setBusy]       = useState(false)
  const [saving, setSaving]   = useState(false)

  useEffect(() => { hrApi.jobs.list().then(setJobs).catch(() => setJobs([])) }, [])

  const set = (k) => (e) => setInput(f => ({ ...f, [k]: e.target.value }))
  const toggleType = (t) => setInput(f => ({
    ...f, types: f.types.includes(t) ? f.types.filter(x => x !== t) : [...f.types, t],
  }))

  const generate = async () => {
    setBusy(true)
    try {
      const payload = {
        ...input,
        skills: input.skills ? input.skills.split(',').map(s => s.trim()).filter(Boolean) : undefined,
        job_posting_id: input.job_posting_id || undefined,
        designation: input.designation || undefined,
        difficulty: input.difficulty || undefined,
        experience_min: input.experience_min === '' ? undefined : Number(input.experience_min),
        experience_max: input.experience_max === '' ? undefined : Number(input.experience_max),
        job_description: input.job_description || undefined,
        count: Number(input.count) || 8,
      }
      const r = await hrApi.interviewQuestions.generate(payload)
      setDrafts(r.questions); setMeta(r.meta)
    } catch (e) { onError(e?.response?.data?.message || 'Generation failed') }
    finally { setBusy(false) }
  }

  const saveAll = async () => {
    setSaving(true)
    try {
      await hrApi.interviewQuestions.saveGenerated(drafts)
      onSaved(`${drafts.length} question(s) added to the bank`)
    } catch (e) { onError(e?.response?.data?.message || 'Could not save') }
    finally { setSaving(false) }
  }

  return (
    <Modal open onClose={onClose} className="max-w-2xl" style={{ maxHeight:'92vh', overflowY:'auto' }}>
      <div className="flex items-center justify-between mb-4">
        <h2 className="font-black text-lg flex items-center gap-2" style={{ color:'var(--text-h)' }}>
          <Sparkles size={18} style={{ color:'#a78bfa' }}/> Generate Interview Questions
        </h2>
        <button onClick={onClose} style={{ color:'var(--text-muted)' }}><X size={18}/></button>
      </div>

      {!drafts ? (
        <div className="space-y-3">
          <div>
            <label className="label">From a Job Posting</label>
            <select className="input-3d text-sm" value={input.job_posting_id} onChange={set('job_posting_id')}>
              <option value="">Not from a posting — describe the role below</option>
              {jobs.map(j => <option key={j.id} value={j.id}>{j.title} · {j.department}</option>)}
            </select>
            <p className="text-[10px] mt-1" style={{ color:'var(--text-muted)' }}>
              Picking a posting reuses its description, title, skills and experience — nothing to retype.
            </p>
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div><label className="label">Designation</label>
              <input className="input-3d text-sm" list="d-list" value={input.designation} onChange={set('designation')} placeholder="e.g. Senior React Developer"/>
              <datalist id="d-list">{designations.map(d => <option key={d.id} value={d.name}/>)}</datalist></div>
            <div><label className="label">Skills (comma separated)</label>
              <input className="input-3d text-sm" value={input.skills} onChange={set('skills')} placeholder="React, Node, SQL"/></div>
          </div>

          <div className="grid grid-cols-4 gap-3">
            <div><label className="label">Exp. from</label>
              <input type="number" min="0" className="input-3d text-sm" value={input.experience_min} onChange={set('experience_min')}/></div>
            <div><label className="label">Exp. to</label>
              <input type="number" min="0" className="input-3d text-sm" value={input.experience_max} onChange={set('experience_max')}/></div>
            <div><label className="label">Count</label>
              <input type="number" min="1" max="25" className="input-3d text-sm" value={input.count} onChange={set('count')}/></div>
            <div><label className="label">Difficulty</label>
              <select className="input-3d text-sm" value={input.difficulty} onChange={set('difficulty')}>
                <option value="">Mixed</option>
                {['easy','medium','hard','expert'].map(d => <option key={d} value={d}>{d}</option>)}
              </select></div>
          </div>

          <div>
            <label className="label">Question types</label>
            <div className="flex flex-wrap gap-1.5">
              {Object.entries(TYPE_LABEL).map(([k, v]) => (
                <button key={k} onClick={()=>toggleType(k)}
                  className="px-2.5 py-1 rounded-lg text-[11px] font-bold"
                  style={{ background: input.types.includes(k) ? `${TYPE_COLOUR[k]}22` : 'var(--bg-input)',
                           color: input.types.includes(k) ? TYPE_COLOUR[k] : 'var(--text-muted)',
                           border:`1px solid ${input.types.includes(k) ? TYPE_COLOUR[k] + '66' : 'var(--border)'}` }}>
                  {v}
                </button>
              ))}
            </div>
          </div>

          <div><label className="label">Extra context (optional)</label>
            <textarea className="input-3d text-sm" rows={3} value={input.job_description} onChange={set('job_description')}
              placeholder="Paste a job description or anything else the questions should reflect."/></div>

          <button onClick={generate} disabled={busy}
            className="w-full py-2.5 rounded-xl text-sm font-bold text-white flex items-center justify-center gap-2"
            style={{ background:'linear-gradient(135deg,#7C3AED,#5b21b6)', opacity:busy?0.7:1 }}>
            {busy ? <><Loader2 size={15} className="animate-spin"/> Generating…</> : <><Sparkles size={15}/> Generate</>}
          </button>
        </div>
      ) : (
        <div className="space-y-3">
          <div className="rounded-xl p-2.5 flex items-start gap-2" style={{ background:'rgba(124,58,237,0.08)', border:'1px solid rgba(124,58,237,0.25)' }}>
            <AlertTriangle size={13} style={{ color:'#a78bfa', flexShrink:0, marginTop:1 }}/>
            <p className="text-[11px]" style={{ color:'#a78bfa' }}>
              {drafts.length} draft{drafts.length === 1 ? '' : 's'} from {meta?.provider} · {meta?.model}.
              Nothing is in the bank yet — edit or drop any of them, then save.
            </p>
          </div>

          <div className="space-y-2" style={{ maxHeight:'46vh', overflowY:'auto' }}>
            {drafts.map((d, i) => (
              <div key={i} className="rounded-xl p-3" style={{ background:'var(--bg-input)', border:'1px solid var(--border)' }}>
                <div className="flex items-start gap-2">
                  <textarea className="input-3d text-sm flex-1" rows={2} value={d.question_text}
                    onChange={e=>setDrafts(list => list.map((x, idx) => idx === i ? { ...x, question_text: e.target.value } : x))}/>
                  <button onClick={()=>setDrafts(list => list.filter((_, idx) => idx !== i))}
                    title="Drop this one" style={{ color:'#f87171' }}><Trash2 size={13}/></button>
                </div>
                <div className="flex items-center gap-1.5 mt-2 flex-wrap">
                  <Tag colour={TYPE_COLOUR[d.question_type]}>{TYPE_LABEL[d.question_type] || d.question_type}</Tag>
                  <Tag colour={DIFF_COLOUR[d.difficulty]}>{d.difficulty}</Tag>
                  {d.category && <Tag colour="#64748b">{d.category}</Tag>}
                  <span className="text-[10px]" style={{ color:'var(--text-muted)' }}>{d.marks} marks</span>
                </div>
                {d.question_type === 'mcq' && d.options?.length > 0 && (
                  <div className="mt-1.5 space-y-0.5">
                    {d.options.map((o, oi) => (
                      <p key={oi} className="text-[11px] flex items-center gap-1.5" style={{ color:o.is_correct ? '#10b981' : 'var(--text-muted)' }}>
                        {o.is_correct ? <Check size={10}/> : <span style={{ width:10 }}/>} {o.text}
                      </p>
                    ))}
                  </div>
                )}
                {d.expected_answer && (
                  <p className="text-[10px] mt-1.5" style={{ color:'var(--text-muted)' }}>
                    <b>Expected:</b> {d.expected_answer}
                  </p>
                )}
              </div>
            ))}
          </div>

          <div className="flex gap-3">
            <button onClick={()=>{ setDrafts(null); generate() }} disabled={busy}
              className="flex-1 py-2.5 rounded-xl text-sm font-bold flex items-center justify-center gap-2"
              style={{ background:'var(--bg-input)', color:'#a78bfa', border:'1px solid rgba(124,58,237,0.4)' }}>
              {busy ? <Loader2 size={14} className="animate-spin"/> : <Sparkles size={14}/>} Regenerate
            </button>
            <button onClick={saveAll} disabled={saving || drafts.length === 0}
              className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white"
              style={{ background:'linear-gradient(135deg,#7C3AED,#5b21b6)', opacity:(saving || !drafts.length)?0.7:1 }}>
              {saving ? 'Saving…' : `Save ${drafts.length} to Bank`}
            </button>
          </div>
        </div>
      )}
    </Modal>
  )
}

/* ── Sets ─────────────────────────────────────────────────────────────── */

function Sets({ showToast }) {
  const [sets, setSets]       = useState([])
  const [bank, setBank]       = useState([])
  const [loading, setLoading] = useState(true)
  const [modal, setModal]     = useState(null)
  const { masters } = useMasterData()

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const [s, b] = await Promise.all([
        hrApi.interviewQuestions.sets(),
        hrApi.interviewQuestions.list({ is_active: 1 }),
      ])
      setSets(s); setBank(b)
    } catch (e) { console.error(e); setSets([]) }
    finally { setLoading(false) }
  }, [])
  useEffect(() => { load() }, [load])

  const remove = async (s) => {
    if (!window.confirm(`Delete "${s.name}"? The questions themselves stay in the bank.`)) return
    try { await hrApi.interviewQuestions.removeSet(s.id); showToast('Set removed'); load() }
    catch (e) { showToast(e?.response?.data?.message || 'Could not remove', 'error') }
  }

  return (
    <div className="space-y-4">
      <div className="flex justify-end">
        <button onClick={()=>setModal({ id:null, form:{ name:'', description:'', designation_id:'', round_name:'', is_active:true, question_ids:[] } })}
          className="flex items-center gap-1.5 px-3.5 py-2 rounded-xl text-sm font-bold text-white"
          style={{ background:'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>
          <Plus size={15}/> New Set
        </button>
      </div>

      {loading ? <HrLoading label="Loading sets…" />
        : sets.length === 0 ? (
          <HrEmpty icon={Layers} title="No question sets yet"
            hint="A set is a reusable selection for one kind of round — attach it to an interview in one click." />
        ) : (
          <div className="grid md:grid-cols-2 gap-3">
            {sets.map(s => (
              <div key={s.id} className="card-3d" style={{ padding:'14px 16px', opacity: s.is_active ? 1 : 0.6 }}>
                <div className="flex items-start justify-between gap-2 mb-2">
                  <div className="min-w-0">
                    <p className="text-xs font-black" style={{ color:'var(--text-h)' }}>{s.name}</p>
                    <p className="text-[10px] mt-0.5" style={{ color:'var(--text-muted)' }}>
                      {[s.designation, s.round_name].filter(Boolean).join(' · ') || 'Any role'}
                      {' · '}{s.question_count} question{s.question_count === 1 ? '' : 's'} · {s.total_marks} marks
                    </p>
                  </div>
                  <div className="flex items-center gap-1.5 flex-shrink-0">
                    <IconBtn onClick={()=>setModal({ id:s.id, form:{ ...s, designation_id:s.designation_id || '', question_ids:(s.questions||[]).map(q => q.id) } })}
                      colour="#a78bfa" title="Edit"><Pencil size={12}/></IconBtn>
                    <IconBtn onClick={()=>remove(s)} colour="#f87171" title="Delete"><Trash2 size={12}/></IconBtn>
                  </div>
                </div>
                {(s.questions || []).slice(0, 3).map(q => (
                  <p key={q.id} className="text-[11px] truncate" style={{ color:'var(--text-muted)' }}>· {q.question_text}</p>
                ))}
                {s.question_count > 3 && <p className="text-[10px] mt-0.5" style={{ color:'var(--text-muted)' }}>+{s.question_count - 3} more</p>}
              </div>
            ))}
          </div>
        )}

      {modal && <SetModal modal={modal} bank={bank} designations={masters.designations || []}
        onClose={()=>setModal(null)} onSaved={(m)=>{ setModal(null); showToast(m); load() }}
        onError={(m)=>showToast(m, 'error')} />}
    </div>
  )
}

function SetModal({ modal, bank, designations, onClose, onSaved, onError }) {
  const [form, setForm] = useState({ ...modal.form })
  const [search, setSearch] = useState('')
  const [saving, setSaving] = useState(false)
  const set = (k) => (e) => setForm(f => ({ ...f, [k]: e.target.value }))

  const toggle = (id) => setForm(f => ({
    ...f, question_ids: f.question_ids.includes(id) ? f.question_ids.filter(x => x !== id) : [...f.question_ids, id],
  }))

  const visible = bank.filter(q => !search || q.question_text.toLowerCase().includes(search.toLowerCase()))

  const save = async () => {
    setSaving(true)
    try {
      await hrApi.interviewQuestions.saveSet(modal.id, form)
      onSaved(modal.id ? 'Set updated' : 'Set created')
    } catch (e) { onError(e?.response?.data?.message || 'Could not save') }
    finally { setSaving(false) }
  }

  return (
    <Modal open onClose={onClose} className="max-w-2xl" style={{ maxHeight:'92vh', overflowY:'auto' }}>
      <div className="flex items-center justify-between mb-4">
        <h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>{modal.id ? 'Edit Set' : 'New Question Set'}</h2>
        <button onClick={onClose} style={{ color:'var(--text-muted)' }}><X size={18}/></button>
      </div>

      <div className="space-y-3">
        <div className="grid grid-cols-2 gap-3">
          <div><label className="label">Name *</label>
            <input className="input-3d text-sm" value={form.name} onChange={set('name')} placeholder="e.g. Senior React — Technical R1"/></div>
          <div><label className="label">Round name</label>
            <input className="input-3d text-sm" value={form.round_name || ''} onChange={set('round_name')} placeholder="e.g. Technical Round 1"/></div>
        </div>
        <div className="grid grid-cols-2 gap-3">
          <div><label className="label">Role / Designation</label>
            <select className="input-3d text-sm" value={form.designation_id || ''} onChange={set('designation_id')}>
              <option value="">Any role</option>
              {designations.map(d => <option key={d.id} value={d.id}>{d.name}</option>)}
            </select></div>
          <div className="flex items-end pb-2">
            <label className="flex items-center gap-2 text-xs font-semibold cursor-pointer" style={{ color:'var(--text-muted)' }}>
              <input type="checkbox" checked={form.is_active !== false} onChange={e=>setForm(f => ({ ...f, is_active: e.target.checked }))}/> Active
            </label>
          </div>
        </div>
        <div><label className="label">Description</label>
          <textarea className="input-3d text-sm" rows={2} value={form.description || ''} onChange={set('description')}/></div>

        <div className="pt-1" style={{ borderTop:'1px solid var(--border)' }}/>

        <div className="flex items-center justify-between">
          <p className="text-[11px] font-bold uppercase" style={{ color:'var(--text-muted)', letterSpacing:'0.04em' }}>
            Questions ({form.question_ids.length} selected)
          </p>
          <input className="input-3d text-sm" style={{ width:200 }} placeholder="Search bank…" value={search} onChange={e=>setSearch(e.target.value)}/>
        </div>

        <div className="space-y-1" style={{ maxHeight:'36vh', overflowY:'auto' }}>
          {visible.map(q => (
            <label key={q.id} className="flex items-start gap-2 px-2.5 py-2 rounded-lg cursor-pointer"
              style={{ background:'var(--bg-input)' }}>
              <input type="checkbox" className="mt-0.5" checked={form.question_ids.includes(q.id)} onChange={()=>toggle(q.id)}/>
              <span className="min-w-0">
                <span className="block text-[11px]" style={{ color:'var(--text-h)' }}>{q.question_text}</span>
                <span className="flex gap-1.5 mt-1">
                  <Tag colour={TYPE_COLOUR[q.question_type]}>{TYPE_LABEL[q.question_type]}</Tag>
                  <Tag colour={DIFF_COLOUR[q.difficulty]}>{q.difficulty}</Tag>
                </span>
              </span>
            </label>
          ))}
          {visible.length === 0 && <p className="text-[11px]" style={{ color:'var(--text-muted)' }}>No active questions match.</p>}
        </div>

        <div className="flex gap-3 pt-1">
          <button onClick={onClose} className="flex-1 py-2.5 rounded-xl text-sm font-semibold"
            style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Cancel</button>
          <button onClick={save} disabled={saving || !form.name?.trim()}
            className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white"
            style={{ background:'linear-gradient(135deg,#7C3AED,#5b21b6)', opacity:(saving || !form.name?.trim())?0.7:1 }}>
            {saving ? 'Saving…' : 'Save Set'}
          </button>
        </div>
      </div>
    </Modal>
  )
}
