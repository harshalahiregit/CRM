import { useState, useEffect, useMemo } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import {
  ArrowLeft, Plus, Trash2, GripVertical, Save, Rocket, Copy, AlertTriangle,
  Lock, Gauge, Loader2, ShieldAlert,
} from 'lucide-react'
import { complianceApi } from '@/services/complianceApi'
import {
  Q_TYPES, isScorable, TPL_STATUS, tplStatusCfg, isTemplateEditable,
  DEFAULT_THRESHOLDS, riskCfg,
} from '../constants'
import { KIT3D_STYLE, labelStyle, inputStyle, Field, TextInput, SelectInput } from '@/components/ui/kit3d'

/**
 * Template builder — authors the questions AND the scoring rules that decide
 * whether a vendor is safe to work. Admin-only (the route enforces it).
 *
 * The definition freezes once the template is activated, because checklists
 * store answers keyed by question: editing a live template would rewrite what
 * every historic instance was answered against. Past that point this page is a
 * read-only view with a Clone action.
 */
export default function TemplateBuilder() {
  const { id } = useParams()
  const navigate = useNavigate()
  const isNew = id === 'new'

  const [tpl, setTpl]       = useState(null)
  const [loading, setLoad]  = useState(!isNew)
  const [saving, setSaving] = useState(false)
  const [err, setErr]       = useState(null)

  useEffect(() => {
    if (isNew) {
      setTpl({
        name: '', category: 'HSSE', description: '', status: TPL_STATUS.DRAFT,
        thresholds: { ...DEFAULT_THRESHOLDS },
        definition: { sections: [blankSection(1)] },
      })
      return
    }
    complianceApi.templates.get(id)
      .then(t => { setTpl(t); setLoad(false) })
      .catch(() => { setErr('Could not load this template.'); setLoad(false) })
  }, [id, isNew])

  const editable = tpl ? isTemplateEditable(tpl.status) : false
  const sections = tpl?.definition?.sections || []

  // The denominator every band is a percentage of — surfaced live so an author
  // can see what their weights actually add up to before publishing.
  const maxRisk = useMemo(() => sections.reduce((sum, s) =>
    sum + (s.questions || []).reduce((qs, q) => qs + maxRiskFor(q), 0), 0), [sections])

  const patch = (fn) => setTpl(prev => { const next = structuredClone(prev); fn(next); return next })
  const setDef = (fn) => patch(t => { t.definition ||= { sections: [] }; fn(t.definition) })

  const save = async ({ thenActivate = false } = {}) => {
    setSaving(true); setErr(null)
    try {
      const body = { name: tpl.name, category: tpl.category, description: tpl.description, definition: tpl.definition, thresholds: tpl.thresholds }
      const saved = isNew
        ? await complianceApi.templates.create(body)
        : await complianceApi.templates.update(id, body)

      if (thenActivate) await complianceApi.templates.activate(saved.id)
      navigate('/app/tpv/compliance?tab=templates')
    } catch (e) {
      // The evaluator's message is specific ("Question 'x': a scored number
      // needs a risk_cap…") — surface it verbatim rather than "Save failed".
      setErr(e?.response?.data?.message || 'Could not save this template.')
      setSaving(false)
    }
  }

  const clone = async () => {
    const copy = await complianceApi.templates.clone(id)
    navigate(`/app/tpv/compliance/templates/${copy.id}`)
  }

  if (loading || !tpl) {
    return <div style={{ padding: 24 }}><style>{KIT3D_STYLE}</style>
      <div className="skeleton" style={{ height: 40, width: 260, borderRadius: 12, background: 'var(--border)' }} /></div>
  }

  const cfg = tplStatusCfg(tpl.status)

  return (
    <div style={{ padding: 24, minHeight: '100vh', background: 'var(--bg-global)' }}>
      <style>{KIT3D_STYLE}</style>

      {/* Header */}
      <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 12, marginBottom: 18, flexWrap: 'wrap' }}>
        <div style={{ display: 'flex', alignItems: 'flex-start', gap: 12 }}>
          <button onClick={() => navigate('/app/tpv/compliance?tab=templates')}
            style={{ width: 34, height: 34, borderRadius: 10, display: 'flex', alignItems: 'center', justifyContent: 'center', cursor: 'pointer', background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-muted)', marginTop: 3 }}>
            <ArrowLeft size={16} />
          </button>
          <div>
            <p className="label-caps" style={{ color: '#a78bfa', margin: 0, fontSize: 11, fontWeight: 800, letterSpacing: '0.08em' }}>
              {isNew ? 'NEW TEMPLATE' : tpl.code}
            </p>
            <h1 style={{ color: 'var(--text-h)', fontSize: 23, fontWeight: 900, margin: '2px 0 0', letterSpacing: '-0.02em' }}>
              {tpl.name || 'Untitled template'}
            </h1>
            <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginTop: 6 }}>
              <span style={{ padding: '3px 9px', borderRadius: 999, background: cfg.bg, color: cfg.color, fontSize: 11, fontWeight: 800 }}>{cfg.label}</span>
              {!editable && (
                <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5, fontSize: 11.5, color: 'var(--text-muted)' }}>
                  <Lock size={11} /> Questions are locked — clone to revise
                </span>
              )}
            </div>
          </div>
        </div>
        <div style={{ display: 'flex', gap: 9 }}>
          {!editable && !isNew && (
            <button onClick={clone} style={btn('#7C3AED', true)}><Copy size={14} /> Clone into a draft</button>
          )}
          {editable && (
            <>
              <button onClick={() => save()} disabled={saving} style={btn()}>
                {saving ? <Loader2 size={14} /> : <Save size={14} />} Save draft
              </button>
              <button onClick={() => save({ thenActivate: true })} disabled={saving} style={btn('#10b981', true)}>
                <Rocket size={14} /> Save &amp; activate
              </button>
            </>
          )}
        </div>
      </div>

      {err && (
        <div style={{ display: 'flex', alignItems: 'flex-start', gap: 9, padding: '12px 14px', borderRadius: 12, marginBottom: 16,
          background: 'rgba(239,68,68,0.1)', border: '1px solid rgba(239,68,68,0.4)' }}>
          <AlertTriangle size={15} style={{ color: '#ef4444', flexShrink: 0, marginTop: 1 }} />
          <span style={{ fontSize: 13, color: 'var(--text-h)', lineHeight: 1.5 }}>{err}</span>
        </div>
      )}

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 320px', gap: 16, alignItems: 'start' }}>
        {/* ── Left: the form ── */}
        <div>
          <div className="pr-glass" style={{ padding: 20, marginBottom: 14 }}>
            <div style={{ display: 'grid', gridTemplateColumns: '2fr 1fr', gap: 14 }}>
              <Field label="Template name">
                <TextInput value={tpl.name} disabled={!editable} onChange={e => patch(t => { t.name = e.target.value })} placeholder="HSSE Site Walk" />
              </Field>
              <Field label="Category">
                <TextInput value={tpl.category || ''} disabled={!editable} onChange={e => patch(t => { t.category = e.target.value })} placeholder="HSSE" />
              </Field>
            </div>
            <Field label="Description" full>
              <TextInput value={tpl.description || ''} disabled={!editable} onChange={e => patch(t => { t.description = e.target.value })} placeholder="What this checklist is for, and when to use it" />
            </Field>
          </div>

          {sections.map((section, si) => (
            <div key={si} className="pr-glass" style={{ padding: 20, marginBottom: 14 }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 14 }}>
                <GripVertical size={15} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />
                <input value={section.title} disabled={!editable}
                  onChange={e => setDef(d => { d.sections[si].title = e.target.value; d.sections[si].key = slug(e.target.value) || `s${si + 1}` })}
                  placeholder="Section title"
                  style={{ ...inputStyle, fontSize: 14.5, fontWeight: 800, background: 'transparent', border: 'none', padding: '4px 0', flex: 1 }} />
                {editable && sections.length > 1 && (
                  <button onClick={() => setDef(d => { d.sections.splice(si, 1) })} style={iconBtn('#ef4444')}><Trash2 size={14} /></button>
                )}
              </div>

              <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
                {(section.questions || []).map((q, qi) => (
                  <QuestionEditor key={qi} q={q} editable={editable}
                    onChange={fn => setDef(d => fn(d.sections[si].questions[qi]))}
                    onRemove={() => setDef(d => { d.sections[si].questions.splice(qi, 1) })}
                    canRemove={(section.questions || []).length > 1} />
                ))}
              </div>

              {editable && (
                <button onClick={() => setDef(d => { d.sections[si].questions.push(blankQuestion(nextKey(d))) })}
                  style={{ ...btn(), marginTop: 12, width: '100%', justifyContent: 'center' }}>
                  <Plus size={14} /> Add question
                </button>
              )}
            </div>
          ))}

          {editable && (
            <button onClick={() => setDef(d => { d.sections.push(blankSection(d.sections.length + 1)) })}
              style={{ ...btn('#7C3AED'), width: '100%', justifyContent: 'center', padding: '13px' }}>
              <Plus size={15} /> Add section
            </button>
          )}
        </div>

        {/* ── Right: scoring ── */}
        <div className="pr-glass" style={{ padding: 20, position: 'sticky', top: 20 }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 4 }}>
            <Gauge size={15} style={{ color: '#a78bfa' }} />
            <h2 style={{ margin: 0, fontSize: 14, fontWeight: 800, color: 'var(--text-h)' }}>Risk scoring</h2>
          </div>
          <p style={{ fontSize: 11.5, color: 'var(--text-muted)', margin: '0 0 14px', lineHeight: 1.5 }}>
            Higher score means more risk, so <strong>Low is the good band</strong>. Bands are a percentage of this template's own maximum.
          </p>

          <div style={{ padding: '11px 13px', borderRadius: 12, background: 'var(--bg-input)', border: '1px solid var(--border)', marginBottom: 14 }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline' }}>
              <span style={{ fontSize: 11.5, color: 'var(--text-muted)', fontWeight: 600 }}>Maximum risk</span>
              <span style={{ fontSize: 19, fontWeight: 900, color: 'var(--text-h)', fontVariantNumeric: 'tabular-nums' }}>{maxRisk}</span>
            </div>
            {maxRisk === 0 && (
              <p style={{ fontSize: 11, color: '#f59e0b', margin: '6px 0 0', lineHeight: 1.45 }}>
                Nothing here earns risk yet, so every submission would come back unscored. Give a Yes/No, choice or number question some weight.
              </p>
            )}
          </div>

          {['moderate', 'high'].map(k => (
            <div key={k} style={{ marginBottom: 12 }}>
              <label style={{ ...labelStyle, color: riskCfg(k === 'moderate' ? 'Moderate' : 'High').color }}>
                {k === 'moderate' ? 'Moderate' : 'High'} starts at
              </label>
              <div style={{ display: 'flex', alignItems: 'center', gap: 9 }}>
                <input type="number" min="0" max="100" disabled={!editable}
                  value={tpl.thresholds?.[k] ?? DEFAULT_THRESHOLDS[k]}
                  onChange={e => patch(t => { t.thresholds = { ...(t.thresholds || DEFAULT_THRESHOLDS), [k]: Number(e.target.value) } })}
                  style={{ ...inputStyle, width: 78, height: 38 }} />
                <span style={{ fontSize: 12, color: 'var(--text-muted)' }}>%</span>
                {/* Percent is abstract while authoring — say what it means in points. */}
                {maxRisk > 0 && (
                  <span style={{ fontSize: 11.5, color: 'var(--text-muted)', fontVariantNumeric: 'tabular-nums' }}>
                    ≈ {((tpl.thresholds?.[k] ?? DEFAULT_THRESHOLDS[k]) / 100 * maxRisk).toFixed(1)} pts
                  </span>
                )}
              </div>
            </div>
          ))}

          <div style={{ display: 'flex', alignItems: 'flex-start', gap: 8, padding: '10px 12px', borderRadius: 11, marginTop: 14,
            background: 'rgba(239,68,68,0.07)', border: '1px solid rgba(239,68,68,0.28)' }}>
            <ShieldAlert size={14} style={{ color: '#ef4444', flexShrink: 0, marginTop: 1 }} />
            <p style={{ fontSize: 11, color: 'var(--text-muted)', margin: 0, lineHeight: 1.5 }}>
              A question marked <strong style={{ color: '#ef4444' }}>critical</strong> forces the whole checklist to <strong>High</strong> when it fails, whatever the arithmetic says.
            </p>
          </div>
        </div>
      </div>
    </div>
  )
}

