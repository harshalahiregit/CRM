/**
 * Approval workflows — who approves what, in what order.
 *
 * The form is built from the options the server sends, not a list kept here:
 * approver types, this workspace's roles and users, and the conditions each
 * process understands all arrive with the ladder. A screen with its own copy of
 * that vocabulary is how an approver type ends up selectable that the engine
 * does not implement.
 *
 * What the page has to make obvious is the ORDER, because that is the whole
 * point of a ladder — so steps are numbered, moved with explicit controls, and
 * read top to bottom.
 */

import { useState, useEffect, useCallback } from 'react'
import { GitBranch, Plus, Trash2, ArrowUp, ArrowDown, Save, Info } from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { HrLoading, HrEmpty } from '@/components/ui/HrState'
import { useToast } from '@/components/ui/Toast'

const inputStyle = {
  padding: '8px 11px', background: 'var(--bg-input)',
  border: '1px solid var(--border)', color: 'var(--text-p)',
}

/** A step needs somebody chosen unless it follows the reporting line. */
function needsRef(type) {
  return type === 'staff_role' || type === 'specific_user'
}

function StepRow({ step, index, total, options, onChange, onMove, onRemove }) {
  const choices = step.approver_type === 'staff_role' ? options.roles
    : step.approver_type === 'specific_user' ? options.users
    : []

  return (
    <div className="card-3d" style={{ padding: '12px' }}>
      <div className="flex items-start gap-3">
        <div className="shrink-0 w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold"
          style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }}>
          {index + 1}
        </div>

        <div className="flex-1 min-w-0 grid grid-cols-1 sm:grid-cols-2 gap-3">
          <label className="flex flex-col gap-1">
            <span className="text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>Step name</span>
            <input value={step.name ?? ''} onChange={e => onChange(index, { name: e.target.value })}
              placeholder="Reporting manager" className="rounded-lg text-sm w-full" style={inputStyle} />
          </label>

          <label className="flex flex-col gap-1">
            <span className="text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>Approver</span>
            <select value={step.approver_type} className="rounded-lg text-sm w-full" style={inputStyle}
              onChange={e => onChange(index, { approver_type: e.target.value, approver_ref: null })}>
              {options.approver_types.map(t => <option key={t.value} value={t.value}>{t.label}</option>)}
            </select>
          </label>

          {needsRef(step.approver_type) && (
            <label className="flex flex-col gap-1 sm:col-span-2">
              <span className="text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>
                {step.approver_type === 'staff_role' ? 'Which role' : 'Which person'}
              </span>
              <select value={step.approver_ref ?? ''} className="rounded-lg text-sm w-full" style={inputStyle}
                onChange={e => onChange(index, { approver_ref: e.target.value ? Number(e.target.value) : null })}>
                <option value="">Select…</option>
                {choices.map(c => <option key={c.value} value={c.value}>{c.label}</option>)}
              </select>
            </label>
          )}

          {step.approver_type === 'reporting_manager' && (
            <label className="flex flex-col gap-1">
              <span className="text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>Levels up</span>
              <input type="number" min="1" max="10" value={step.levels_up ?? 1}
                onChange={e => onChange(index, { levels_up: Number(e.target.value) || 1 })}
                className="rounded-lg text-sm w-full" style={inputStyle} />
              <span className="text-[10px]" style={{ color: 'var(--text-muted)' }}>1 is the employee’s own manager.</span>
            </label>
          )}

          {/*
            Amount bounds, only where the process has money on it — the server
            says which do. Leave has no amount, so offering the field there
            would invite a rule that can never match.

            Blank means "no bound", which is why an empty string clears the key
            rather than sending 0: a minimum of zero would read as a rule and
            match everything.
          */}
          {options.supports_amount && (
            <>
              <label className="flex flex-col gap-1">
                <span className="text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>Only if amount ≥</span>
                <input type="number" min="0" step="0.01" placeholder="Any"
                  value={step.conditions?.min_amount ?? ''}
                  onChange={e => onChange(index, {
                    conditions: { ...(step.conditions || {}), min_amount: e.target.value === '' ? undefined : Number(e.target.value) },
                  })}
                  className="rounded-lg text-sm w-full" style={inputStyle} />
              </label>

              <label className="flex flex-col gap-1">
                <span className="text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>Only if amount ≤</span>
                <input type="number" min="0" step="0.01" placeholder="Any"
                  value={step.conditions?.max_amount ?? ''}
                  onChange={e => onChange(index, {
                    conditions: { ...(step.conditions || {}), max_amount: e.target.value === '' ? undefined : Number(e.target.value) },
                  })}
                  className="rounded-lg text-sm w-full" style={inputStyle} />
              </label>
            </>
          )}
        </div>

        <div className="flex flex-col gap-1 shrink-0">
          <button type="button" onClick={() => onMove(index, -1)} disabled={index === 0}
            aria-label="Move step up" title="Move up"
            className="p-1.5 rounded-lg disabled:opacity-30" style={{ border: '1px solid var(--border)' }}>
            <ArrowUp size={13} />
          </button>
          <button type="button" onClick={() => onMove(index, 1)} disabled={index === total - 1}
            aria-label="Move step down" title="Move down"
            className="p-1.5 rounded-lg disabled:opacity-30" style={{ border: '1px solid var(--border)' }}>
            <ArrowDown size={13} />
          </button>
          <button type="button" onClick={() => onRemove(index)}
            aria-label="Remove step" title="Remove"
            className="p-1.5 rounded-lg" style={{ border: '1px solid var(--border)', color: '#ef4444' }}>
            <Trash2 size={13} />
          </button>
        </div>
      </div>
    </div>
  )
}

