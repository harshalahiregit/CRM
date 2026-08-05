import { useState, useEffect, useCallback } from 'react'
import { useTheme } from '@/context/ThemeContext'
import {
  ClipboardList, Plus, X, Trash2, Send, Ban, BarChart3, Download, EyeOff,
  Search, Tag, Users, ShieldCheck, AlertTriangle, Star, CheckSquare, Type, ToggleLeft,
} from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { HrLoading, HrEmpty } from '@/components/ui/HrState'

const GRAD = 'linear-gradient(135deg,#7C3AED,#5b21b6)'

const STATUS_C = {
  Draft:     { c:'var(--text-muted)', bg:'var(--bg-input)' },
  Scheduled: { c:'#3b82f6', bg:'rgba(59,130,246,0.12)' },
  Active:    { c:'#10b981', bg:'rgba(16,185,129,0.12)' },
  Closed:    { c:'#f87171', bg:'rgba(239,68,68,0.1)' },
  Archived:  { c:'var(--text-muted)', bg:'var(--bg-input)' },
}

const Q_TYPES = [
  { value:'text',            label:'Text',            icon:Type },
  { value:'rating',          label:'Rating',          icon:Star },
  { value:'single_choice',   label:'Multiple Choice', icon:CheckSquare },
  { value:'multiple_choice', label:'Checkbox',        icon:CheckSquare },
  { value:'boolean',         label:'Yes / No',        icon:ToggleLeft },
]

/**
 * Review comment #26 — the Employee Survey module.
 *
 * Anonymity is surfaced everywhere it matters, not buried in a checkbox: the
 * builder warns that it cannot be undone, and the analytics view explains why a
 * small department's answers are withheld rather than just omitting them.
 */
export default function EmployeeSurveys() {
  useTheme()
  const [tab, setTab] = useState('surveys')
  const [toast, setToast] = useState(null)
  const showToast = (msg, type = 'success') => { setToast({ msg, type }); setTimeout(() => setToast(null), 3000) }

  const TABS = [
    { key:'surveys',    label:'Surveys',    icon:ClipboardList },
    { key:'categories', label:'Categories', icon:Tag },
    { key:'dashboard',  label:'Dashboard',  icon:BarChart3 },
  ]

  return (
    <div className="space-y-6 animate-[tiltIn_0.35s_ease_forwards]">
      {toast && (
        <div className="fixed top-5 right-5 z-[9999] px-5 py-3 rounded-2xl text-sm font-semibold text-white shadow-2xl"
          style={{ background: toast.type === 'success' ? 'linear-gradient(135deg,#10b981,#059669)' : 'linear-gradient(135deg,#f87171,#ef4444)' }}>
          {toast.msg}
        </div>
      )}

      <div>
        <p className="label-caps mb-1">HR Records</p>
        <h1 className="font-black flex items-center gap-2" style={{ fontSize:'clamp(1.3rem,2vw,1.7rem)', color:'var(--text-h)', letterSpacing:'-0.02em' }}>
          <ClipboardList size={22} style={{ color:'#a78bfa' }}/> <span className="text-gradient">Employee Surveys</span>
        </h1>
      </div>

      <div className="flex gap-1.5 flex-wrap">
        {TABS.map(t => {
          const active = tab === t.key
          return (
            <button key={t.key} onClick={() => setTab(t.key)}
              className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold transition-all"
              style={{ background: active ? GRAD : 'var(--bg-input)', color: active ? '#fff' : 'var(--text-muted)', border: active ? 'none' : '1px solid var(--border)' }}>
              <t.icon size={15}/> {t.label}
            </button>
          )
        })}
      </div>

      {tab === 'surveys'    && <SurveyList showToast={showToast} />}
      {tab === 'categories' && <Categories showToast={showToast} />}
      {tab === 'dashboard'  && <Dashboard showToast={showToast} />}
    </div>
  )
}

/* ────────────────────────────────────────────────────────────────────── */