/* ── Question editor ──────────────────────────────────────────────────────── */
function QuestionEditor({ q, onChange, onRemove, canRemove, editable }) {
  const scorable = isScorable(q.type)

  return (
    <div style={{ padding: 14, borderRadius: 14, background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
      <div style={{ display: 'flex', gap: 10, marginBottom: 10 }}>
        <div style={{ flex: 1, minWidth: 0 }}>
          <input value={q.label} disabled={!editable} onChange={e => onChange(x => { x.label = e.target.value })}
            placeholder="Question the supervisor will see"
            style={{ ...inputStyle, fontSize: 13.5, fontWeight: 700 }} />
        </div>
        <div style={{ width: 138, flexShrink: 0 }}>
          <SelectInput value={q.type} disabled={!editable} options={Q_TYPES} pairs
            onChange={e => onChange(x => { resetForType(x, e.target.value) })} />
        </div>
        {editable && canRemove && <button onClick={onRemove} style={iconBtn('#ef4444')}><Trash2 size={14} /></button>}
      </div>

      {/* Flags */}
      <div style={{ display: 'flex', gap: 7, flexWrap: 'wrap', marginBottom: scorable ? 10 : 0 }}>
        <Toggle on={!!q.required} disabled={!editable} onClick={() => onChange(x => { x.required = !x.required })} label="Required" />
        <Toggle on={!!q.allow_na} disabled={!editable} onClick={() => onChange(x => { x.allow_na = !x.allow_na })} label="Allow N/A" tip="Drops out of the score and the maximum" />
        {scorable && (
          <>
            <Toggle on={!!q.remark_when_risky} disabled={!editable} onClick={() => onChange(x => { x.remark_when_risky = !x.remark_when_risky })} label="Remark if risky" />
            {q.type === 'boolean' && (
              <Toggle on={!!q.critical} disabled={!editable} onClick={() => onChange(x => { x.critical = !x.critical })} label="Critical" tone="#ef4444" tip="Failing this forces High" />
            )}
          </>
        )}
      </div>

      {/* Type-specific scoring */}
      {q.type === 'boolean' && (
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10 }}>
          <Field label="Risky answer">
            <SelectInput value={String(q.risk_when ?? false)} disabled={!editable}
              options={[['false', 'No is risky'], ['true', 'Yes is risky']]} pairs
              onChange={e => onChange(x => { x.risk_when = e.target.value === 'true' })} />
          </Field>
          <Field label="Risk points">
            <TextInput type="number" min="0" value={q.weight ?? 1} disabled={!editable}
              onChange={e => onChange(x => { x.weight = Number(e.target.value) })} />
          </Field>
        </div>
      )}

      {q.type === 'choice' && (
        <div>
          <label style={labelStyle}>Options</label>
          <div style={{ display: 'flex', flexDirection: 'column', gap: 7 }}>
            {(q.options || []).map((o, oi) => (
              <div key={oi} style={{ display: 'flex', gap: 7, alignItems: 'center' }}>
                <input value={o.label ?? ''} disabled={!editable} placeholder="Label"
                  onChange={e => onChange(x => { x.options[oi].label = e.target.value; x.options[oi].value = slug(e.target.value) || `o${oi + 1}` })}
                  style={{ ...inputStyle, flex: 1, height: 36 }} />
                <input type="number" min="0" value={o.risk ?? 0} disabled={!editable} title="Risk points"
                  onChange={e => onChange(x => { x.options[oi].risk = Number(e.target.value) })}
                  style={{ ...inputStyle, width: 72, height: 36 }} />
                <Toggle on={!!o.critical} disabled={!editable} tone="#ef4444" label="Critical"
                  onClick={() => onChange(x => { x.options[oi].critical = !x.options[oi].critical })} />
                {editable && (q.options || []).length > 1 && (
                  <button onClick={() => onChange(x => { x.options.splice(oi, 1) })} style={iconBtn('#ef4444')}><Trash2 size={12} /></button>
                )}
              </div>
            ))}
          </div>
          {editable && (
            <button onClick={() => onChange(x => { x.options = [...(x.options || []), { value: '', label: '', risk: 0 }] })}
              style={{ ...btn(), marginTop: 8, height: 32, fontSize: 12 }}><Plus size={12} /> Add option</button>
          )}
        </div>
      )}

      {q.type === 'number' && (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4,1fr)', gap: 9 }}>
          <Field label="Min"><TextInput type="number" value={q.min ?? ''} disabled={!editable} onChange={e => onChange(x => { x.min = num(e.target.value) })} /></Field>
          <Field label="Max"><TextInput type="number" value={q.max ?? ''} disabled={!editable} onChange={e => onChange(x => { x.max = num(e.target.value) })} /></Field>
          <Field label="Risk / unit"><TextInput type="number" min="0" value={q.risk_per_unit ?? 0} disabled={!editable} onChange={e => onChange(x => { x.risk_per_unit = Number(e.target.value) })} /></Field>
          {/* Without a cap (or a max) the template has no maximum and no bands —
              the server refuses to save it, so say so before they hit Save. */}
          <Field label="Risk cap"><TextInput type="number" min="0" value={q.risk_cap ?? ''} disabled={!editable} onChange={e => onChange(x => { x.risk_cap = num(e.target.value) })} /></Field>
          {Number(q.risk_per_unit || 0) > 0 && q.risk_cap == null && q.max == null && (
            <p style={{ gridColumn: '1/-1', fontSize: 11, color: '#f59e0b', margin: 0 }}>
              A scored number needs a risk cap or a max — otherwise this template has no maximum risk and cannot be banded.
            </p>
          )}
        </div>
      )}
    </div>
  )
}