export default function ApprovalWorkflows() {
  const toast = useToast()

  const [processes, setProcesses] = useState([])
  const [process,   setProcess]   = useState('leave')
  const [detail,    setDetail]    = useState(null)
  const [steps,     setSteps]     = useState([])
  const [active,    setActive]    = useState(true)
  const [loading,   setLoading]   = useState(true)
  const [busy,      setBusy]      = useState(false)

  const loadList = useCallback(async () => {
    try { setProcesses(await hrApi.approvalWorkflows.list()) }
    catch { toast.error('Could not load approval workflows') }
  }, [toast])

  const loadDetail = useCallback(async (key) => {
    setLoading(true)
    try {
      const d = await hrApi.approvalWorkflows.show(key)
      setDetail(d)
      setSteps(d?.steps ?? [])
      setActive(d?.workflow ? !!d.workflow.is_active : true)
    } catch {
      toast.error('Could not load this workflow')
    } finally {
      setLoading(false)
    }
  }, [toast])

  useEffect(() => { loadList() }, [loadList])
  useEffect(() => { loadDetail(process) }, [process, loadDetail])

  const changeStep = (i, patch) =>
    setSteps(s => s.map((step, idx) => (idx === i ? { ...step, ...patch } : step)))

  const moveStep = (i, delta) => setSteps(s => {
    const next = [...s]
    const j = i + delta
    if (j < 0 || j >= next.length) return s
    ;[next[i], next[j]] = [next[j], next[i]]
    return next
  })

  const removeStep = (i) => setSteps(s => s.filter((_, idx) => idx !== i))

  const addStep = () => setSteps(s => [...s, {
    name: '', approver_type: 'reporting_manager', approver_ref: null, levels_up: 1, conditions: {}, is_active: true,
  }])

  const save = async () => {
    // Caught here rather than by the server so the message names the step.
    const bad = steps.findIndex(s => needsRef(s.approver_type) && !s.approver_ref)
    if (bad !== -1) return toast.error(`Step ${bad + 1} needs an approver selected.`)

    setBusy(true)
    try {
      const d = await hrApi.approvalWorkflows.save(process, { is_active: active, steps })
      setDetail(d); setSteps(d?.steps ?? [])
      toast.success('Workflow saved')
      loadList()
    } catch (e) {
      toast.error(e?.response?.data?.message || 'Could not save the workflow')
    } finally { setBusy(false) }
  }

  const options = detail?.options ?? { approver_types: [], roles: [], users: [], conditions: [] }

  return (
    <div className="space-y-4">
      <div className="flex items-start justify-between gap-3 flex-wrap">
        <div className="flex items-center gap-2">
          <GitBranch size={18} style={{ color: 'var(--text-h)' }} />
          <div>
            <h1 className="text-lg font-bold" style={{ color: 'var(--text-h)' }}>Approval workflows</h1>
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
              Who approves each request, and in what order.
            </p>
          </div>
        </div>

        <button type="button" onClick={save} disabled={busy || loading}
          className="rounded-lg text-sm font-bold flex items-center gap-2 disabled:opacity-50"
          style={{ padding: '8px 14px', background: 'var(--accent)', color: '#fff' }}>
          <Save size={14} /> {busy ? 'Saving…' : 'Save workflow'}
        </button>
      </div>

      {processes.length > 1 && (
        <div className="flex gap-2 flex-wrap">
          {processes.map(p => (
            <button key={p.process} type="button" onClick={() => setProcess(p.process)}
              className="rounded-lg text-xs font-bold"
              style={{
                padding: '6px 12px',
                border: '1px solid var(--border)',
                background: p.process === process ? 'var(--bg-input)' : 'transparent',
                color: 'var(--text-h)',
              }}>
              {p.label}
            </button>
          ))}
        </div>
      )}

      {loading ? <HrLoading label="Loading workflow…" /> : (
        <>
          <div className="card-3d flex items-start justify-between gap-3" style={{ padding: '12px' }}>
            <div className="min-w-0">
              <p className="text-xs font-bold" style={{ color: 'var(--text-h)' }}>Workflow enabled</p>
              <p className="text-[10px] mt-0.5" style={{ color: 'var(--text-muted)' }}>
                When this is off — or no steps are configured — anyone who may manage the HR
                queue can approve, exactly as before.
              </p>
            </div>
            <button type="button" onClick={() => setActive(a => !a)}
              aria-pressed={active} aria-label="Workflow enabled"
              className="w-11 h-6 rounded-full relative transition-all shrink-0"
              style={{ background: active ? '#10b981' : 'var(--border)' }}>
              <span className="absolute top-0.5 w-5 h-5 rounded-full bg-white shadow transition-all"
                style={{ left: active ? '22px' : '2px' }} />
            </button>
          </div>

          {steps.length === 0 ? (
            <HrEmpty icon={GitBranch} title="No approval steps"
              hint="Approval currently falls back to anyone who may manage the HR queue. Add a step to define your own ladder." />
          ) : (
            <div className="space-y-2">
              {steps.map((step, i) => (
                <StepRow key={i} step={step} index={i} total={steps.length} options={options}
                  onChange={changeStep} onMove={moveStep} onRemove={removeStep} />
              ))}
            </div>
          )}

          <button type="button" onClick={addStep}
            className="rounded-lg text-sm font-bold flex items-center gap-2"
            style={{ padding: '8px 14px', border: '1px dashed var(--border)', color: 'var(--text-h)' }}>
            <Plus size={14} /> Add step
          </button>

          <div className="flex items-start gap-2 text-[11px] rounded-lg"
            style={{ padding: '10px 12px', background: 'var(--bg-input)', color: 'var(--text-muted)' }}>
            <Info size={13} className="shrink-0 mt-0.5" />
            <p>
              Steps run in order — the next approver is asked only once the previous one has
              approved, and a rejection at any step ends the request. Changing this ladder does
              not affect approvals already in progress.
              {options.supports_amount && ' A step with an amount bound is used only for requests inside it; the others always apply.'}
            </p>
          </div>
        </>
      )}
    </div>
  )
}
