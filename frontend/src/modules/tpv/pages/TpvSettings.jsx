import { useState, useEffect, useMemo } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import {
  SlidersHorizontal, ShieldAlert, Gauge, ShieldCheck, Landmark, ListChecks, ScanLine,
  HardHat, CalendarDays, Plus, Trash2, RotateCcw, Save, ExternalLink, CheckCircle2, TrendingUp,
} from 'lucide-react'
import { tpvApi } from '@/services/tpvApi'

/**
 * TPV System Configuration (Sangoe TPV §34).
 *
 * The governance engines used to read fixed config files / PHP constants; this
 * screen makes each of them tenant-editable. Every tab edits ONE settings group
 * and saves it as an override document — leaving a group untouched keeps the
 * shipped defaults. "Reset" deletes the override and reverts to those defaults.
 */

const ACCENT = '#f59e0b'

const TABS = [
  { key: 'strike_rules',      label: 'Strike Rules',      icon: ShieldAlert },
  { key: 'vpi',               label: 'Performance (VPI)', icon: Gauge },
  { key: 'approval_workflow', label: 'Approval Workflow', icon: ShieldCheck },
  { key: 'authority_matrix',  label: 'Authority Matrix',  icon: Landmark },
  { key: 'approval_types',    label: 'Approval Types',    icon: ListChecks },
  { key: 'gate',              label: 'Gate (PPE)',        icon: ScanLine },
  { key: 'violation_ladder',  label: 'Violation Ladder',  icon: TrendingUp },
]

export default function TpvSettings() {
  const qc = useQueryClient()
  const [tab, setTab] = useState('strike_rules')
  const { data: bundle, isLoading } = useQuery({ queryKey: ['tpv-settings'], queryFn: tpvApi.settings.get, staleTime: 0 })

  const reload = () => qc.invalidateQueries({ queryKey: ['tpv-settings'] })

  return (
    <div className="animate-fade-in">
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 4 }}>
        <SlidersHorizontal size={19} style={{ color: ACCENT }} />
        <h1 style={{ margin: 0, fontSize: 20, fontWeight: 800, color: 'var(--text-h)' }}>System Configuration</h1>
      </div>
      <p style={{ margin: '0 0 18px', fontSize: 12.5, color: 'var(--text-muted)' }}>
        Tune the governance engines for your organisation. Anything you don’t change keeps the shipped defaults; “Reset” reverts a section to those defaults.
      </p>

      <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6, marginBottom: 18 }}>
        {TABS.map(t => {
          const active = tab === t.key
          const customized = bundle?.[t.key]?.custom != null
          return (
            <button key={t.key} onClick={() => setTab(t.key)}
              className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl"
              style={{
                background: active ? ACCENT : 'var(--bg-card)',
                color: active ? '#fff' : 'var(--text-body)',
                border: `1px solid ${active ? ACCENT : 'var(--border)'}`, cursor: 'pointer',
              }}>
              <t.icon size={13} /> {t.label}
              {customized && <span title="Customised" style={{ width: 6, height: 6, borderRadius: '50%', background: active ? '#fff' : ACCENT }} />}
            </button>
          )
        })}
      </div>

      {isLoading ? (
        <p className="text-sm p-8 text-center" style={{ color: 'var(--text-muted)' }}>Loading settings…</p>
      ) : (
        <div key={tab}>
          {tab === 'strike_rules'      && <StrikeEditor    grp={bundle.strike_rules}      onSaved={reload} />}
          {tab === 'vpi'               && <VpiEditor       grp={bundle.vpi}               onSaved={reload} />}
          {tab === 'approval_workflow' && <WorkflowEditor  grp={bundle.approval_workflow} onSaved={reload} />}
          {tab === 'authority_matrix'  && <AuthorityEditor grp={bundle.authority_matrix}  onSaved={reload} />}
          {tab === 'approval_types'    && <TypesEditor     grp={bundle.approval_types}    onSaved={reload} />}
          {tab === 'gate'              && <GateEditor      grp={bundle.gate}              onSaved={reload} />}
          {tab === 'violation_ladder'  && <ViolationLadderEditor grp={bundle.violation_ladder} onSaved={reload} />}
        </div>
      )}

      <RelatedEditors />
    </div>
  )
}