/* ── helpers ──────────────────────────────────────────────────────────────── */
const slug = (s) => (s || '').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '').slice(0, 40)
const num = (v) => (v === '' ? null : Number(v))

const blankSection = (n) => ({ key: `s${n}`, title: `Section ${n}`, questions: [blankQuestion('q1')] })
const blankQuestion = (key) => ({ key, label: '', type: 'boolean', required: true, risk_when: false, weight: 1 })

/** Question keys index the responses map, so they must be unique per template. */
function nextKey(def) {
  const used = new Set((def.sections || []).flatMap(s => (s.questions || []).map(q => q.key)))
  let i = 1
  while (used.has(`q${i}`)) i++

  return `q${i}`
}

/** Switching type must drop the previous type's scoring fields, not leave them
 *  behind where the evaluator would read them. */
function resetForType(q, type) {
  const { key, label, required, allow_na } = q
  Object.keys(q).forEach(k => delete q[k])
  Object.assign(q, { key, label, required, allow_na, type })

  if (type === 'boolean') Object.assign(q, { risk_when: false, weight: 1 })
  if (type === 'choice')  q.options = [{ value: 'yes', label: 'Yes', risk: 0 }, { value: 'no', label: 'No', risk: 2 }]
  if (type === 'number')  Object.assign(q, { min: 0, risk_per_unit: 0 })
}

