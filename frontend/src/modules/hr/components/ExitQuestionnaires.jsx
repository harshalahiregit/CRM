import { useState, useEffect, useCallback } from 'react'
import { Plus, X, Trash2, GripVertical, FileQuestion, Star, Copy } from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { HrLoading, HrEmpty } from '@/components/ui/HrState'
import Modal from '@/components/ui/Modal'
// #3 — the shared filter bar. The questionnaire endpoint takes no filter params,
// so this narrows the loaded templates in memory rather than growing a server
// contract for a list that is small by nature.
import ListFilter, { applyListFilter } from '@/components/ui/ListFilter'

/**
 * Review comment #44 — "Exit questionnaire: option to set various types of exit
 * questionnaire and select while processing any exit formality."
 *
 * Templates are authored here and chosen at exit time. A template bound to an
 * exit type is offered automatically for that type; the one marked default covers
 * everything else. A tenant that defines none keeps the original fixed form, so
 * this screen is additive rather than a replacement.
 */

const QUESTION_TYPES = [
  { key:'text',            label:'Free text' },
  { key:'rating',          label:'Rating' },
  { key:'single_choice',   label:'Single choice' },
  { key:'multiple_choice', label:'Multiple choice' },
  { key:'boolean',         label:'Yes / No' },
]
const CHOICE_TYPES = ['single_choice', 'multiple_choice']

