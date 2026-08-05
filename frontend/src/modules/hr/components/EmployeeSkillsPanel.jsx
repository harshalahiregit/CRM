import { useState, useEffect, useCallback } from 'react'
import { Target, Pencil, X, Loader2, AlertTriangle } from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import TagInput from '@/components/ui/TagInput'
import { SkillFit } from './operations/EmployeeMovements'

/**
 * Review comment #43 — the employee half of "add SKILL field … and when we assign
 * any emp to these (dept/designation etc.), system indicate relevant skills and
 * score of individual to analyse."
 *
 * The expected-skills half (department / designation / grade / role) has been
 * editable in Organization Setup since Phase A, and EmployeeSkillService has
 * scored the two against each other since then. What was missing was any way to
 * record the OTHER operand: no screen wrote `hr_employees.skills`, so the
 * comparison always ran against an empty list and every employee scored 0% with
 * every expected skill reported as a gap.
 *
 * This is only the missing UI. It calls the existing GET/PUT
 * /hr/employees/{id}/skills, and renders the score with the SAME `SkillFit`
 * component the transfer screen already uses — the fit is presented identically
 * wherever it appears, and there is no second scoring path.
 */
export default function EmployeeSkillsPanel({ employeeId, canManage }) {
  const [data, setData]     = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError]   = useState(null)
  const [editing, setEditing] = useState(null)   // string[] while open, null when closed
  const [saving, setSaving] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    try { setData(await hrApi.employees.skills(employeeId)); setError(null) }
    catch (e) {
      // 403 is an answer about permission, not a broken panel.
      setError(e?.response?.status === 403
        ? 'You are not authorised to view employee skills.'
        : (e?.response?.data?.message || 'Could not load skills'))
    }
    finally { setLoading(false) }
  }, [employeeId])

  useEffect(() => { load() }, [load])

  const save = async () => {
    setSaving(true)
    try {
      // The PUT returns the freshly re-analysed payload, so the score below
      // updates from the server's own calculation rather than a local guess.
      setData(await hrApi.employees.updateSkills(employeeId, editing))
      setEditing(null)
    } catch (e) { setError(e?.response?.data?.message || 'Could not save skills') }
    finally { setSaving(false) }
  }

  if (loading) return <p className="text-xs" style={{ color:'var(--text-muted)' }}>Loading skills…</p>
  if (error && !data) return <p className="text-xs" style={{ color:'#f87171' }}>{error}</p>

  const held = data?.employee_skills || []

  return (
    <div className="mt-5">
      <div className="flex items-center justify-between gap-3 mb-2">
        <p className="text-[11px] font-bold uppercase flex items-center gap-1.5" style={{ color:'var(--text-muted)', letterSpacing:'0.04em' }}>
          <Target size={12}/> Skills
        </p>
        {canManage && editing === null && (
          <button onClick={()=>setEditing([...held])} className="text-[11px] font-bold inline-flex items-center gap-1" style={{ color:'#a78bfa' }}>
            <Pencil size={11}/> {held.length ? 'Edit' : 'Add skills'}
          </button>
        )}
      </div>

      {editing !== null ? (
        <div className="rounded-xl p-3" style={{ background:'var(--bg-input)', border:'1px solid var(--border)' }}>
          <TagInput value={editing} onChange={setEditing} max={40} placeholder="Type a skill and press Enter…" />
          <p className="text-[10px] mt-2" style={{ color:'var(--text-muted)' }}>
            Compared against the expected skills on this employee’s department, designation, grade and role.
          </p>
          <div className="flex gap-2 mt-3">
            <button onClick={()=>{ setEditing(null); setError(null) }} className="px-3 py-1.5 rounded-lg text-xs font-semibold"
              style={{ background:'var(--bg-card)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>
              <X size={11} className="inline mr-1"/> Cancel
            </button>
            <button onClick={save} disabled={saving} className="px-3 py-1.5 rounded-lg text-xs font-bold text-white inline-flex items-center gap-1.5"
              style={{ background:'linear-gradient(135deg,#7C3AED,#5b21b6)', opacity:saving?0.7:1 }}>
              {saving && <Loader2 size={11} className="animate-spin"/>} Save
            </button>
          </div>
          {error && <p className="text-[11px] mt-2" style={{ color:'#f87171' }}>{error}</p>}
        </div>
      ) : held.length === 0 ? (
        // Says WHY there is no score rather than showing a 0% that reads as a
        // total mismatch — the employee has not been assessed, not failed.
        <div className="rounded-xl p-3 flex items-start gap-2" style={{ background:'rgba(251,191,36,0.08)', border:'1px solid rgba(251,191,36,0.25)' }}>
          <AlertTriangle size={13} style={{ color:'#fbbf24', flexShrink:0, marginTop:1 }}/>
          <p className="text-[11px]" style={{ color:'#fbbf24' }}>
            No skills recorded for this employee, so there is nothing to score against the expected skills of their position.
            {canManage ? ' Use “Add skills” above.' : ''}
          </p>
        </div>
      ) : (
        <>
          <div className="flex flex-wrap gap-1.5 mb-3">
            {held.map(s => (
              <span key={s} className="px-2.5 py-1 rounded-lg text-[11px] font-semibold"
                style={{ background:'rgba(124,58,237,0.1)', color:'#a78bfa', border:'1px solid rgba(124,58,237,0.2)' }}>{s}</span>
            ))}
          </div>
          {/* Same component the transfer screen uses — one presentation of fit. */}
          <SkillFit fit={data} title="Skill fit for the current position" />
        </>
      )}
    </div>
  )
}
