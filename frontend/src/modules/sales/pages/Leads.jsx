import { useState, useEffect, useCallback } from 'react'
import { createPortal } from 'react-dom'
import { useNavigate } from 'react-router-dom'
import GoalSummary from '../components/GoalSummary'
import { taskApi } from '@/services/taskApi'
import { salesApi } from '@/services/salesApi'
import WinProbabilityBadge from '../components/WinProbabilityBadge'
import { Plus, Search, MoreVertical, X, UserPlus, Flame, Thermometer, Snowflake, Eye, Trash2, XCircle, RotateCcw, TrendingUp, Users, Target, DollarSign, LayoutGrid, List, ChevronDown } from 'lucide-react'
import { useToast } from '@/hooks/useToast'
import RichTextEditor from '@/components/ui/RichTextEditor'

const TEMP_ICON = { Hot: Flame, Warm: Thermometer, Cold: Snowflake }
const TEMP_COLOR = { Hot: '#ef4444', Warm: '#f59e0b', Cold: '#3b82f6' }

export default function Leads() {
  const navigate = useNavigate()
  const [data, setData] = useState([])
  const [statuses, setStatuses] = useState([])
  const [sources, setSources] = useState([])
  const [staff, setStaff] = useState([])
  const [summary, setSummary] = useState(null)
  const [loading, setLoading] = useState(true)
  const [view, setView] = useState('table')
  // null = board not fetched yet. Distinct from [] (fetched, genuinely no columns)
  // so switching to the board doesn't flash the "no statuses" empty state: the
  // page-level `loading` flag is already false by then and can't cover this.
  const [kanban, setKanban] = useState(null)
  const [restoring, setRestoring] = useState(false)
  const [filter, setFilter] = useState('all')
  const [search, setSearch] = useState('')
  const [showDrawer, setShowDrawer] = useState(false)
  const [openMenu, setOpenMenu] = useState(null)
  const [menuPos, setMenuPos] = useState(null)  // fixed-position anchor for the row menu so it escapes the table's overflow
  const [selected, setSelected] = useState([])
  const [form, setForm] = useState({ name:'', email:'', phone:'', company:'', title:'', website:'', industry:'', campaign:'', priority:'medium', expected_close_date:'', description:'', lead_value:'', source:'', status_id:'', assigned_to:'', tags:'', address:'', city:'', state:'', country:'', zip:'', referral_type:'none', referral_value:'', referral_contact:'' })

  // Routed through the shared Toast so every module notifies identically
  // (and error toasts get the per-field validation detail + tip).
  const toast = useToast()
  const showToast = (msg, type = 'success') =>
    type === 'error' ? toast.error(msg) : type === 'info' ? toast.info(msg) : toast.success(msg)
  const sf = (k,v) => setForm(p=>({...p,[k]:v}))

  const load = useCallback(async () => {
    try {
      const [leads, sts, srcs, sum] = await Promise.all([
        salesApi.leads.list(filter !== 'all' ? { status_id: filter } : {}),
        salesApi.leadStatuses.list(),
        salesApi.leadSources.list(),
        salesApi.leads.summary(),
      ])
      setData(leads); setStatuses(sts); setSources(srcs); setSummary(sum)
      if (view === 'kanban') {
        const k = await salesApi.leads.kanban()
        setKanban(Array.isArray(k) ? k : (k?.data ?? []))
      }
    } catch(e) { showToast(e.message,'error') }
    setLoading(false)
  }, [filter, view])


  /**
   * Recreate the standard pipeline stages.
   *
   * The backend seeds these for any workspace that has never had lead settings, so
   * this is only reachable when someone has deleted every stage — at which point
   * the board has no columns and there is no other screen for rebuilding them.
   * Mirrors LeadDefaultsSeeder; done through the normal create endpoint so the
   * stages are ordinary editable records.
   */
  const restoreStages = async () => {
    setRestoring(true)
    try {
      const defaults = [
        { name: 'New',           color: '#3b82f6', is_default: true },
        { name: 'Contacted',     color: '#8b5cf6' },
        { name: 'Qualified',     color: '#f59e0b' },
        { name: 'Proposal Sent', color: '#ec4899' },
        { name: 'Negotiation',   color: '#ef4444' },
        { name: 'Won',           color: '#10b981', is_won_status: true },
      ]
      // Sequential, not Promise.all: sort_order follows creation order.
      for (let i = 0; i < defaults.length; i++) {
        await salesApi.leadStatuses.create({ ...defaults[i], sort_order: i + 1 })
      }
      showToast('Pipeline stages created')
      await load()
    } catch (e) { showToast(e.message, 'error') } finally { setRestoring(false) }
  }

  useEffect(() => { load() }, [load])
  // Assignable staff — fetched once; the list doesn't change with the filter.
  useEffect(() => { taskApi.staff().then(setStaff).catch(() => setStaff([])) }, [])

  const handleCreate = async () => {
    if (!form.name.trim()) return showToast('Name is required','error')
    try {
      // lead_value & referral_value are NOT-NULL numerics (DB default 0). An
      // empty box would become null and break the insert, so send 0 instead.
      const payload = {
        ...form,
        lead_value: form.lead_value === '' ? 0 : form.lead_value,
        referral_value: form.referral_value === '' ? 0 : form.referral_value,
      }
      await salesApi.leads.create(payload)
      showToast('Lead created')
      setShowDrawer(false)
      setForm({ name:'', email:'', phone:'', company:'', title:'', website:'', industry:'', campaign:'', priority:'medium', expected_close_date:'', description:'', lead_value:'', source:'', status_id:'', assigned_to:'', tags:'', address:'', city:'', state:'', country:'', zip:'', referral_type:'none', referral_value:'', referral_contact:'' })
      load()
    } catch(e) { showToast(e.message,'error') }
  }

  const handleAction = async (action, lead) => {
    setOpenMenu(null)
    try {
      if (action==='lost') { await salesApi.leads.markLost(lead.id); showToast('Marked as lost') }
      if (action==='junk') { await salesApi.leads.markJunk(lead.id); showToast('Marked as junk') }
      if (action==='restore') { await salesApi.leads.restore(lead.id); showToast('Lead restored') }
      if (action==='delete') { await salesApi.leads.delete(lead.id); showToast('Lead deleted') }
      load()
    } catch(e) { showToast(e.message,'error') }
  }

  const handleBulk = async (action, value) => {
    if (!selected.length) return
    try {
      await salesApi.leads.bulkAction({ action, lead_ids: selected, value })
      showToast(`Bulk ${action} applied`)
      setSelected([]); load()
    } catch(e) { showToast(e.message,'error') }
  }

  const filtered = data.filter(l => {
    if (search) {
      const s = search.toLowerCase()
      if (!(l.name||'').toLowerCase().includes(s) && !(l.company||'').toLowerCase().includes(s) && !(l.email||'').toLowerCase().includes(s)) return false
    }
    return true
  })

  // status_id -> active lead count, from the summary the page already fetches.
  const statusCount = (summary?.by_status || []).reduce((m, s) => { m[s.id] = s.count; return m }, {})

  const kpis = summary ? [
    { label:'Total Leads', val: summary.total, icon: Users, color:'#8b5cf6' },
    { label:'Hot Leads', val: summary.hot, icon: Flame, color:'#ef4444' },
    { label:'Converted', val: summary.converted, icon: Target, color:'#10b981' },
    { label:'Pipeline Value', val: `₹${(summary.pipeline_value/1000).toFixed(0)}K`, icon: DollarSign, color:'#f59e0b' },
    { label:'This Month', val: summary.this_month, icon: TrendingUp, color:'#3b82f6' },
  ] : []

  if (loading) return <div className="space-y-4 animate-fade-in">{[1,2,3].map(i=><div key={i} className="skeleton h-28 rounded-2xl" style={{background:'var(--border)'}}/>)}</div>

  return (
    <>

      <div className="space-y-6 animate-[tiltIn_0.35s_ease]" onClick={()=>setOpenMenu(null)}>

        {/* Header */}
        <div className="flex items-center justify-between flex-wrap gap-3">
          <div>
            <p className="label-caps mb-1" style={{color:'#a78bfa'}}>Sales & Revenue</p>
            <h1 className="text-2xl font-black" style={{color:'var(--text-h)',letterSpacing:'-0.03em'}}>Leads</h1>
            <p className="text-xs mt-0.5" style={{color:'var(--text-muted)'}}>Manage your sales pipeline leads</p>
          </div>
          <div className="flex items-center gap-2">
            <button onClick={()=>navigate('/app/sales/lead-goals')} className="flex items-center gap-2 px-4 py-2.5 rounded-2xl text-sm font-bold transition-all hover:scale-[1.03]" style={{background:'var(--bg-input)',border:'1px solid var(--border)',color:'var(--text-muted)'}}>
              <Target size={15}/> Goals
            </button>
            <button onClick={()=>setShowDrawer(true)} className="flex items-center gap-2 px-5 py-2.5 rounded-2xl text-sm font-bold text-white transition-all hover:scale-[1.03]" style={{background:'linear-gradient(135deg,#9f67ff,#7C3AED)',boxShadow:'0 6px 20px rgba(124,58,237,0.4)'}}>
              <Plus size={15}/> New Lead
            </button>
          </div>
        </div>

        {/* KPI Cards */}
        {summary && (
          <div className="grid grid-cols-2 lg:grid-cols-5 gap-3">
            {kpis.map(k => (
              <div key={k.label} className="kpi-3d p-4">
                <div className="w-10 h-10 rounded-2xl flex items-center justify-center mb-2" style={{background:`${k.color}15`}}>
                  <k.icon size={18} style={{color:k.color}}/>
                </div>
                <p className="text-xl font-black" style={{color:'var(--text-h)'}}>{k.val}</p>
                <p className="text-[11px] font-medium" style={{color:'var(--text-muted)'}}>{k.label}</p>
              </div>
            ))}
          </div>
        )}

        {/* Active lead-goal progress — renders nothing when no goals are set */}
        <GoalSummary />

        {/* Controls */}
        <div className="flex items-center justify-between flex-wrap gap-3">
          <div className="flex items-center gap-2 flex-wrap">
            {[{k:'table',I:List},{k:'kanban',I:LayoutGrid}].map(({k,I})=>(
              <button key={k} onClick={()=>setView(k)} className="px-3 py-2 rounded-xl text-xs font-bold flex items-center gap-1.5 transition-all" style={{background:view===k?'linear-gradient(135deg,#9f67ff,#7C3AED)':'var(--bg-input)',color:view===k?'#fff':'var(--text-muted)',border:`1px solid ${view===k?'transparent':'var(--border)'}`}}>
                <I size={13}/>{k==='table'?'Table':'Kanban'}
              </button>
            ))}
            {/* Each stage carries its own lead count, as in the previous CRM —
                the numbers were already computed for the KPI row but the chips
                only showed a name, so you had to click one to learn it was empty. */}
            <div className="flex gap-1 ml-2 flex-wrap">
              <button onClick={()=>setFilter('all')} className="px-3 py-1.5 rounded-xl text-[11px] font-bold transition-all" style={{background:filter==='all'?'linear-gradient(135deg,#9f67ff,#7C3AED)':'var(--bg-input)',color:filter==='all'?'#fff':'var(--text-muted)',border:'1px solid var(--border)'}}>
                {summary ? <span className="mr-1">{summary.active}</span> : null}All
              </button>
              {statuses.map(s=>{
                const on = filter===s.id
                const n = statusCount[s.id] ?? 0
                return (
                  <button key={s.id} onClick={()=>setFilter(s.id)} className="px-3 py-1.5 rounded-xl text-[11px] font-bold transition-all" style={{background:on?s.color:'var(--bg-input)',color:on?'#fff':'var(--text-muted)',border:'1px solid var(--border)'}}>
                    <span className="mr-1">{n}</span>
                    {/* Off-state name takes the stage colour so the row reads as a
                        pipeline; on-state stays white on the filled chip. */}
                    <span style={on?undefined:{color:s.color}}>{s.name}</span>
                  </button>
                )
              })}
              {/* Lost / junk sit outside the pipeline (the stage chips count only
                  active leads), and unassigned leads belong to no stage at all —
                  shown as read-only tallies so the chip numbers reconcile with the
                  total instead of appearing to lose leads. */}
              {summary && [
                { label:'Lost', n: summary.lost, tone:'#f87171' },
                { label:'Junk', n: summary.junk, tone:'#fbbf24' },
                { label:'No stage', n: summary.unassigned, tone:'var(--text-muted)' },
              ].filter(x => x.n > 0).map(x => (
                <span key={x.label} title={`${x.label} — not shown in the stage counts above`}
                  className="px-3 py-1.5 rounded-xl text-[11px] font-bold"
                  style={{background:'var(--bg-input)',color:x.tone,border:'1px dashed var(--border)'}}>
                  {x.n} {x.label}
                  {summary.total > 0 && <span className="ml-1 font-semibold" style={{color:'var(--text-muted)'}}>
                    · {Math.round((x.n / summary.total) * 100)}%
                  </span>}
                </span>
              ))}
            </div>
          </div>
          <div className="relative">
            <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2" style={{color:'var(--text-muted)'}}/>
            <input className="input-3d text-sm pl-9 w-56" placeholder="Search leads…" value={search} onChange={e=>setSearch(e.target.value)}/>
          </div>
        </div>

        {/* Bulk Actions Bar */}
        {selected.length > 0 && (
          <div className="flex items-center gap-3 p-3 rounded-2xl" style={{background:'linear-gradient(135deg,rgba(124,58,237,0.12),rgba(91,33,182,0.08))',border:'1px solid rgba(124,58,237,0.3)'}}>
            <span className="text-xs font-bold" style={{color:'#a78bfa'}}>{selected.length} selected</span>
            <button onClick={()=>handleBulk('lost')} className="text-xs font-semibold px-3 py-1.5 rounded-xl" style={{background:'rgba(239,68,68,0.1)',color:'#f87171'}}>Mark Lost</button>
            <button onClick={()=>handleBulk('delete')} className="text-xs font-semibold px-3 py-1.5 rounded-xl" style={{background:'rgba(239,68,68,0.1)',color:'#f87171'}}>Delete</button>
            <button onClick={()=>setSelected([])} className="ml-auto text-xs" style={{color:'var(--text-muted)'}}>Clear</button>
          </div>
        )}

        {/* Table View */}
        {view === 'table' && (
          <div className="card-3d overflow-hidden">
            <div className="overflow-x-auto">
              <table className="w-full text-sm" style={{color:'var(--text-body)'}}>
                <thead><tr style={{borderBottom:'1px solid var(--border)'}}>
                  <th className="py-3 px-3 text-left"><input type="checkbox" onChange={e=>setSelected(e.target.checked?filtered.map(l=>l.id):[])} checked={selected.length===filtered.length&&filtered.length>0}/></th>
                  <th className="py-3 px-3 text-left text-xs font-bold" style={{color:'var(--text-muted)'}}>Name</th>
                  <th className="py-3 px-3 text-left text-xs font-bold" style={{color:'var(--text-muted)'}}>Company</th>
                  <th className="py-3 px-3 text-left text-xs font-bold" style={{color:'var(--text-muted)'}}>Email</th>
                  <th className="py-3 px-3 text-left text-xs font-bold" style={{color:'var(--text-muted)'}}>Value</th>
                  <th className="py-3 px-3 text-left text-xs font-bold" style={{color:'var(--text-muted)'}}>Score</th>
                  <th className="py-3 px-3 text-left text-xs font-bold" style={{color:'var(--text-muted)'}}>Source</th>
                  <th className="py-3 px-3 text-left text-xs font-bold" style={{color:'var(--text-muted)'}}>Status</th>
                  <th className="py-3 px-3 text-left text-xs font-bold" style={{color:'var(--text-muted)'}}>Assigned</th>
                  <th className="py-3 px-3 w-10"></th>
                </tr></thead>
                <tbody>
                  {filtered.length === 0 && <tr><td colSpan={10} className="py-12 text-center text-sm" style={{color:'var(--text-muted)'}}>No leads found</td></tr>}
                  {filtered.map(l => {
                    const TI = TEMP_ICON[l.lead_temperature] || Snowflake
                    return (
                      <tr key={l.id} className="transition-colors cursor-pointer" style={{borderBottom:'1px solid var(--border)'}} onMouseEnter={e=>e.currentTarget.style.background='rgba(124,58,237,0.04)'} onMouseLeave={e=>e.currentTarget.style.background='transparent'}>
                        <td className="py-3 px-3"><input type="checkbox" checked={selected.includes(l.id)} onChange={e=>setSelected(p=>e.target.checked?[...p,l.id]:p.filter(x=>x!==l.id))}/></td>
                        <td className="py-3 px-3 font-bold" style={{color:'var(--text-h)'}} onClick={()=>navigate(`/app/sales/leads/${l.id}`)}>{l.name}</td>
                        <td className="py-3 px-3" style={{color:'var(--text-muted)'}}>{l.company||'—'}</td>
                        <td className="py-3 px-3" style={{color:'var(--text-muted)'}}>{l.email||'—'}</td>
                        <td className="py-3 px-3 font-semibold" style={{color:'#10b981'}}>{l.lead_value>0?`₹${Number(l.lead_value).toLocaleString()}`:'—'}</td>
                        <td className="py-3 px-3"><span className="inline-flex items-center gap-1 text-xs font-bold px-2 py-0.5 rounded-lg" style={{background:`${TEMP_COLOR[l.lead_temperature]}15`,color:TEMP_COLOR[l.lead_temperature]}}><TI size={11}/>{l.lead_score}</span></td>
                        <td className="py-3 px-3 text-xs" style={{color:'var(--text-muted)'}}>{l.source?.name||'—'}</td>
                        <td className="py-3 px-3"><span className="text-[11px] font-bold px-2.5 py-1 rounded-lg text-white" style={{background:l.status?.color||'#6b7280'}}>{l.status?.name||'—'}</span></td>
                        <td className="py-3 px-3 text-xs" style={{color:'var(--text-muted)'}}>{l.assigned_user?.name||'Unassigned'}</td>
                        <td className="py-3 px-3">
                          <button onClick={e=>{e.stopPropagation();const r=e.currentTarget.getBoundingClientRect();setMenuPos({top:r.bottom+6,right:window.innerWidth-r.right});setOpenMenu(openMenu===l.id?null:l.id)}} className="w-7 h-7 rounded-lg flex items-center justify-center hover:bg-[rgba(124,58,237,0.08)]"><MoreVertical size={14} style={{color:'var(--text-muted)'}}/></button>
                          {openMenu===l.id && createPortal(
                            <div className="w-40 rounded-2xl p-1.5 z-[9998] shadow-2xl" style={{position:'fixed',top:menuPos?.top,right:menuPos?.right,background:'var(--bg-card)',border:'1px solid var(--border)'}} onClick={e=>e.stopPropagation()}>
                              <button onClick={()=>{setOpenMenu(null);navigate(`/app/sales/leads/${l.id}`)}} className="w-full flex items-center gap-2 px-3 py-2 rounded-xl text-xs font-semibold hover:bg-[rgba(124,58,237,0.06)]" style={{color:'var(--text-h)'}}><Eye size={12}/>View</button>
                              {!l.lost && <button onClick={()=>handleAction('lost',l)} className="w-full flex items-center gap-2 px-3 py-2 rounded-xl text-xs font-semibold hover:bg-[rgba(239,68,68,0.06)]" style={{color:'#f87171'}}><XCircle size={12}/>Mark Lost</button>}
                              {(l.lost||l.junk) && <button onClick={()=>handleAction('restore',l)} className="w-full flex items-center gap-2 px-3 py-2 rounded-xl text-xs font-semibold hover:bg-[rgba(16,185,129,0.06)]" style={{color:'#10b981'}}><RotateCcw size={12}/>Restore</button>}
                              <button onClick={()=>handleAction('delete',l)} className="w-full flex items-center gap-2 px-3 py-2 rounded-xl text-xs font-semibold hover:bg-[rgba(239,68,68,0.06)]" style={{color:'#f87171'}}><Trash2 size={12}/>Delete</button>
                            </div>, document.body
                          )}
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
          </div>
        )}

        {/* Kanban View */}
        {view === 'kanban' && kanban === null && (
          <div className="flex gap-4 overflow-x-auto pb-4">
            {[1,2,3,4].map(i => (
              <div key={i} className="skeleton flex-shrink-0 w-72 rounded-2xl" style={{height:320,background:'var(--border)'}}/>
            ))}
          </div>
        )}

        {/* The board is built from pipeline statuses, so with none defined it has
            nothing to draw. It used to render an empty <div> here, which read as a
            broken page — say what's missing and offer the fix.

            Keyed on real stages, not on the column count: the API prepends a
            synthetic "Unassigned" column when leads have no status, so a workspace
            with zero stages still returns one column and would otherwise show a
            lone dashed box with no explanation. This card renders ABOVE that
            column rather than replacing it, so those leads stay reachable. */}
        {view === 'kanban' && kanban && !kanban.some(c => c.id != null) && (
          <div className="card-3d text-center mb-4" style={{padding:'48px 24px'}}>
            <LayoutGrid size={28} className="mx-auto mb-3" style={{color:'var(--text-muted)'}}/>
            <p className="font-bold text-sm mb-1" style={{color:'var(--text-h)'}}>No pipeline stages yet</p>
            <p className="text-xs mb-4 max-w-sm mx-auto" style={{color:'var(--text-muted)'}}>
              The board shows one column per lead status. Add your stages — for example New,
              Contacted, Qualified, Won — and your leads will appear here.
            </p>
            <button onClick={restoreStages} disabled={restoring} className="btn-primary text-xs">
              {restoring ? 'Creating stages…' : 'Create default stages'}
            </button>
          </div>
        )}

        {view === 'kanban' && kanban?.length > 0 && (
          <div className="flex gap-4 overflow-x-auto pb-4" style={{minHeight:400}}>
            {kanban.map(col => (
              // id is null for the synthetic "Unassigned" column the API prepends
              // for leads that have no status — dashed border marks it as not a
              // real pipeline stage.
              <div key={col.id ?? 'unassigned'} className="flex-shrink-0 w-72 rounded-2xl p-3"
                style={{background:'var(--bg-input)',border:col.id==null?'1px dashed var(--border)':'1px solid var(--border)'}}>
                <div className="flex items-center justify-between mb-3">
                  <div className="flex items-center gap-2">
                    <div className="w-3 h-3 rounded-full" style={{background:col.color}}/>
                    <span className="text-sm font-bold" style={{color:'var(--text-h)'}}>{col.name}</span>
                    <span className="text-[10px] font-bold px-1.5 py-0.5 rounded-md" style={{background:`${col.color}20`,color:col.color}}>{col.count}</span>
                  </div>
                  {col.total_value > 0 && (
                    <span className="text-[10px] font-semibold" style={{color:'var(--text-muted)'}}>₹{(col.total_value/1000).toFixed(0)}K</span>
                  )}
                </div>
                {col.id == null && (
                  <p className="text-[10px] mb-2 px-1" style={{color:'var(--text-muted)'}}>
                    No status set — open a lead to assign one.
                  </p>
                )}
                <div className="space-y-2">
                  {(col.leads ?? []).map(l => {
                    const TI = TEMP_ICON[l.lead_temperature] || Snowflake
                    return (
                      <div key={l.id} onClick={()=>navigate(`/app/sales/leads/${l.id}`)} className="p-3 rounded-xl cursor-pointer transition-all hover:scale-[1.01]" style={{background:'var(--bg-card)',border:'1px solid var(--border)',boxShadow:'0 2px 8px rgba(0,0,0,0.06)'}}>
                        <p className="text-sm font-bold truncate" style={{color:'var(--text-h)'}}>{l.name}</p>
                        {l.company && <p className="text-[11px] truncate" style={{color:'var(--text-muted)'}}>{l.company}</p>}
                        <div className="flex items-center justify-between mt-2">
                          {l.lead_value>0 && <span className="text-[10px] font-bold px-2 py-0.5 rounded-md" style={{background:'rgba(16,185,129,0.1)',color:'#10b981'}}>₹{Number(l.lead_value).toLocaleString()}</span>}
                          <span className="inline-flex items-center gap-0.5 text-[10px] font-bold" style={{color:TEMP_COLOR[l.lead_temperature]}}><TI size={10}/>{l.lead_score}</span>
                        </div>
                        <div className="flex items-center justify-between mt-2">
                          <WinProbabilityBadge lead={l} />
                          {l.assigned_user && <span className="text-[10px] font-semibold px-1.5 py-0.5 rounded-md" style={{background:'rgba(124,58,237,0.1)',color:'#a78bfa'}}>{l.assigned_user.name.split(' ')[0]}</span>}
                        </div>
                      </div>
                    )
                  })}
                  {(col.leads ?? []).length===0 && <p className="text-center text-[11px] py-6" style={{color:'var(--text-muted)'}}>No leads</p>}
                </div>
              </div>
            ))}
          </div>
        )}

      </div>

      {/* Create Lead Drawer */}
      {showDrawer && (
        <>
          <div className="drawer-backdrop"/>
          <div className="drawer-panel" style={{width:'min(820px,95vw)'}}>
            <div className="drawer-header">
              <div className="flex items-center gap-2.5">
                <div className="w-8 h-8 rounded-xl flex items-center justify-center" style={{background:'linear-gradient(135deg,#9f67ff,#7C3AED)',boxShadow:'0 4px 12px rgba(124,58,237,0.4)'}}><UserPlus size={14} className="text-white"/></div>
                <div><h2 className="font-black text-lg" style={{color:'var(--text-h)'}}>New Lead</h2><p className="text-xs" style={{color:'var(--text-muted)'}}>Add a potential customer to your pipeline</p></div>
              </div>
              <button onClick={()=>setShowDrawer(false)} className="w-9 h-9 rounded-xl flex items-center justify-center hover:bg-[rgba(239,68,68,0.08)]" style={{border:'1px solid var(--border)'}}><X size={16} style={{color:'var(--text-muted)'}}/></button>
            </div>
            <div className="drawer-body">
              {/* Basic */}
              <div><p className="label-caps mb-4" style={{color:'#a78bfa'}}>Contact Information</p>
                <div className="space-y-3">
                  <div className="grid grid-cols-2 gap-3">
                    <div><label className="label">Name *</label><input className="input-3d text-sm" placeholder="Lead name" value={form.name} onChange={e=>sf('name',e.target.value)}/></div>
                    <div><label className="label">Company</label><input className="input-3d text-sm" placeholder="Company" value={form.company} onChange={e=>sf('company',e.target.value)}/></div>
                  </div>
                  <div className="grid grid-cols-2 gap-3">
                    <div><label className="label">Email</label><input className="input-3d text-sm" placeholder="email@example.com" value={form.email} onChange={e=>sf('email',e.target.value)}/></div>
                    <div><label className="label">Phone</label><input className="input-3d text-sm" placeholder="+91 ..." value={form.phone} onChange={e=>sf('phone',e.target.value)}/></div>
                  </div>
                  <div className="grid grid-cols-2 gap-3">
                    <div><label className="label">Title</label><input className="input-3d text-sm" placeholder="Position" value={form.title} onChange={e=>sf('title',e.target.value)}/></div>
                    <div><label className="label">Website</label><input className="input-3d text-sm" placeholder="https://..." value={form.website} onChange={e=>sf('website',e.target.value)}/></div>
                  </div>
                </div>
              </div>
              {/* Pipeline */}
              <div className="mt-6"><p className="label-caps mb-4" style={{color:'#a78bfa'}}>Pipeline Details</p>
                <div className="space-y-3">
                  <div className="grid grid-cols-3 gap-3">
                    <div>
                      <label className="label">Source</label>
                      {/* Free text rather than a dropdown + "add" button: typing is
                          faster than picking, and the backend matches the name
                          against existing sources (case-insensitively) so this
                          doesn't spawn duplicates. The datalist just offers what's
                          already been used — it isn't a required choice. */}
                      <input className="input-3d text-sm" list="lead-source-options" placeholder="e.g. Referral"
                        value={form.source} onChange={e=>sf('source',e.target.value)}/>
                      <datalist id="lead-source-options">
                        {sources.map(s=><option key={s.id} value={s.name}/>)}
                      </datalist>
                    </div>
                    <div><label className="label">Status</label><select className="input-3d text-sm" value={form.status_id} onChange={e=>sf('status_id',e.target.value)}><option value="">Default</option>{statuses.map(s=><option key={s.id} value={s.id}>{s.name}</option>)}</select></div>
                    <div><label className="label">Lead Value (₹)</label><input type="number" className="input-3d text-sm" placeholder="0" value={form.lead_value} onChange={e=>sf('lead_value',e.target.value)}/></div>
                  </div>
                  <div className="grid grid-cols-3 gap-3">
                    {/* assigned_to was already in the payload and validated server-side,
                        but the form had no control for it — leads could only be assigned
                        afterwards from the row menu. */}
                    <div><label className="label">Assign To</label>
                      <select className="input-3d text-sm" value={form.assigned_to} onChange={e=>sf('assigned_to',e.target.value)}>
                        <option value="">Unassigned</option>
                        {staff.map(u=><option key={u.id} value={u.id}>{u.name}</option>)}
                      </select>
                    </div>
                  </div>
                  <div><label className="label">Tags</label><input className="input-3d text-sm" placeholder="Comma-separated tags" value={form.tags} onChange={e=>sf('tags',e.target.value)}/></div>
                  <div><label className="label">Description</label><RichTextEditor value={form.description} onChange={v => sf('description', v)} placeholder="Notes about this lead…" minHeight={110} /></div>
                </div>
              </div>
              {/* Business / Qualification */}
              <div className="mt-6"><p className="label-caps mb-4" style={{color:'#a78bfa'}}>Business / Qualification</p>
                <div className="space-y-3">
                  <div className="grid grid-cols-2 gap-3">
                  </div>
                  <div className="grid grid-cols-2 gap-3">
                    <div><label className="label">Industry</label><input className="input-3d text-sm" placeholder="e.g. Textiles" value={form.industry} onChange={e=>sf('industry',e.target.value)}/></div>
                    <div><label className="label">Campaign</label><input className="input-3d text-sm" placeholder="e.g. Q3 Outreach" value={form.campaign} onChange={e=>sf('campaign',e.target.value)}/></div>
                  </div>
                  <div className="grid grid-cols-2 gap-3">
                    <div><label className="label">Priority</label><select className="input-3d text-sm" value={form.priority} onChange={e=>sf('priority',e.target.value)}><option value="low">Low</option><option value="medium">Medium</option><option value="high">High</option></select></div>
                    <div><label className="label">Expected Close Date</label><input type="date" className="input-3d text-sm" value={form.expected_close_date} onChange={e=>sf('expected_close_date',e.target.value)}/></div>
                  </div>
                </div>
              </div>
              {/* Referral */}
              <div className="mt-6"><p className="label-caps mb-4" style={{color:'#a78bfa'}}>Referral</p>
                <div className="grid grid-cols-3 gap-3">
                  <div><label className="label">Type</label><select className="input-3d text-sm" value={form.referral_type} onChange={e=>sf('referral_type',e.target.value)}><option value="none">None</option><option value="percentage">Percentage</option><option value="fixed">Fixed Amount</option></select></div>
                  {form.referral_type!=='none' && <div><label className="label">{form.referral_type==='percentage'?'%':'₹ Amount'}</label><input type="number" className="input-3d text-sm" value={form.referral_value} onChange={e=>sf('referral_value',e.target.value)}/></div>}
                  {form.referral_type!=='none' && <div><label className="label">Referred By</label><input className="input-3d text-sm" placeholder="Name" value={form.referral_contact} onChange={e=>sf('referral_contact',e.target.value)}/></div>}
                </div>
              </div>
            </div>
            <div className="drawer-footer">
              <button onClick={()=>setShowDrawer(false)} className="flex-1 py-3 rounded-2xl text-sm font-bold" style={{background:'var(--bg-input)',color:'var(--text-muted)',border:'1px solid var(--border)'}}>Cancel</button>
              <button onClick={handleCreate} className="flex-[2] py-3 rounded-2xl text-sm font-bold text-white" style={{background:'linear-gradient(135deg,#9f67ff,#7C3AED)',boxShadow:'0 6px 20px rgba(124,58,237,0.4)'}}>Create Lead</button>
            </div>
          </div>
        </>
      )}
    </>
  )
}
