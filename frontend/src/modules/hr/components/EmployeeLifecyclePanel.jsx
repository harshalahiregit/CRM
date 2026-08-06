import { useState, useEffect } from 'react'
import { Link } from 'react-router-dom'
import { FolderOpen, CheckSquare, LifeBuoy, BookOpen, ArrowRight, Link2, AlertTriangle, Clock } from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { HrLoading } from '@/components/ui/HrState'

const ACTIVITY_STYLE = {
  project: { icon: FolderOpen,  colour: '#3b82f6' },
  task:    { icon: CheckSquare, colour: '#a78bfa' },
  ticket:  { icon: LifeBuoy,    colour: '#f59e0b' },
}
const fmtActivityDate = (d) =>
  d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : ''

/**
 * Review comment #37 — "Project, Task, KB, Tickets should reflect in employee
 * lifecycle too and they can jump to the relevant section from here itself."
 *
 * Every row links out to the module that owns it. Nothing is editable here — this
 * is a window onto four existing systems, not a fifth one.
 */
export default function EmployeeLifecyclePanel({ employeeId }) {
  const [data, setData] = useState(null)
  const [error, setError] = useState(null)

  useEffect(() => {
    if (!employeeId) return
    let cancelled = false
    hrApi.employees.lifecycle(employeeId)
      .then(r => { if (!cancelled) setData(r) })
      .catch(e => { if (!cancelled) setError(e?.response?.data?.message || 'Could not load lifecycle') })
    return () => { cancelled = true }
  }, [employeeId])

  if (error) return <p className="text-xs" style={{ color:'#f87171' }}>{error}</p>
  if (!data) return <HrLoading label="Loading lifecycle…" />

  const SECTIONS = [
    { key:'projects',       label:'Projects',       icon:FolderOpen,  colour:'#3b82f6' },
    { key:'tasks',          label:'Tasks',          icon:CheckSquare, colour:'#a78bfa' },
    { key:'tickets',        label:'Tickets',        icon:LifeBuoy,    colour:'#f59e0b' },
    { key:'knowledge_base', label:'Knowledge Base', icon:BookOpen,    colour:'#10b981' },
  ]

  return (
    <div className="space-y-3">
      {/* Four empty lists would read as "this person has done nothing" — say why. */}
      {!data.linked && (
        <div className="rounded-xl p-3 flex items-start gap-2" style={{ background:'rgba(251,191,36,0.08)', border:'1px solid rgba(251,191,36,0.25)' }}>
          <Link2 size={13} style={{ color:'#fbbf24', flexShrink:0, marginTop:2 }}/>
          <p className="text-[11px]" style={{ color:'#fbbf24' }}>{data.reason}</p>
        </div>
      )}

      <div className="grid md:grid-cols-2 gap-3">
        {SECTIONS.map(({ key, label, icon:Icon, colour }) => {
          const section = data[key] || { total:0, items:[] }

          return (
            <div key={key} className="card-3d" style={{ padding:'14px 16px' }}>
              <div className="flex items-center justify-between gap-2 mb-2">
                <p className="text-xs font-black flex items-center gap-1.5" style={{ color:'var(--text-h)' }}>
                  <Icon size={14} style={{ color:colour }}/> {label}
                  <span className="px-1.5 py-0.5 rounded text-[10px]" style={{ background:'var(--bg-input)', color:'var(--text-muted)' }}>
                    {section.total ?? 0}
                  </span>
                </p>
                {section.link && (
                  <Link to={section.link} className="text-[10px] font-bold inline-flex items-center gap-1" style={{ color:'#a78bfa' }}>
                    Open <ArrowRight size={11}/>
                  </Link>
                )}
              </div>

              {/* Section-specific counters, where the module offers something useful. */}
              {key === 'tasks' && section.total > 0 && (
                <p className="text-[10px] mb-1.5" style={{ color:'var(--text-muted)' }}>
                  {section.open} open{section.overdue > 0 && <span style={{ color:'#f87171' }}> · {section.overdue} overdue</span>}
                </p>
              )}
              {key === 'tickets' && section.total > 0 && (
                <p className="text-[10px] mb-1.5" style={{ color:'var(--text-muted)' }}>{section.open} open</p>
              )}
              {key === 'projects' && section.total > 0 && (
                <p className="text-[10px] mb-1.5" style={{ color:'var(--text-muted)' }}>{section.open} active</p>
              )}

              {(section.items || []).length === 0 ? (
                <p className="text-[11px]" style={{ color:'var(--text-muted)' }}>Nothing to show.</p>
              ) : (
                <div className="space-y-1">
                  {section.items.map(item => (
                    <Link key={item.id} to={item.link}
                      className="flex items-center justify-between gap-2 px-2 py-1 rounded-lg"
                      style={{ background:'var(--bg-input)' }}>
                      <span className="text-[11px] truncate" style={{ color:'var(--text-h)' }}>{item.title}</span>
                      <span className="text-[10px] whitespace-nowrap" style={{ color:'var(--text-muted)' }}>
                        {item.due || item.published_at || item.status || ''}
                      </span>
                    </Link>
                  ))}
                  {section.total > section.items.length && (
                    <p className="text-[10px] pt-0.5" style={{ color:'var(--text-muted)' }}>
                      Showing {section.items.length} of {section.total} — open the module for the rest.
                    </p>
                  )}
                </div>
              )}

              {/* The KB list is department-matched, not authored — say so plainly. */}
              {key === 'knowledge_base' && section.basis && (
                <p className="text-[10px] mt-1.5 flex items-start gap-1" style={{ color:'var(--text-muted)' }}>
                  <AlertTriangle size={10} style={{ flexShrink:0, marginTop:1 }}/> {section.basis}
                </p>
              )}
            </div>
          )
        })}
      </div>

      <ActivityReferences items={data.activity} />
    </div>
  )
}