/* ── Shared bits ─────────────────────────────────────────────────────────── */

function useGroupSave(group, onSaved) {
  const [err, setErr] = useState('')
  const [ok, setOk] = useState(false)
  const flash = () => { setOk(true); setTimeout(() => setOk(false), 2200) }
  const onError = (e) => setErr(e?.response?.data?.message || 'That change was rejected.')
  const save = useMutation({ mutationFn: (payload) => tpvApi.settings.update(group, payload), onSuccess: () => { setErr(''); flash(); onSaved?.() }, onError })
  const reset = useMutation({ mutationFn: () => tpvApi.settings.reset(group), onSuccess: () => { setErr(''); flash(); onSaved?.() }, onError })
  return { save, reset, err, ok }
}

function Card({ title, hint, children }) {
  return (
    <div style={{ padding: 20, borderRadius: 14, background: 'var(--bg-card)', border: '1px solid var(--border)', marginBottom: 14 }}>
      {title && <p style={{ margin: 0, fontSize: 14, fontWeight: 800, color: 'var(--text-h)' }}>{title}</p>}
      {hint && <p style={{ margin: '3px 0 14px', fontSize: 11.5, color: 'var(--text-muted)', lineHeight: 1.5 }}>{hint}</p>}
      {children}
    </div>
  )
}

function SaveBar({ ctl, onSave, canSave = true, isCustom }) {
  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginTop: 16 }}>
      <button onClick={onSave} disabled={!canSave || ctl.save.isPending}
        className="flex items-center gap-1.5 text-xs font-bold px-4 py-2 rounded-xl"
        style={{ background: canSave ? ACCENT : 'var(--bg-input)', color: canSave ? '#fff' : 'var(--text-muted)', border: 'none', cursor: canSave ? 'pointer' : 'not-allowed' }}>
        <Save size={13} /> {ctl.save.isPending ? 'Saving…' : 'Save changes'}
      </button>
      <button onClick={() => ctl.reset.mutate()} disabled={!isCustom || ctl.reset.isPending}
        className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl"
        title={isCustom ? 'Revert this section to the shipped defaults' : 'Already on defaults'}
        style={{ background: 'transparent', color: isCustom ? '#d03b3b' : 'var(--text-muted)', border: '1px solid var(--border)', cursor: isCustom ? 'pointer' : 'not-allowed' }}>
        <RotateCcw size={13} /> Reset to defaults
      </button>
      {ctl.ok && <span className="flex items-center gap-1 text-xs font-bold" style={{ color: '#0ca30c' }}><CheckCircle2 size={13} /> Saved</span>}
      {ctl.err && <span className="text-xs" style={{ color: '#d03b3b' }}>{ctl.err}</span>}
    </div>
  )
}

const lbl = { display: 'block', fontSize: 10.5, fontWeight: 700, letterSpacing: '.04em', textTransform: 'uppercase', color: 'var(--text-muted)', marginBottom: 5 }
const inp = { width: '100%', padding: '9px 11px', borderRadius: 9, fontSize: 13, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }

/* ── 1 · Strike rules ────────────────────────────────────────────────────── */