export default function ExitQuestionnaires({ showToast }) {
  const [rows, setRows]       = useState([])
  const [types, setTypes]     = useState([])
  const [loading, setLoading] = useState(true)
  const [modal, setModal]     = useState(null)
  // #3 — search by name, plus the two things that actually distinguish one
  // template from another: which exit type it serves, and whether it is live.
  const [search, setSearch]   = useState('')
  const [typeF, setTypeF]     = useState('')
  const [statusF, setStatusF] = useState('All')

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const [list, exitTypes] = await Promise.all([
        hrApi.exit.questionnaires.list(),
        hrApi.exit.types.list().then(r => r?.data ?? r ?? []).catch(() => []),
      ])
      setRows(list); setTypes(Array.isArray(exitTypes) ? exitTypes : [])
    } catch (e) { console.error(e); setRows([]) }
    finally { setLoading(false) }
  }, [])

  useEffect(() => { load() }, [load])

  const shown = applyListFilter(rows, {
    search, fields: ['name', 'exit_type'],
    matchers: [
      // '' is 'any type'; '__default' is the template that covers everything not
      // bound to a specific type, which is a distinct thing from 'unbound'.
      [typeF, (r, v) => (v === '__default' ? !!r.is_default : r.exit_type === v)],
      [statusF, (r, v) => (r.is_active ? 'Active' : 'Inactive') === v],
    ],
  })

  const remove = async (r) => {
    if (!window.confirm(`Remove "${r.name}"? If it has already been used it will be deactivated instead, so completed interviews keep their answers.`)) return
    try { await hrApi.exit.questionnaires.remove(r.id); showToast?.('Questionnaire removed'); load() }
    catch (e) { showToast?.(e?.response?.data?.message || 'Could not remove', 'error') }
  }

  return (
    <div className="space-y-4">
      <div className="flex items-start justify-between gap-3 flex-wrap">
        <div>
          <p className="text-sm font-black flex items-center gap-2" style={{ color:'var(--text-h)' }}>
            <FileQuestion size={16} style={{ color:'#a78bfa' }}/> Exit Questionnaires
          </p>
          <p className="text-[11px] mt-0.5" style={{ color:'var(--text-muted)' }}>
            Bind a template to an exit type and it is offered automatically for that type. The default covers the rest.
          </p>
        </div>
        <button onClick={()=>setModal({ id:null, form:blankForm() })}
          className="flex items-center gap-1.5 px-3.5 py-2 rounded-xl text-sm font-bold text-white"
          style={{ background:'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>
          <Plus size={15}/> New Questionnaire
        </button>
      </div>

      {/* #3 */}
      <ListFilter
        search={search} setSearch={setSearch} placeholder="Questionnaire name or exit type…"
        selects={[
          { key:'type', label:'Exit Type', value:typeF, onChange:setTypeF,
            options:[{ value:'', label:'All types' }, { value:'__default', label:'Default template' },
              ...types.map(t => ({ value:t.name, label:t.name }))] },
          { key:'status', label:'Status', value:statusF, onChange:setStatusF, options:['All','Active','Inactive'] },
        ]}
        onClear={()=>{ setSearch(''); setTypeF(''); setStatusF('All') }}
      />

      {loading ? <HrLoading label="Loading questionnaires…" />
        : shown.length === 0 ? (
          <HrEmpty icon={FileQuestion} title={rows.length ? 'No matching questionnaires' : 'No questionnaires yet'}
            hint={rows.length ? 'Nothing matches these filters.' : 'Until one exists, exit interviews use the original fixed form — nothing is broken by leaving this empty.'} />
        ) : (
          <div className="grid md:grid-cols-2 gap-3">
            {shown.map(r => (
              <div key={r.id} className="card-3d" style={{ padding:'14px 16px', opacity: r.is_active ? 1 : 0.6 }}>
                <div className="flex items-start justify-between gap-2 mb-2">
                  <div className="min-w-0">
                    <p className="text-xs font-black flex items-center gap-1.5" style={{ color:'var(--text-h)' }}>
                      {r.name}
                      {r.is_default && <Star size={11} style={{ color:'#f59e0b' }} title="Default"/>}
                      {!r.is_active && <span className="px-1.5 py-0.5 rounded text-[9px] font-bold" style={{ background:'var(--bg-input)', color:'var(--text-muted)' }}>Inactive</span>}
                    </p>
                    <p className="text-[10px] mt-0.5" style={{ color:'var(--text-muted)' }}>
                      {r.exit_type ? `For ${r.exit_type}` : r.is_default ? 'Default for all exit types' : 'Not bound to an exit type'}
                      {' · '}{r.question_count} question{r.question_count === 1 ? '' : 's'}
                    </p>
                  </div>
                  <div className="flex items-center gap-1.5 flex-shrink-0">
                    <button onClick={()=>setModal({ id:r.id, form:{ ...r } })}
                      className="rounded-lg px-2 py-1 text-[11px] font-bold"
                      style={{ background:'var(--bg-input)', border:'1px solid var(--border)', color:'#a78bfa' }}>Edit</button>
                    <button onClick={()=>setModal({ id:null, form:{ ...r, id:undefined, name:`${r.name} (copy)`, is_default:false,
                      questions:(r.questions||[]).map(q => ({ ...q, id:undefined })) } })}
                      title="Duplicate" className="rounded-lg px-2 py-1"
                      style={{ background:'var(--bg-input)', border:'1px solid var(--border)', color:'var(--text-muted)' }}><Copy size={12}/></button>
                    <button onClick={()=>remove(r)} title="Remove" className="rounded-lg px-2 py-1"
                      style={{ background:'var(--bg-input)', border:'1px solid var(--border)', color:'#f87171' }}><Trash2 size={12}/></button>
                  </div>
                </div>

                {(r.questions || []).slice(0, 4).map(q => (
                  <p key={q.id} className="text-[11px] truncate" style={{ color:'var(--text-muted)' }}>
                    · {q.question_text}
                    {q.is_required && <span style={{ color:'#f87171' }}> *</span>}
                  </p>
                ))}
                {r.question_count > 4 && (
                  <p className="text-[10px] mt-0.5" style={{ color:'var(--text-muted)' }}>+{r.question_count - 4} more</p>
                )}
              </div>
            ))}
          </div>
        )}

      {modal && (
        <Builder modal={modal} types={types} onClose={()=>setModal(null)}
          onSaved={(msg)=>{ setModal(null); showToast?.(msg); load() }}
          onError={(msg)=>showToast?.(msg, 'error')} />
      )}
    </div>
  )
}

const blankForm = () => ({
  name:'', code:'', description:'', exit_type_id:'', is_default:false, is_active:true,
  questions:[{ question_text:'', question_type:'text', is_required:false, options:[], rating_max:5 }],
})

function Builder({ modal, types, onClose, onSaved, onError }) {
  const [form, setForm] = useState({ ...blankForm(), ...modal.form,
    questions: modal.form.questions?.length ? modal.form.questions : blankForm().questions })
  const [saving, setSaving] = useState(false)

  const set = (k, v) => setForm(f => ({ ...f, [k]: v }))
  const setQ = (i, patch) => setForm(f => ({
    ...f, questions: f.questions.map((q, idx) => idx === i ? { ...q, ...patch } : q),
  }))
  const addQ = () => setForm(f => ({ ...f,
    questions: [...f.questions, { question_text:'', question_type:'text', is_required:false, options:[], rating_max:5 }] }))
  const removeQ = (i) => setForm(f => ({ ...f, questions: f.questions.filter((_, idx) => idx !== i) }))
  const move = (i, dir) => setForm(f => {
    const next = [...f.questions]
    const j = i + dir
    if (j < 0 || j >= next.length) return f
    ;[next[i], next[j]] = [next[j], next[i]]
    return { ...f, questions: next }
  })

  const save = async () => {
    setSaving(true)
    try {
      await hrApi.exit.questionnaires.save(modal.id, {
        ...form,
        exit_type_id: form.exit_type_id || null,
        questions: form.questions
          .filter(q => q.question_text?.trim())
          .map((q, i) => ({ ...q, sort_order: i })),
      })
      onSaved(modal.id ? 'Questionnaire updated' : 'Questionnaire created')
    } catch (e) { onError(e?.response?.data?.message || 'Could not save') }
    finally { setSaving(false) }
  }

  return (
    <Modal open onClose={onClose} className="max-w-2xl" style={{ maxHeight:'92vh', overflowY:'auto' }}>
      <div className="flex items-center justify-between mb-4">
        <h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>
          {modal.id ? 'Edit Questionnaire' : 'New Questionnaire'}
        </h2>
        <button onClick={onClose} style={{ color:'var(--text-muted)' }}><X size={18}/></button>
      </div>

      <div className="space-y-3">
        <div className="grid grid-cols-2 gap-3">
          <div><label className="label">Name *</label>
            <input className="input-3d text-sm" value={form.name} onChange={e=>set('name', e.target.value)} placeholder="e.g. Voluntary Resignation"/></div>
          <div><label className="label">Code</label>
            <input className="input-3d text-sm" value={form.code || ''} onChange={e=>set('code', e.target.value)} placeholder="Optional short code"/></div>
        </div>

        <div className="grid grid-cols-2 gap-3">
          <div>
            <label className="label">Exit Type</label>
            <select className="input-3d text-sm" value={form.exit_type_id || ''} onChange={e=>set('exit_type_id', e.target.value)}>
              <option value="">Not bound to a type</option>
              {types.map(t => <option key={t.id} value={t.id}>{t.name}</option>)}
            </select>
            <p className="text-[10px] mt-1" style={{ color:'var(--text-muted)' }}>Offered automatically for this exit type.</p>
          </div>
          <div className="flex flex-col justify-center gap-2 pt-4">
            <label className="flex items-center gap-2 text-xs font-semibold cursor-pointer" style={{ color:'var(--text-muted)' }}>
              <input type="checkbox" checked={!!form.is_default} onChange={e=>set('is_default', e.target.checked)}/>
              Use as the default
            </label>
            <label className="flex items-center gap-2 text-xs font-semibold cursor-pointer" style={{ color:'var(--text-muted)' }}>
              <input type="checkbox" checked={form.is_active !== false} onChange={e=>set('is_active', e.target.checked)}/>
              Active
            </label>
          </div>
        </div>

        <div><label className="label">Description</label>
          <textarea className="input-3d text-sm" rows={2} value={form.description || ''} onChange={e=>set('description', e.target.value)}/></div>

        <div className="pt-1" style={{ borderTop:'1px solid var(--border)' }}/>

        <div className="flex items-center justify-between">
          <p className="text-[11px] font-bold uppercase" style={{ color:'var(--text-muted)', letterSpacing:'0.04em' }}>
            Questions ({form.questions.length})
          </p>
          <button onClick={addQ} className="text-[11px] font-bold flex items-center gap-1" style={{ color:'#a78bfa' }}>
            <Plus size={12}/> Add question
          </button>
        </div>

        <div className="space-y-2">
          {form.questions.map((q, i) => (
            <div key={i} className="rounded-xl p-3" style={{ background:'var(--bg-input)', border:'1px solid var(--border)' }}>
              <div className="flex items-start gap-2">
                <div className="flex flex-col items-center gap-0.5 pt-1.5">
                  <button onClick={()=>move(i, -1)} disabled={i === 0} className="text-[9px]"
                    style={{ color:'var(--text-muted)', opacity: i === 0 ? 0.3 : 1 }}>▲</button>
                  <GripVertical size={11} style={{ color:'var(--text-muted)' }}/>
                  <button onClick={()=>move(i, 1)} disabled={i === form.questions.length - 1} className="text-[9px]"
                    style={{ color:'var(--text-muted)', opacity: i === form.questions.length - 1 ? 0.3 : 1 }}>▼</button>
                </div>

                <div className="flex-1 space-y-2 min-w-0">
                  <input className="input-3d text-sm" placeholder={`Question ${i + 1}`}
                    value={q.question_text} onChange={e=>setQ(i, { question_text: e.target.value })}/>

                  <div className="flex items-center gap-2 flex-wrap">
                    <select className="input-3d text-sm" style={{ width:'auto' }}
                      value={q.question_type} onChange={e=>setQ(i, { question_type: e.target.value })}>
                      {QUESTION_TYPES.map(t => <option key={t.key} value={t.key}>{t.label}</option>)}
                    </select>

                    {q.question_type === 'rating' && (
                      <input type="number" min="2" max="10" className="input-3d text-sm" style={{ width:90 }}
                        value={q.rating_max || 5} onChange={e=>setQ(i, { rating_max: Number(e.target.value) })} title="Maximum rating"/>
                    )}

                    <label className="flex items-center gap-1.5 text-[11px] font-semibold cursor-pointer" style={{ color:'var(--text-muted)' }}>
                      <input type="checkbox" checked={!!q.is_required} onChange={e=>setQ(i, { is_required: e.target.checked })}/>
                      Required
                    </label>

                    <button onClick={()=>removeQ(i)} className="ml-auto rounded-lg px-2 py-1"
                      style={{ background:'var(--bg-card)', border:'1px solid var(--border)', color:'#f87171' }}>
                      <Trash2 size={12}/>
                    </button>
                  </div>

                  {CHOICE_TYPES.includes(q.question_type) && (
                    <div>
                      <input className="input-3d text-sm" placeholder="Options, comma separated — e.g. Pay, Manager, Growth"
                        value={(q.options || []).join(', ')}
                        onChange={e=>setQ(i, { options: e.target.value.split(',').map(o => o.trim()).filter(Boolean) })}/>
                      {/* The API refuses a choice question with no options, so say
                          so here rather than letting the save fail. */}
                      {(q.options || []).length === 0 && (
                        <p className="text-[10px] mt-1" style={{ color:'#f87171' }}>
                          A choice question needs at least one option.
                        </p>
                      )}
                    </div>
                  )}
                </div>
              </div>
            </div>
          ))}
        </div>

        <div className="flex gap-3 pt-2">
          <button onClick={onClose} className="flex-1 py-2.5 rounded-xl text-sm font-semibold"
            style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Cancel</button>
          <button onClick={save} disabled={saving || !form.name?.trim()}
            className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white"
            style={{ background:'linear-gradient(135deg,#7C3AED,#5b21b6)', opacity:(saving || !form.name?.trim())?0.7:1 }}>
            {saving ? 'Saving…' : modal.id ? 'Save Changes' : 'Create Questionnaire'}
          </button>
        </div>
      </div>
    </Modal>
  )
}