function SurveyList({ showToast }) {
  const [rows, setRows] = useState([])
  const [meta, setMeta] = useState({ statuses: [], audiences: [], question_types: [] })
  const [categories, setCategories] = useState([])
  const [masters, setMasters] = useState({ departments: [], designations: [] })
  const [loading, setLoading] = useState(true)
  const [statusF, setStatusF] = useState('All')
  const [search, setSearch] = useState('')
  const [builder, setBuilder] = useState(null)
  const [analyticsFor, setAnalyticsFor] = useState(null)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const [m, list, cats, org] = await Promise.all([
        hrApi.surveys.meta(), hrApi.surveys.list(), hrApi.surveys.categories(), hrApi.organization.options(),
      ])
      setMeta(m); setRows(list); setCategories(cats)
      setMasters({ departments: org?.departments || [], designations: org?.designations || [] })
    } catch (e) { showToast(e?.message || 'Could not load surveys', 'error') }
    finally { setLoading(false) }
  }, [showToast])

  useEffect(() => { load() }, [load])

  const act = async (fn, msg) => {
    try { await fn(); showToast(msg); load() }
    catch (e) { showToast(e?.response?.data?.message || 'Action failed', 'error') }
  }

  const visible = rows.filter(r =>
    (statusF === 'All' || r.status === statusF) &&
    (!search || r.title.toLowerCase().includes(search.toLowerCase()))
  )

  if (loading) return <HrLoading label="Loading surveys…" />

  return (
    <div className="space-y-4">
      <div className="card-3d" style={{ padding:'14px 16px' }}>
        <div className="flex gap-3 flex-wrap items-end">
          <div className="relative flex-1 min-w-[200px]">
            <label className="label">Search</label>
            <Search size={14} className="absolute left-3 top-[34px]" style={{ color:'var(--text-muted)' }}/>
            <input className="input-3d pl-9 text-sm" placeholder="Survey title…" value={search} onChange={e=>setSearch(e.target.value)}/>
          </div>
          <div className="min-w-[150px]">
            <label className="label">Status</label>
            <select className="input-3d text-sm" value={statusF} onChange={e=>setStatusF(e.target.value)}>
              {['All', ...meta.statuses].map(s => <option key={s}>{s}</option>)}
            </select>
          </div>
          <button onClick={()=>setBuilder({ id:null, form:{ title:'', category_id:'', description:'', is_anonymous:false,
            audience:'All', department_id:'', designation_id:'', starts_at:'', ends_at:'', questions:[] } })}
            className="flex items-center gap-1.5 px-4 py-2.5 rounded-xl text-sm font-bold text-white ml-auto" style={{ background:GRAD }}>
            <Plus size={15}/> New Survey
          </button>
        </div>
      </div>

      {visible.length === 0
        ? <HrEmpty icon={ClipboardList} title="No surveys yet" subtitle="Build a survey, publish it, and responses appear here." />
        : (
          <div className="space-y-2">
            {visible.map(s => {
              const sc = STATUS_C[s.status] || {}
              return (
                <div key={s.id} className="card-3d flex items-start gap-3 flex-wrap" style={{ padding:'14px 16px' }}>
                  <div className="flex-1 min-w-[240px]">
                    <div className="flex items-center gap-2 flex-wrap">
                      <span className="text-sm font-black" style={{ color:'var(--text-h)' }}>{s.title}</span>
                      <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:sc.bg, color:sc.c }}>{s.status}</span>
                      {s.is_anonymous && (
                        <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg inline-flex items-center gap-1"
                          style={{ background:'rgba(124,58,237,0.12)', color:'#a78bfa' }}><EyeOff size={9}/> ANONYMOUS</span>
                      )}
                      {s.category_name && <span className="text-[10px]" style={{ color:'var(--text-muted)' }}>· {s.category_name}</span>}
                    </div>
                    <p className="text-[11px] mt-1" style={{ color:'var(--text-muted)' }}>
                      {s.question_count ?? 0} question(s) · {s.response_count ?? 0} response(s) · audience {s.audience}
                      {s.starts_at && <> · from {new Date(s.starts_at).toLocaleDateString('en-IN')}</>}
                      {s.ends_at && <> to {new Date(s.ends_at).toLocaleDateString('en-IN')}</>}
                    </p>
                  </div>

                  <div className="flex gap-1.5 flex-wrap">
                    {s.status === 'Draft' && (
                      <button onClick={()=>act(()=>hrApi.surveys.publish(s.id), 'Published')}
                        className="text-[11px] font-bold px-3 py-1.5 rounded-lg inline-flex items-center gap-1 text-white" style={{ background:GRAD }}>
                        <Send size={12}/> Publish
                      </button>
                    )}
                    {['Active', 'Scheduled'].includes(s.status) && (
                      <button onClick={()=>act(()=>hrApi.surveys.close(s.id), 'Closed')}
                        className="text-[11px] font-bold px-3 py-1.5 rounded-lg inline-flex items-center gap-1" style={{ background:'rgba(239,68,68,0.1)', color:'#f87171' }}>
                        <Ban size={12}/> Close
                      </button>
                    )}
                    <button onClick={async ()=>setBuilder({ id:s.id, form:{ ...(await hrApi.surveys.get(s.id)) } })}
                      className="text-[11px] font-bold px-3 py-1.5 rounded-lg" style={{ background:'var(--bg-input)', color:'var(--text-muted)' }}>Edit</button>
                    <button onClick={()=>setAnalyticsFor(s)}
                      className="text-[11px] font-bold px-3 py-1.5 rounded-lg inline-flex items-center gap-1" style={{ background:'rgba(124,58,237,0.1)', color:'#a78bfa' }}>
                      <BarChart3 size={12}/> Analytics
                    </button>
                    {s.status === 'Draft' && (
                      <button onClick={()=>{ if (window.confirm(`Delete "${s.title}"?`)) act(()=>hrApi.surveys.remove(s.id), 'Deleted') }}
                        className="p-1.5 rounded-lg" style={{ background:'rgba(239,68,68,0.1)' }}><Trash2 size={13} style={{ color:'#f87171' }}/></button>
                    )}
                  </div>
                </div>
              )
            })}
          </div>
        )}

      {builder && <SurveyBuilder state={builder} setState={setBuilder} meta={meta} categories={categories}
        masters={masters} showToast={showToast} onDone={()=>{ setBuilder(null); load() }} />}
      {analyticsFor && <Analytics survey={analyticsFor} onClose={()=>setAnalyticsFor(null)} showToast={showToast} />}
    </div>
  )
}