function StrikeEditor({ grp, onSaved }) {
  const ctl = useGroupSave('strike_rules', onSaved)
  const [f, setF] = useState(grp.effective)
  useEffect(() => { setF(grp.effective) }, [grp])

  const valid = f.limit >= 1 && f.warn_at >= 1 && f.warn_at <= f.limit

  return (
    <Card title="Safety strike policy" hint="How many active strikes terminate a worker’s site access, when the gate starts warning, and whether a Critical strike terminates on its own.">
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(180px,1fr))', gap: 14 }}>
        <div>
          <label style={lbl}>Termination limit (active strikes)</label>
          <input type="number" min="1" max="50" value={f.limit} onChange={e => setF({ ...f, limit: Number(e.target.value) })} style={inp} />
        </div>
        <div>
          <label style={lbl}>Warn at (strikes)</label>
          <input type="number" min="1" value={f.warn_at} onChange={e => setF({ ...f, warn_at: Number(e.target.value) })} style={inp} />
          {!valid && <p style={{ margin: '5px 0 0', fontSize: 11, color: '#d03b3b' }}>Warn-at must be between 1 and the limit.</p>}
        </div>
      </div>
      <label className="flex items-center gap-2" style={{ marginTop: 14, cursor: 'pointer', fontSize: 13, color: 'var(--text-h)', fontWeight: 600 }}>
        <input type="checkbox" checked={!!f.critical_terminates_immediately} onChange={e => setF({ ...f, critical_terminates_immediately: e.target.checked })} />
        A Critical strike terminates immediately (without reaching the limit)
      </label>
      <SaveBar ctl={ctl} isCustom={grp.custom != null} canSave={valid}
        onSave={() => ctl.save.mutate({ limit: Number(f.limit), warn_at: Number(f.warn_at), critical_terminates_immediately: !!f.critical_terminates_immediately })} />
    </Card>
  )
}

/* ── 2 · VPI scoring ─────────────────────────────────────────────────────── */

function VpiEditor({ grp, onSaved }) {
  const ctl = useGroupSave('vpi', onSaved)
  const [f, setF] = useState(grp.effective)
  useEffect(() => { setF(grp.effective) }, [grp])

  const weightSum = useMemo(() => Object.values(f.weights || {}).reduce((a, b) => a + Number(b || 0), 0), [f.weights])
  const sumOk = Math.abs(weightSum - 1) <= 0.001

  const setW = (k, v) => setF({ ...f, weights: { ...f.weights, [k]: Number(v) } })
  const setD = (k, v) => setF({ ...f, deductions: { ...f.deductions, [k]: Number(v) } })
  const setB = (k, v) => setF({ ...f, bands: { ...f.bands, [k]: Number(v) } })

  return (
    <>
      <Card title="Dimension weights" hint={`The overall index is a weighted average of these eight dimensions. Weights must sum to 1.00 — currently ${weightSum.toFixed(2)}.`}>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(150px,1fr))', gap: 12 }}>
          {Object.entries(f.weights || {}).map(([k, v]) => (
            <div key={k}>
              <label style={lbl}>{k}</label>
              <input type="number" step="0.01" min="0" max="1" value={v} onChange={e => setW(k, e.target.value)} style={inp} />
            </div>
          ))}
        </div>
        <p style={{ margin: '10px 0 0', fontSize: 12, fontWeight: 700, color: sumOk ? '#0ca30c' : '#d03b3b' }}>
          Sum: {weightSum.toFixed(2)} {sumOk ? '✓' : '— must equal 1.00'}
        </p>
      </Card>

      <Card title="Per-item deductions" hint="Points removed from a dimension (which starts at 100) for each open governance item.">
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(150px,1fr))', gap: 12 }}>
          {Object.entries(f.deductions || {}).map(([k, v]) => (
            <div key={k}>
              <label style={lbl}>{k.replace(/_/g, ' ')}</label>
              <input type="number" min="0" max="100" value={v} onChange={e => setD(k, e.target.value)} style={inp} />
            </div>
          ))}
        </div>
      </Card>

      <Card title="Bands & window" hint="Letter-band thresholds on the overall index (below D = E), and the window for counting an expiring document as a partial hit.">
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(120px,1fr))', gap: 12 }}>
          {['A', 'B', 'C', 'D'].map(k => (
            <div key={k}>
              <label style={lbl}>Band {k} ≥</label>
              <input type="number" min="0" max="100" value={f.bands?.[k] ?? ''} onChange={e => setB(k, e.target.value)} style={inp} />
            </div>
          ))}
          <div>
            <label style={lbl}>Doc expiring window (days)</label>
            <input type="number" min="1" max="365" value={f.doc_expiring_window_days} onChange={e => setF({ ...f, doc_expiring_window_days: Number(e.target.value) })} style={inp} />
          </div>
        </div>
        <SaveBar ctl={ctl} isCustom={grp.custom != null} canSave={sumOk}
          onSave={() => ctl.save.mutate({ weights: f.weights, deductions: f.deductions, bands: f.bands, doc_expiring_window_days: Number(f.doc_expiring_window_days) })} />
      </Card>
    </>
  )
}