/** Mirrors ChecklistEvaluator::maxRiskFor — the preview must agree with the server. */
function maxRiskFor(q) {
  switch (q.type) {
    case 'boolean': return Number(q.weight ?? 1)
    case 'choice':  return Math.max(0, ...(q.options || []).map(o => Number(o.risk || 0)))
    case 'number': {
      const per = Number(q.risk_per_unit || 0)
      if (per <= 0) return 0
      if (q.risk_cap != null) return Number(q.risk_cap)

      return q.max != null ? Number(q.max) * per : 0
    }
    default: return 0
  }
}

const btn = (color = null, solid = false) => ({
  display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 14px', borderRadius: 10, cursor: 'pointer',
  fontSize: 13, fontWeight: 700,
  background: solid ? `linear-gradient(145deg, ${color}dd, ${color})` : 'var(--bg-card)',
  border: solid ? 'none' : `1px solid ${color ? `${color}55` : 'var(--border)'}`,
  color: solid ? '#fff' : (color || 'var(--text-muted)'),
  boxShadow: solid ? `0 8px 20px -6px ${color}88` : 'none',
})

const iconBtn = (color) => ({
  width: 30, height: 30, borderRadius: 8, display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0,
  cursor: 'pointer', background: `${color}14`, border: `1px solid ${color}40`, color,
})

const Toggle = ({ on, onClick, label, tone = '#7C3AED', disabled, tip }) => (
  <button onClick={disabled ? undefined : onClick} disabled={disabled} title={tip}
    style={{ padding: '4px 10px', borderRadius: 999, fontSize: 11, fontWeight: 700, cursor: disabled ? 'default' : 'pointer',
      background: on ? `${tone}20` : 'transparent',
      border: `1px solid ${on ? `${tone}66` : 'var(--border)'}`,
      color: on ? tone : 'var(--text-muted)' }}>
    {label}
  </button>
)