/* ── Question builder ─────────────────────────────────────────────────── */

function SurveyBuilder({ state, setState, meta, categories, masters, showToast, onDone }) {
  const { form } = state
  const [saving, setSaving] = useState(false)
  const set = (patch) => setState(s => ({ ...s, form: { ...s.form, ...patch } }))
  const questions = form.questions || []
  const locked = (form.response_count ?? 0) > 0

  const setQ = (i, patch) => set({ questions: questions.map((q, j) => j === i ? { ...q, ...patch } : q) })

  const save = async () => {
    setSaving(true)
    try {
      await hrApi.surveys.save(state.id, {
        title: form.title, category_id: form.category_id || null, description: form.description || null,
        is_anonymous: !!form.is_anonymous, audience: form.audience,
        department_id: form.audience === 'Department' ? Number(form.department_id) || null : null,
        designation_id: form.audience === 'Designation' ? Number(form.designation_id) || null : null,
        starts_at: form.starts_at || null, ends_at: form.ends_at || null,
        questions: questions.map(q => ({
          question_text: q.question_text,
          question_type: q.question_type,
          options: ['single_choice', 'multiple_choice'].includes(q.question_type) ? (q.options || []) : null,
          rating_max: q.question_type === 'rating' ? Number(q.rating_max) || 5 : null,
          is_required: !!q.is_required,
        })),
      })
      showToast('Survey saved'); onDone()
    } catch (e) { showToast(e?.response?.data?.message || 'Could not save', 'error') }
    finally { setSaving(false) }
  }

  return (
    <div className="modal-backdrop" onClick={()=>setState(null)}>
      <div className="modal-box" onClick={e=>e.stopPropagation()} style={{ maxWidth:760, width:'95%', maxHeight:'92vh', overflowY:'auto' }}>
        <div className="flex items-center justify-between mb-4">
          <h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>{state.id ? 'Edit' : 'New'} Survey</h2>
          <button onClick={()=>setState(null)} style={{ color:'var(--text-muted)' }}><X size={18}/></button>
        </div>

        <div className="space-y-3">
          <div><label className="label">Title *</label>
            <input className="input-3d text-sm" value={form.title||''} onChange={e=>set({ title:e.target.value })}/></div>

          <div className="grid grid-cols-2 gap-3">
            <div><label className="label">Category</label>
              <select className="input-3d text-sm" value={form.category_id||''} onChange={e=>set({ category_id:e.target.value })}>
                <option value="">None</option>
                {categories.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
              </select>
            </div>
            <div><label className="label">Audience</label>
              <select className="input-3d text-sm" value={form.audience||'All'} onChange={e=>set({ audience:e.target.value })}>
                {meta.audiences.map(a => <option key={a}>{a}</option>)}
              </select>
            </div>
            {form.audience === 'Department' && (
              <div><label className="label">Department *</label>
                <select className="input-3d text-sm" value={form.department_id||''} onChange={e=>set({ department_id:e.target.value })}>
                  <option value="">Choose…</option>
                  {masters.departments.map(d => <option key={d.id} value={d.id}>{d.name}</option>)}
                </select>
              </div>
            )}
            {form.audience === 'Designation' && (
              <div><label className="label">Designation *</label>
                <select className="input-3d text-sm" value={form.designation_id||''} onChange={e=>set({ designation_id:e.target.value })}>
                  <option value="">Choose…</option>
                  {masters.designations.map(d => <option key={d.id} value={d.id}>{d.name}</option>)}
                </select>
              </div>
            )}
            <div><label className="label">Opens</label>
              <input type="datetime-local" className="input-3d text-sm" value={(form.starts_at||'').slice(0,16)} onChange={e=>set({ starts_at:e.target.value })}/>
              <p className="text-[10px] mt-1" style={{ color:'var(--text-muted)' }}>A future date schedules it instead of opening it.</p>
            </div>
            <div><label className="label">Closes</label>
              <input type="datetime-local" className="input-3d text-sm" value={(form.ends_at||'').slice(0,16)} onChange={e=>set({ ends_at:e.target.value })}/></div>
          </div>

          {/* Anonymity — stated plainly, because it cannot be undone. */}
          <div className="rounded-xl p-3" style={{ background:'var(--bg-input)', border:'1px solid var(--border)' }}>
            <label className="flex items-start gap-2 text-xs font-semibold cursor-pointer" style={{ color:'var(--text-muted)' }}>
              <input type="checkbox" className="mt-0.5" disabled={locked} checked={!!form.is_anonymous}
                onChange={e=>set({ is_anonymous:e.target.checked })}/>
              <span>
                <span className="inline-flex items-center gap-1" style={{ color:'var(--text-h)' }}><ShieldCheck size={12}/> Anonymous survey</span>
                <span className="block text-[10px] font-normal mt-0.5">
                  No employee identity is stored — not a hash, not a token. The system genuinely cannot tell who answered.
                  Department is still recorded for reporting, and small departments are withheld from the breakdown.
                </span>
                {form.is_anonymous && (
                  <span className="block text-[10px] font-normal mt-1" style={{ color:'#fbbf24' }}>
                    One response per employee cannot be enforced on an anonymous survey.
                  </span>
                )}
                {locked && (
                  <span className="block text-[10px] font-normal mt-1" style={{ color:'#fbbf24' }}>
                    Locked — responses have already been collected.
                  </span>
                )}
              </span>
            </label>
          </div>

          {/* Questions */}
          <div>
            <div className="flex items-center justify-between mb-1.5">
              <label className="label" style={{ marginBottom:0 }}>Questions</label>
              <button disabled={locked} onClick={()=>set({ questions:[...questions, { question_text:'', question_type:'text', options:[], rating_max:5, is_required:false }] })}
                className="flex items-center gap-1 px-2 py-1 rounded-lg text-[10px] font-bold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', opacity: locked ? 0.5 : 1 }}>
                <Plus size={11}/> Add question
              </button>
            </div>
            {locked && <p className="text-[11px] mb-2" style={{ color:'#fbbf24' }}>Questions are locked once anyone has responded — editing them would re-interpret answers already given.</p>}

            <div className="space-y-2">
              {questions.map((q, i) => (
                <div key={i} className="rounded-xl p-3" style={{ background:'var(--bg-input)' }}>
                  <div className="flex items-start gap-2">
                    <span className="text-[11px] font-bold pt-2" style={{ color:'var(--text-muted)', width:18 }}>{i+1}</span>
                    <div className="flex-1 space-y-2">
                      <input className="input-3d text-sm" placeholder="Question" disabled={locked}
                        value={q.question_text||''} onChange={e=>setQ(i, { question_text:e.target.value })}/>
                      <div className="flex gap-2 flex-wrap items-center">
                        <select className="input-3d text-xs" style={{ width:160 }} disabled={locked}
                          value={q.question_type} onChange={e=>setQ(i, { question_type:e.target.value })}>
                          {Q_TYPES.map(t => <option key={t.value} value={t.value}>{t.label}</option>)}
                        </select>
                        {q.question_type === 'rating' && (
                          <input type="number" min="2" max="10" className="input-3d text-xs" style={{ width:90 }} disabled={locked}
                            value={q.rating_max||5} onChange={e=>setQ(i, { rating_max:e.target.value })} title="Maximum rating"/>
                        )}
                        <label className="flex items-center gap-1.5 text-[11px] font-semibold cursor-pointer" style={{ color:'var(--text-muted)' }}>
                          <input type="checkbox" disabled={locked} checked={!!q.is_required} onChange={e=>setQ(i, { is_required:e.target.checked })}/> Required
                        </label>
                      </div>
                      {['single_choice','multiple_choice'].includes(q.question_type) && (
                        <ChoiceOptions value={q.options||[]} disabled={locked} onChange={v=>setQ(i, { options:v })}/>
                      )}
                    </div>
                    {!locked && (
                      <button onClick={()=>set({ questions: questions.filter((_, j) => j !== i) })}
                        className="p-1.5 rounded-lg" style={{ background:'rgba(239,68,68,0.1)' }}><Trash2 size={12} style={{ color:'#f87171' }}/></button>
                    )}
                  </div>
                </div>
              ))}
              {questions.length === 0 && <p className="text-[11px]" style={{ color:'var(--text-muted)' }}>No questions yet — a survey cannot be published without at least one.</p>}
            </div>
          </div>
        </div>

        <div className="flex gap-3 pt-5">
          <button onClick={()=>setState(null)} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Cancel</button>
          <button onClick={save} disabled={saving} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:GRAD, opacity:saving?0.7:1 }}>{saving?'Saving…':'Save Survey'}</button>
        </div>
      </div>
    </div>
  )
}