/* ── 3 · Approval workflow ───────────────────────────────────────────────── */

function WorkflowEditor({ grp, onSaved }) {
  const ctl = useGroupSave('approval_workflow', onSaved)
  const [f, setF] = useState(grp.effective)
  useEffect(() => { setF(grp.effective) }, [grp])

  const setLevel = (i, key, val) => { const levels = f.levels.map((l, j) => j === i ? { ...l, [key]: val } : l); setF({ ...f, levels }) }
  const addLevel = () => setF({ ...f, levels: [...(f.levels || []), { level: (f.levels?.length || 0) + 1, role: 'staff', label: 'Review' }] })
  const rmLevel = (i) => setF({ ...f, levels: f.levels.filter((_, j) => j !== i).map((l, j) => ({ ...l, level: j + 1 })) })

  return (
    <Card title="Onboarding approval chain" hint="Single = one admin approval (default). Multi-level = an ordered chain; each level must approve before the next unlocks.">
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(180px,1fr))', gap: 14, marginBottom: 6 }}>
        <div>
          <label style={lbl}>Mode</label>
          <select value={f.mode} onChange={e => setF({ ...f, mode: e.target.value })} style={inp}>
            <option value="single">Single (one admin approval)</option>
            <option value="multi_level">Multi-level chain</option>
          </select>
        </div>
        <div>
          <label style={lbl}>SLA per level (hours)</label>
          <input type="number" min="1" max="8760" value={f.sla_hours} onChange={e => setF({ ...f, sla_hours: Number(e.target.value) })} style={inp} />
        </div>
      </div>

      {f.mode === 'multi_level' && (
        <div style={{ marginTop: 10 }}>
          <label style={lbl}>Levels (in order)</label>
          <ul className="space-y-2">
            {(f.levels || []).map((l, i) => (
              <li key={i} className="flex items-center gap-2">
                <span style={{ fontSize: 11, fontWeight: 800, color: 'var(--text-muted)', width: 22 }}>#{l.level}</span>
                <input value={l.role} onChange={e => setLevel(i, 'role', e.target.value)} placeholder="role" style={{ ...inp, flex: '0 0 130px' }} />
                <input value={l.label} onChange={e => setLevel(i, 'label', e.target.value)} placeholder="label" style={{ ...inp, flex: 1 }} />
                <button onClick={() => rmLevel(i)} style={{ padding: 6, background: 'none', border: 'none', cursor: 'pointer', color: '#d03b3b' }}><Trash2 size={13} /></button>
              </li>
            ))}
          </ul>
          <button onClick={addLevel} className="flex items-center gap-1 text-xs font-bold mt-2" style={{ color: ACCENT, background: 'none', border: 'none', cursor: 'pointer' }}>
            <Plus size={13} /> Add level
          </button>
        </div>
      )}

      <SaveBar ctl={ctl} isCustom={grp.custom != null}
        onSave={() => ctl.save.mutate({ mode: f.mode, sla_hours: Number(f.sla_hours), levels: (f.mode === 'multi_level' ? f.levels : (grp.builtins.levels)) })} />
    </Card>
  )
}

/* ── 4 · Authority matrix ────────────────────────────────────────────────── */

