import { useState, useEffect, useCallback } from 'react'
import { Link } from 'react-router-dom'
import { Network, AlertTriangle, ChevronDown, ChevronRight, Users, Briefcase, UserCheck } from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { useMasterData } from '@/modules/hr/useMasterData'
import { HrLoading, HrEmpty } from '@/components/ui/HrState'

/**
 * Review comment #29 — "Organization chart – auto create and update based on
 * employee, consultant, freelancer added in system".
 *
 * Read-only by design. The hierarchy lives on the employee record
 * (`reporting_manager_id`), so it is changed by editing the person, not by
 * dragging a box on a chart — one place to change it, and no second copy of the
 * structure that can disagree with the employee list.
 */

const TYPE_STYLE = {
  employee:   { label: 'Employee',   colour: '#7C3AED', icon: Users },
  consultant: { label: 'Consultant', colour: '#0ea5e9', icon: Briefcase },
  freelancer: { label: 'Freelancer', colour: '#10b981', icon: UserCheck },
}

const initials = (n) => (n || '').split(' ').slice(0, 2).map(x => x[0]).join('').toUpperCase()

export default function OrgChart() {
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [deptF, setDeptF] = useState('All')
  const [typeF, setTypeF] = useState('All')
  const [includeInactive, setIncludeInactive] = useState(false)
  const { masters } = useMasterData()

  const load = useCallback(async () => {
    setLoading(true)
    try {
      setData(await hrApi.employees.orgChart({
        ...(deptF !== 'All' ? { department: deptF } : {}),
        ...(typeF !== 'All' ? { worker_type: typeF } : {}),
        ...(includeInactive ? { include_inactive: 1 } : {}),
      }))
    } catch (e) { console.error('Failed to load org chart', e); setData(null) }
    finally { setLoading(false) }
  }, [deptF, typeF, includeInactive])

  useEffect(() => { load() }, [load])

  return (
    <div className="space-y-6 animate-[tiltIn_0.35s_ease_forwards]">
      <div className="flex items-start justify-between flex-wrap gap-3">
        <div>
          <p className="label-caps mb-1">HR Records</p>
          <h1 className="font-black flex items-center gap-2" style={{ fontSize:'clamp(1.3rem,2vw,1.7rem)', color:'var(--text-h)', letterSpacing:'-0.02em' }}>
            <Network size={22} style={{ color:'#a78bfa' }}/> Organization <span className="text-gradient">Chart</span>
          </h1>
          <p className="text-sm mt-0.5" style={{ color:'var(--text-muted)' }}>
            Built from each person's reporting manager — it updates itself as people join, move or leave.
          </p>
        </div>
      </div>

      <div className="card-3d flex items-center gap-3 flex-wrap" style={{ padding:'14px 18px' }}>
        <select value={deptF} onChange={e=>setDeptF(e.target.value)} className="input-3d text-sm" style={{ width:'auto' }}>
          <option value="All">All Departments</option>
          {(masters.departments || []).map(d => <option key={d.id} value={d.name}>{d.name}</option>)}
        </select>
        <select value={typeF} onChange={e=>setTypeF(e.target.value)} className="input-3d text-sm" style={{ width:'auto' }}>
          <option value="All">Everyone</option>
          {Object.entries(TYPE_STYLE).map(([k, v]) => <option key={k} value={k}>{v.label}s</option>)}
        </select>
        <label className="flex items-center gap-2 text-xs font-semibold cursor-pointer" style={{ color:'var(--text-muted)' }}>
          <input type="checkbox" checked={includeInactive} onChange={e=>setIncludeInactive(e.target.checked)}/>
          Include inactive
        </label>

        {data && (
          <div className="flex items-center gap-3 ml-auto flex-wrap">
            {Object.entries(TYPE_STYLE).map(([k, v]) => (data.legend?.[k] > 0) && (
              <span key={k} className="flex items-center gap-1.5 text-[11px]" style={{ color:'var(--text-muted)' }}>
                <span style={{ width:8, height:8, borderRadius:99, background:v.colour }}/>
                {data.legend[k]} {v.label}{data.legend[k] === 1 ? '' : 's'}
              </span>
            ))}
            <span className="text-[11px]" style={{ color:'var(--text-muted)' }}>· {data.max_depth} levels</span>
          </div>
        )}
      </div>

      {/* Data problems the chart cannot fix on its own. Surfaced rather than
          silently patched — only a human knows who actually reports to whom. */}
      {data?.issues?.length > 0 && <Issues issues={data.issues} />}

      {loading ? <HrLoading label="Building the chart…" />
        : !data || data.total === 0 ? (
          <HrEmpty icon={Network} title="Nobody to chart yet"
            hint="Employees appear here automatically. Set a reporting manager on each person to build the hierarchy." />
        ) : (
          <div className="card-3d" style={{ padding:'18px 20px', overflowX:'auto' }}>
            <div style={{ minWidth:'min-content' }}>
              {data.roots.map(node => <Node key={node.id} node={node} />)}
            </div>
          </div>
        )}
    </div>
  )
}