function ChoiceOptions({ value, onChange, disabled }) {
  const [draft, setDraft] = useState('')
  const add = () => { const v = draft.trim(); if (v && !value.includes(v)) onChange([...value, v]); setDraft('') }

  return (
    <div>
      <div className="flex flex-wrap gap-1.5 mb-1.5">
        {value.map(o => (
          <span key={o} className="inline-flex items-center gap-1 px-2 py-0.5 rounded-lg text-[11px] font-semibold"
            style={{ background:'rgba(124,58,237,0.12)', color:'#a78bfa' }}>
            {o}{!disabled && <button onClick={()=>onChange(value.filter(x => x !== o))} style={{ lineHeight:1 }}><X size={10}/></button>}
          </span>
        ))}
        {value.length === 0 && <span className="text-[10px]" style={{ color:'#fbbf24' }}>A choice question needs at least two options.</span>}
      </div>
      {!disabled && (
        <input className="input-3d text-xs" placeholder="Add an option and press Enter" value={draft}
          onChange={e=>setDraft(e.target.value)}
          onKeyDown={e=>{ if (e.key === 'Enter') { e.preventDefault(); add() } }} onBlur={add}/>
      )}
    </div>
  )
}

/* ── Analytics ────────────────────────────────────────────────────────── */

function Analytics({ survey, onClose, showToast }) {
  const [data, setData] = useState(null)

  useEffect(() => {
    hrApi.surveys.analytics(survey.id).then(setData)
      .catch(e => { showToast(e?.response?.data?.message || 'Could not load analytics', 'error'); onClose() })
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [survey.id])

  return (
    <div className="modal-backdrop" onClick={onClose}>
      <div className="modal-box" onClick={e=>e.stopPropagation()} style={{ maxWidth:820, width:'95%', maxHeight:'92vh', overflowY:'auto' }}>
        <div className="flex items-center justify-between mb-1">
          <h2 className="font-black text-lg flex items-center gap-2" style={{ color:'var(--text-h)' }}>
            <BarChart3 size={18} style={{ color:'#a78bfa' }}/> {survey.title}
          </h2>
          <button onClick={onClose} style={{ color:'var(--text-muted)' }}><X size={18}/></button>
        </div>

        {!data ? <HrLoading label="Loading analytics…" /> : (
          <>
            <div className="flex items-center gap-2 flex-wrap mb-4">
              <span className="text-xs" style={{ color:'var(--text-muted)' }}>
                {data.response_count} of {data.eligible_count} eligible
                {data.response_rate !== null && <> · {data.response_rate}% response rate</>}
              </span>
              {data.survey.is_anonymous && (
                <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg inline-flex items-center gap-1"
                  style={{ background:'rgba(124,58,237,0.12)', color:'#a78bfa' }}><EyeOff size={9}/> ANONYMOUS</span>
              )}
              <a href={hrApi.surveys.exportUrl(survey.id)} className="ml-auto text-[11px] font-bold px-3 py-1.5 rounded-lg inline-flex items-center gap-1"
                style={{ background:'var(--bg-input)', color:'var(--text-muted)' }}><Download size={12}/> Export CSV</a>
            </div>

            {/* Per-question */}
            <div className="space-y-3">
              {data.questions.map(q => (
                <div key={q.question_id} className="rounded-xl p-3" style={{ background:'var(--bg-input)' }}>
                  <p className="text-xs font-bold mb-2" style={{ color:'var(--text-h)' }}>{q.question_text}</p>

                  {q.question_type === 'rating' && (
                    <>
                      <p className="text-sm font-black" style={{ color:'#a78bfa' }}>{q.average ?? '—'} <span className="text-[10px] font-normal" style={{ color:'var(--text-muted)' }}>average of {q.answer_count}</span></p>
                      <div className="flex gap-1 mt-2">
                        {Object.entries(q.distribution || {}).map(([score, count]) => (
                          <div key={score} className="text-center" style={{ flex:1 }}>
                            <div style={{ height:Math.max(4, count * 18), background:'#a78bfa', borderRadius:4 }}/>
                            <p className="text-[10px] mt-1" style={{ color:'var(--text-muted)' }}>{score} ({count})</p>
                          </div>
                        ))}
                      </div>
                    </>
                  )}

                  {q.question_type === 'boolean' && (
                    <p className="text-[11px]" style={{ color:'var(--text-muted)' }}>
                      <b style={{ color:'#10b981' }}>{q.yes}</b> yes · <b style={{ color:'#f87171' }}>{q.no}</b> no
                    </p>
                  )}

                  {['single_choice','multiple_choice'].includes(q.question_type) && (
                    <div className="space-y-1">
                      {(q.options || []).map(o => (
                        <div key={o.option} className="flex items-center justify-between gap-3">
                          <span className="text-[11px]" style={{ color:'var(--text-muted)' }}>{o.option}</span>
                          <span className="text-[11px] font-bold" style={{ color:'var(--text-h)' }}>{o.count}</span>
                        </div>
                      ))}
                    </div>
                  )}

                  {q.question_type === 'text' && (
                    <div className="space-y-1">
                      {(q.responses || []).slice(0, 20).map((r, i) => (
                        <p key={i} className="text-[11px] px-2 py-1 rounded" style={{ background:'var(--bg-card)', color:'var(--text-muted)' }}>{r}</p>
                      ))}
                      {(q.responses || []).length === 0 && <p className="text-[11px]" style={{ color:'var(--text-muted)' }}>No answers.</p>}
                    </div>
                  )}
                </div>
              ))}
            </div>

            {/* Departments — with the suppression reason shown, not hidden. */}
            <div className="mt-4">
              <p className="text-[10px] font-bold uppercase mb-1.5" style={{ color:'var(--text-muted)', letterSpacing:'0.04em' }}>
                <Users size={11} className="inline mr-1"/> By department
              </p>
              {data.departments.map(d => (
                <div key={d.department} className="flex items-center justify-between gap-3 py-1" style={{ borderBottom:'1px solid var(--border)' }}>
                  <span className="text-[11px]" style={{ color:'var(--text-muted)' }}>{d.department}</span>
                  <span className="text-[11px] font-semibold flex items-center gap-1.5" style={{ color: d.suppressed ? '#fbbf24' : 'var(--text-h)' }}>
                    {d.response_count}
                    {d.suppressed && <span title={d.reason}><AlertTriangle size={11}/></span>}
                  </span>
                </div>
              ))}
              {data.departments.some(d => d.suppressed) && (
                <p className="text-[10px] mt-1.5" style={{ color:'#fbbf24' }}>
                  Groups marked with a warning are withheld to protect anonymity — too few responses to publish safely.
                </p>
              )}
            </div>
          </>
        )}
      </div>
    </div>
  )
}

/* ── Categories + dashboard ───────────────────────────────────────────── */

function Categories({ showToast }) {
  const [rows, setRows] = useState([])
  const [loading, setLoading] = useState(true)
  const [modal, setModal] = useState(null)

  const load = useCallback(() => {
    setLoading(true)
    hrApi.surveys.categories().then(setRows).catch(() => showToast('Could not load categories', 'error')).finally(() => setLoading(false))
  }, [showToast])

  useEffect(() => { load() }, [load])

  const save = async () => {
    try { await hrApi.surveys.saveCategory(modal.id, modal.form); showToast('Saved'); setModal(null); load() }
    catch (e) { showToast(e?.response?.data?.message || 'Could not save', 'error') }
  }

  if (loading) return <HrLoading label="Loading categories…" />

  return (
    <div className="space-y-4">
      <div className="flex justify-end">
        <button onClick={()=>setModal({ id:null, form:{ name:'', code:'', colour:'#7C3AED', description:'', is_active:true } })}
          className="flex items-center gap-1.5 px-4 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:GRAD }}>
          <Plus size={15}/> Add Category
        </button>
      </div>

      {rows.length === 0
        ? <HrEmpty icon={Tag} title="No survey categories" subtitle="Group surveys — engagement, exit, pulse, training feedback." />
        : (
          <div className="grid md:grid-cols-2 gap-3">
            {rows.map(c => (
              <div key={c.id} className="card-3d flex items-center gap-3" style={{ padding:'13px 16px', opacity: c.is_active ? 1 : 0.55 }}>
                <div className="rounded-lg" style={{ width:10, height:28, background:c.colour || '#7C3AED' }}/>
                <div className="flex-1">
                  <p className="text-sm font-black" style={{ color:'var(--text-h)' }}>{c.name}</p>
                  <p className="text-[11px]" style={{ color:'var(--text-muted)' }}>{c.survey_count} survey(s)</p>
                </div>
                <button onClick={()=>setModal({ id:c.id, form:{ ...c } })}
                  className="text-[11px] font-bold px-3 py-1.5 rounded-lg" style={{ background:'var(--bg-input)', color:'var(--text-muted)' }}>Edit</button>
                <button onClick={async ()=>{
                  if (!window.confirm(`Delete "${c.name}"?`)) return
                  try { await hrApi.surveys.removeCategory(c.id); showToast('Deleted'); load() }
                  catch (e) { showToast(e?.response?.data?.message || 'Could not delete', 'error') }
                }} className="p-2 rounded-lg" style={{ background:'rgba(239,68,68,0.1)' }}><Trash2 size={13} style={{ color:'#f87171' }}/></button>
              </div>
            ))}
          </div>
        )}

      {modal && (
        <div className="modal-backdrop" onClick={()=>setModal(null)}>
          <div className="modal-box max-w-md" onClick={e=>e.stopPropagation()}>
            <div className="flex items-center justify-between mb-4">
              <h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>{modal.id ? 'Edit' : 'Add'} Category</h2>
              <button onClick={()=>setModal(null)} style={{ color:'var(--text-muted)' }}><X size={18}/></button>
            </div>
            <div className="space-y-3">
              <div><label className="label">Name *</label><input className="input-3d text-sm" value={modal.form.name||''} onChange={e=>setModal(m=>({...m,form:{...m.form,name:e.target.value}}))}/></div>
              <div className="grid grid-cols-2 gap-3">
                <div><label className="label">Code</label><input className="input-3d text-sm" value={modal.form.code||''} onChange={e=>setModal(m=>({...m,form:{...m.form,code:e.target.value}}))}/></div>
                <div><label className="label">Colour</label><input type="color" className="input-3d" style={{ height:38 }} value={modal.form.colour||'#7C3AED'} onChange={e=>setModal(m=>({...m,form:{...m.form,colour:e.target.value}}))}/></div>
              </div>
              <label className="flex items-center gap-2 text-xs font-semibold cursor-pointer" style={{ color:'var(--text-muted)' }}>
                <input type="checkbox" checked={!!modal.form.is_active} onChange={e=>setModal(m=>({...m,form:{...m.form,is_active:e.target.checked}}))}/> Active
              </label>
            </div>
            <div className="flex gap-3 pt-5">
              <button onClick={()=>setModal(null)} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Cancel</button>
              <button onClick={save} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:GRAD }}>Save</button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}

function Dashboard({ showToast }) {
  const [data, setData] = useState(null)

  useEffect(() => {
    hrApi.surveys.dashboard().then(setData).catch(e => showToast(e?.response?.data?.message || 'Could not load', 'error'))
  }, [showToast])

  if (!data) return <HrLoading label="Loading dashboard…" />

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
        {[
          ['Total surveys', data.total_surveys, '#7C3AED'],
          ['Active', data.active_surveys, '#10b981'],
          ['Responses', data.total_responses, '#3b82f6'],
          ['Last 30 days', data.responses_last_30_days, '#a78bfa'],
        ].map(([label, value, colour]) => (
          <div key={label} className="kpi-3d">
            <p className="text-2xl font-black" style={{ color:colour }}>{value}</p>
            <p className="text-xs mt-1" style={{ color:'var(--text-muted)' }}>{label}</p>
          </div>
        ))}
      </div>

      <div className="card-3d" style={{ padding:'16px' }}>
        <p className="text-xs font-black mb-2" style={{ color:'var(--text-h)' }}>By status</p>
        {Object.entries(data.by_status || {}).map(([status, count]) => (
          <div key={status} className="flex items-center justify-between gap-3 py-1" style={{ borderBottom:'1px solid var(--border)' }}>
            <span className="text-[11px]" style={{ color:(STATUS_C[status]||{}).c || 'var(--text-muted)' }}>{status}</span>
            <span className="text-[11px] font-bold" style={{ color:'var(--text-h)' }}>{count}</span>
          </div>
        ))}
        <p className="text-[10px] mt-2" style={{ color:'var(--text-muted)' }}>
          {data.anonymous_surveys} of {data.total_surveys} are anonymous · {data.active_employees} active employees form the response-rate base.
        </p>
      </div>
    </div>
  )
}