/**
 * #37 — "timeline/activity references where available".
 *
 * REFERENCES, not an event stream. The four modules keep no per-item history this
 * panel can read, so each row says what the date actually means ("Task due",
 * "Ticket resolved") and links to the record. Anything undated never arrives here
 * — the service drops it rather than stamping it today.
 */
function ActivityReferences({ items }) {
  const [expanded, setExpanded] = useState(false)
  const rows = items || []
  if (rows.length === 0) return null

  const shown = expanded ? rows : rows.slice(0, 6)

  return (
    <div className="card-3d" style={{ padding:'14px 16px' }}>
      <div className="flex items-center justify-between gap-2 mb-2">
        <p className="text-xs font-black flex items-center gap-1.5" style={{ color:'var(--text-h)' }}>
          <Clock size={14} style={{ color:'#38bdf8' }}/> Activity References
          <span className="px-1.5 py-0.5 rounded text-[10px]" style={{ background:'var(--bg-input)', color:'var(--text-muted)' }}>
            {rows.length}
          </span>
        </p>
        {rows.length > 6 && (
          <button onClick={() => setExpanded(e => !e)} className="text-[10px] font-bold" style={{ color:'#a78bfa' }}>
            {expanded ? 'Show less' : `Show all ${rows.length}`}
          </button>
        )}
      </div>

      <div className="space-y-1">
        {shown.map((a, i) => {
          const { icon:Icon, colour } = ACTIVITY_STYLE[a.type] || ACTIVITY_STYLE.task
          return (
            <Link key={`${a.type}-${a.link}-${i}`} to={a.link}
              className="flex items-center gap-2 px-2 py-1.5 rounded-lg"
              style={{ background:'var(--bg-input)' }}>
              <span className="rounded flex items-center justify-center" style={{ width:20, height:20, flexShrink:0, background:`${colour}1a` }}>
                <Icon size={11} style={{ color:colour }}/>
              </span>
              <span className="text-[11px] truncate flex-1" style={{ color:'var(--text-h)' }}>{a.title}</span>
              <span className="text-[10px] whitespace-nowrap" style={{ color:'var(--text-muted)' }}>{a.detail}</span>
              <span className="text-[10px] whitespace-nowrap font-semibold" style={{ color:colour }}>{fmtActivityDate(a.date)}</span>
            </Link>
          )
        })}
      </div>
    </div>
  )
}