function AuthorityEditor({ grp, onSaved }) {
  const ctl = useGroupSave('authority_matrix', onSaved)
  const [f, setF] = useState(grp.effective)
  useEffect(() => { setF(grp.effective) }, [grp])

  const authKeys = Object.keys(f.authorities || {})
  const toggleAuth = (rowIdx, key) => {
    const matrix = f.matrix.map((r, i) => {
      if (i !== rowIdx) return r
      const has = (r.authorities || []).includes(key)
      return { ...r, authorities: has ? r.authorities.filter(a => a !== key) : [...(r.authorities || []), key] }
    })
    setF({ ...f, matrix })
  }
  const setRow = (i, key, val) => setF({ ...f, matrix: f.matrix.map((r, j) => j === i ? { ...r, [key]: val } : r) })
  const addRow = () => setF({ ...f, matrix: [...(f.matrix || []), { action: '', gate: '', authorities: [] }] })
  const rmRow = (i) => setF({ ...f, matrix: f.matrix.filter((_, j) => j !== i) })

  return (
    <Card title="HSSE authority matrix" hint="Who signs off on each governance action. Toggle the accountable authorities per row.">
      <div style={{ overflowX: 'auto' }}>
        <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12.5 }}>
          <thead>
            <tr>
              <th style={th}>Action</th><th style={th}>Gate</th>
              {authKeys.map(k => <th key={k} style={{ ...th, textAlign: 'center' }}>{k}</th>)}
              <th style={th} />
            </tr>
          </thead>
          <tbody>
            {(f.matrix || []).map((r, i) => (
              <tr key={i}>
                <td style={td}><input value={r.action} onChange={e => setRow(i, 'action', e.target.value)} style={{ ...inp, minWidth: 180 }} /></td>
                <td style={td}><input value={r.gate || ''} onChange={e => setRow(i, 'gate', e.target.value)} style={{ ...inp, minWidth: 120 }} /></td>
                {authKeys.map(k => (
                  <td key={k} style={{ ...td, textAlign: 'center' }}>
                    <input type="checkbox" checked={(r.authorities || []).includes(k)} onChange={() => toggleAuth(i, k)} />
                  </td>
                ))}
                <td style={td}><button onClick={() => rmRow(i)} style={{ padding: 4, background: 'none', border: 'none', cursor: 'pointer', color: '#d03b3b' }}><Trash2 size={13} /></button></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <button onClick={addRow} className="flex items-center gap-1 text-xs font-bold mt-3" style={{ color: ACCENT, background: 'none', border: 'none', cursor: 'pointer' }}>
        <Plus size={13} /> Add action
      </button>
      <SaveBar ctl={ctl} isCustom={grp.custom != null}
        onSave={() => ctl.save.mutate({ authorities: f.authorities, matrix: f.matrix })} />
    </Card>
  )
}
const th = { textAlign: 'left', padding: '8px 10px', fontSize: 10, letterSpacing: '.05em', textTransform: 'uppercase', color: 'var(--text-muted)', fontWeight: 700, borderBottom: '1px solid var(--border)' }
const td = { padding: '6px 8px', borderBottom: '1px solid var(--border)' }

/* ── 5 · Approval types ──────────────────────────────────────────────────── */

function TypesEditor({ grp, onSaved }) {
  const ctl = useGroupSave('approval_types', onSaved)
  const [types, setTypes] = useState(grp.effective.types)
  useEffect(() => { setTypes(grp.effective.types) }, [grp])

  const setT = (i, key, val) => setTypes(types.map((t, j) => j === i ? { ...t, [key]: val } : t))
  const add = () => setTypes([...types, { value: '', label: '', is_active: true }])
  const rm = (i) => setTypes(types.filter((_, j) => j !== i))

  return (
    <Card title="Approval types" hint="The catalogue of approval kinds the central register offers. Deactivate one to hide it from the “raise approval” form; add your own with a machine value and a label.">
      <ul className="space-y-2">
        {types.map((t, i) => (
          <li key={i} className="flex items-center gap-2">
            <input value={t.value} onChange={e => setT(i, 'value', e.target.value.replace(/\s+/g, '_').toLowerCase())} placeholder="machine_value" style={{ ...inp, flex: '0 0 190px', fontFamily: 'monospace', fontSize: 12 }} />
            <input value={t.label} onChange={e => setT(i, 'label', e.target.value)} placeholder="Display label" style={{ ...inp, flex: 1 }} />
            <label className="flex items-center gap-1 text-xs" style={{ color: 'var(--text-muted)', whiteSpace: 'nowrap', cursor: 'pointer' }}>
              <input type="checkbox" checked={!!t.is_active} onChange={e => setT(i, 'is_active', e.target.checked)} /> active
            </label>
            <button onClick={() => rm(i)} style={{ padding: 6, background: 'none', border: 'none', cursor: 'pointer', color: '#d03b3b' }}><Trash2 size={13} /></button>
          </li>
        ))}
      </ul>
      <button onClick={add} className="flex items-center gap-1 text-xs font-bold mt-2" style={{ color: ACCENT, background: 'none', border: 'none', cursor: 'pointer' }}>
        <Plus size={13} /> Add type
      </button>
      <SaveBar ctl={ctl} isCustom={grp.custom != null}
        canSave={types.every(t => t.value && t.label)}
        onSave={() => ctl.save.mutate({ types: types.map(t => ({ value: t.value, label: t.label, is_active: !!t.is_active })) })} />
    </Card>
  )
}

/* ── 6 · Gate ────────────────────────────────────────────────────────────── */

function GateEditor({ grp, onSaved }) {
  const ctl = useGroupSave('gate', onSaved)
  const [mode, setMode] = useState(grp.effective.ppe_enforcement)
  useEffect(() => { setMode(grp.effective.ppe_enforcement) }, [grp])

  const OPTS = [
    { v: 'warn', t: 'Warn', d: 'Amber: the guard sees missing PPE, entry not blocked (default).' },
    { v: 'deny', t: 'Deny', d: 'Red: entry refused until the PPE is issued.' },
    { v: 'off',  t: 'Off',  d: 'The gate does not check PPE at all.' },
  ]
  return (
    <Card title="Gate — mandatory PPE enforcement (Rule 5)" hint="How the site gate reacts when a worker is missing mandatory PPE.">
      <div style={{ display: 'grid', gap: 8 }}>
        {OPTS.map(o => (
          <label key={o.v} className="flex items-start gap-2.5" style={{ padding: '11px 13px', borderRadius: 10, cursor: 'pointer', background: mode === o.v ? 'color-mix(in srgb, #f59e0b 10%, transparent)' : 'var(--bg-input)', border: `1px solid ${mode === o.v ? ACCENT : 'var(--border)'}` }}>
            <input type="radio" name="ppe" checked={mode === o.v} onChange={() => setMode(o.v)} style={{ marginTop: 3 }} />
            <span>
              <span style={{ display: 'block', fontSize: 13, fontWeight: 700, color: 'var(--text-h)' }}>{o.t}</span>
              <span style={{ display: 'block', fontSize: 11.5, color: 'var(--text-muted)' }}>{o.d}</span>
            </span>
          </label>
        ))}
      </div>
      <SaveBar ctl={ctl} isCustom={grp.custom != null} onSave={() => ctl.save.mutate({ ppe_enforcement: mode })} />
    </Card>
  )
}

/* ── 7 · Violation escalation ladder (§26, Rule 9) ───────────────────────── */

function ViolationLadderEditor({ grp, onSaved }) {
  const ctl = useGroupSave('violation_ladder', onSaved)
  const [f, setF] = useState(grp.effective)
  useEffect(() => { setF(grp.effective) }, [grp])

  const setPts = (k, v) => setF({ ...f, severity_points: { ...f.severity_points, [k]: Number(v) } })
  const setStep = (i, key, val) => { const steps = f.steps.map((s, j) => j === i ? { ...s, [key]: key === 'points' ? Number(val) : val } : s); setF({ ...f, steps }) }
  const addStep = () => setF({ ...f, steps: [...(f.steps || []), { points: 0, level: 'New_Level' }] })
  const rmStep = (i) => setF({ ...f, steps: f.steps.filter((_, j) => j !== i) })

  const hasZero = (f.steps || []).some(s => Number(s.points) === 0)
  const allNamed = (f.steps || []).every(s => String(s.level || '').trim() !== '')
  const valid = hasZero && allNamed && (f.steps || []).length >= 1

  return (
    <>
      <Card title="Severity points" hint="Points a single violation contributes, by severity. Cumulative OPEN points drive the escalation level below.">
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(150px,1fr))', gap: 12 }}>
          {Object.entries(f.severity_points || {}).map(([k, v]) => (
            <div key={k}>
              <label style={lbl}>{k}</label>
              <input type="number" min="0" max="100" value={v} onChange={e => setPts(k, e.target.value)} style={inp} />
            </div>
          ))}
        </div>
      </Card>

      <Card title="Escalation ladder" hint="Cumulative open-points thresholds → level. The highest threshold a vendor reaches wins; 'Suspension' and 'Blacklist' auto-apply that action (Rule 9). A step at 0 points is required as the baseline.">
        <ul className="space-y-2">
          {(f.steps || []).map((s, i) => (
            <li key={i} className="flex items-center gap-2">
              <span style={{ fontSize: 11, fontWeight: 800, color: 'var(--text-muted)', width: 30 }}>≥</span>
              <input type="number" min="0" value={s.points} onChange={e => setStep(i, 'points', e.target.value)} placeholder="points" style={{ ...inp, flex: '0 0 100px' }} />
              <input value={s.level} onChange={e => setStep(i, 'level', e.target.value)} placeholder="level (e.g. Suspension)" style={{ ...inp, flex: 1 }} />
              <button onClick={() => rmStep(i)} style={{ padding: 6, background: 'none', border: 'none', cursor: 'pointer', color: '#d03b3b' }}><Trash2 size={13} /></button>
            </li>
          ))}
        </ul>
        <button onClick={addStep} className="flex items-center gap-1 text-xs font-bold mt-2" style={{ color: ACCENT, background: 'none', border: 'none', cursor: 'pointer' }}>
          <Plus size={13} /> Add step
        </button>
        {!hasZero && <p style={{ margin: '8px 0 0', fontSize: 11, color: '#d03b3b' }}>Add a step at 0 points — it is the baseline level.</p>}
        {!allNamed && <p style={{ margin: '5px 0 0', fontSize: 11, color: '#d03b3b' }}>Every step needs a level name.</p>}
        <SaveBar ctl={ctl} isCustom={grp.custom != null} canSave={valid}
          onSave={() => ctl.save.mutate({
            severity_points: Object.fromEntries(Object.entries(f.severity_points || {}).map(([k, v]) => [k, Number(v)])),
            steps: (f.steps || []).map(s => ({ points: Number(s.points), level: String(s.level).trim() })),
          })} />
      </Card>
    </>
  )
}