function Issues({ issues }) {
  const [open, setOpen] = useState(false)
  const shown = open ? issues : issues.slice(0, 3)

  return (
    <div className="rounded-xl p-3" style={{ background:'rgba(251,191,36,0.08)', border:'1px solid rgba(251,191,36,0.25)' }}>
      <div className="flex items-center justify-between gap-2 mb-1.5">
        <p className="text-xs font-bold flex items-center gap-1.5" style={{ color:'#fbbf24' }}>
          <AlertTriangle size={13}/> {issues.length} reporting {issues.length === 1 ? 'issue' : 'issues'}
        </p>
        {issues.length > 3 && (
          <button onClick={()=>setOpen(o=>!o)} className="text-[10px] font-bold" style={{ color:'#fbbf24' }}>
            {open ? 'Show less' : 'Show all'}
          </button>
        )}
      </div>
      <div className="space-y-1">
        {shown.map((i, idx) => (
          <p key={idx} className="text-[11px]" style={{ color:'#fbbf24' }}>
            <Link to={`/app/hr/employees`} style={{ textDecoration:'underline' }}>{i.employee_name || `#${i.employee_id}`}</Link>
            {' — '}{i.detail}
          </p>
        ))}
      </div>
    </div>
  )
}

/**
 * One person and their reports.
 *
 * Collapsed below the third level by default: a chart that opens fully expanded
 * on a 300-person company is a wall, not a chart.
 */
function Node({ node }) {
  const [open, setOpen] = useState(node.level < 3)
  const style = TYPE_STYLE[node.worker_type] || TYPE_STYLE.employee
  const Icon = style.icon
  const hasReports = node.children?.length > 0

  return (
    <div style={{ marginLeft: node.level > 1 ? 26 : 0 }}>
      <div className="flex items-center gap-2 py-1">
        <button onClick={()=>setOpen(o=>!o)} disabled={!hasReports}
          className="flex items-center justify-center rounded"
          style={{ width:18, height:18, flexShrink:0, color:'var(--text-muted)',
                   visibility: hasReports ? 'visible' : 'hidden', cursor: hasReports ? 'pointer' : 'default' }}>
          {open ? <ChevronDown size={14}/> : <ChevronRight size={14}/>}
        </button>

        <Link to={`/app/hr/employees/${node.id}`}
          className="flex items-center gap-2.5 px-3 py-2 rounded-xl flex-1 min-w-0"
          style={{ background:'var(--bg-input)', border:`1px solid ${node.level === 1 ? style.colour + '55' : 'var(--border)'}`, textDecoration:'none' }}>
          <span className="rounded-xl flex items-center justify-center text-[10px] font-black text-white flex-shrink-0"
            style={{ width:30, height:30, background:`linear-gradient(135deg,${style.colour},${style.colour}bb)` }}>
            {initials(node.name)}
          </span>
          <span className="min-w-0">
            <span className="flex items-center gap-1.5 flex-wrap">
              <span className="text-xs font-bold truncate" style={{ color:'var(--text-h)' }}>{node.name}</span>
              {node.worker_type !== 'employee' && (
                <span className="px-1.5 py-0.5 rounded text-[9px] font-bold inline-flex items-center gap-1"
                  style={{ background:`${style.colour}1a`, color:style.colour }}>
                  <Icon size={9}/> {style.label}
                </span>
              )}
              {node.status !== 'Active' && (
                <span className="px-1.5 py-0.5 rounded text-[9px] font-bold" style={{ background:'var(--bg-card)', color:'var(--text-muted)' }}>
                  {node.status}
                </span>
              )}
            </span>
            <span className="block text-[10px] truncate" style={{ color:'var(--text-muted)' }}>
              {[node.designation, node.department].filter(Boolean).join(' · ') || '—'}
            </span>
          </span>
          {hasReports && (
            <span className="text-[10px] font-semibold whitespace-nowrap ml-auto" style={{ color:style.colour }}>
              {node.direct_count} direct{node.reports_count !== node.direct_count ? ` · ${node.reports_count} total` : ''}
            </span>
          )}
        </Link>
      </div>

      {open && hasReports && (
        <div style={{ borderLeft:'1px solid var(--border)', marginLeft:9 }}>
          {node.children.map(c => <Node key={c.id} node={c} />)}
        </div>
      )}
    </div>
  )
}