/* ── Links to the two §34 items that already have dedicated editors ───────── */

function RelatedEditors() {
  const items = [
    { icon: HardHat, label: 'PPE Requirement Matrix', hint: 'Role → required PPE (Job/Hazard/Activity).', to: '/app/tpv/ppe/matrix' },
    { icon: CalendarDays, label: 'Meeting Types & Templates', hint: 'Add, rename, hide or delete meeting types and their standard agendas.', to: '/app/tpv/kickoff?view=templates' },
  ]
  return (
    <div style={{ marginTop: 26 }}>
      <p style={{ margin: '0 0 10px', fontSize: 10.5, fontWeight: 700, letterSpacing: '.05em', textTransform: 'uppercase', color: 'var(--text-muted)' }}>
        Also configurable — dedicated editors
      </p>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(260px,1fr))', gap: 12 }}>
        {items.map(i => (
          <Link key={i.to} to={i.to} className="flex items-center gap-3" style={{ padding: '14px 16px', borderRadius: 12, background: 'var(--bg-card)', border: '1px solid var(--border)', textDecoration: 'none' }}>
            <i.icon size={18} style={{ color: ACCENT, flexShrink: 0 }} />
            <span style={{ flex: 1, minWidth: 0 }}>
              <span style={{ display: 'block', fontSize: 13, fontWeight: 700, color: 'var(--text-h)' }}>{i.label}</span>
              <span style={{ display: 'block', fontSize: 11.5, color: 'var(--text-muted)' }}>{i.hint}</span>
            </span>
            <ExternalLink size={14} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />
          </Link>
        ))}
      </div>
    </div>
  )
}
