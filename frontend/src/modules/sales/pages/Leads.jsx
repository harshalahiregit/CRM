import { useState, useEffect, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { salesApi } from '@/services/salesApi'
import WinProbabilityBadge from '../components/WinProbabilityBadge'
import {
  Plus, Search, MoreVertical, X, UserPlus, Flame, Thermometer, Snowflake,
  Eye, Trash2, XCircle, RotateCcw, TrendingUp, Users, Target, DollarSign,
  LayoutGrid, List, ChevronDown
} from 'lucide-react'

const TEMP_ICON = { Hot: Flame, Warm: Thermometer, Cold: Snowflake }
const TEMP_COLOR = { Hot: '#ef4444', Warm: '#f59e0b', Cold: '#3b82f6' }

export default function Leads() {
  const navigate = useNavigate()
  const [data, setData] = useState([])
  const [statuses, setStatuses] = useState([])
  const [sources, setSources] = useState([])
  const [summary, setSummary] = useState(null)
  const [loading, setLoading] = useState(true)
  const [view, setView] = useState('table')
  const [kanban, setKanban] = useState([])
  const [filter, setFilter] = useState('all')
  const [search, setSearch] = useState('')
  const [toast, setToast] = useState(null)
  const [showDrawer, setShowDrawer] = useState(false)
  const [openMenu, setOpenMenu] = useState(null)
  const [selected, setSelected] = useState([])
  const [form, setForm] = useState({ name:'', email:'', phone:'', company:'', title:'', website:'', pan:'', gst:'', industry:'', campaign:'', priority:'medium', expected_close_date:'', description:'', lead_value:'', source_id:'', status_id:'', assigned_to:'', tags:'', address:'', city:'', state:'', country:'', zip:'', referral_type:'none', referral_value:'', referral_contact:'' })

  const showToast = (msg, type='success') => { setToast({msg,type}); setTimeout(()=>setToast(null),3000) }
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
      if (view === 'kanban') { const k = await salesApi.leads.kanban(); setKanban(k) }
    } catch(e) { showToast(e.message,'error') }
    setLoading(false)
  }, [filter, view])

  useEffect(() => { load() }, [load])

  const handleCreate = async () => {
    if (!form.name.trim()) return showToast('Name is required','error')
    try {
      await salesApi.leads.create(form)
      showToast('Lead created')
      setShowDrawer(false)
      setForm({ name:'', email:'', phone:'', company:'', title:'', website:'', pan:'', gst:'', industry:'', campaign:'', priority:'medium', expected_close_date:'', description:'', lead_value:'', source_id:'', status_id:'', assigned_to:'', tags:'', address:'', city:'', state:'', country:'', zip:'', referral_type:'none', referral_value:'', referral_contact:'' })
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
      {toast && <div className="fixed top-5 right-5 z-[9999] px-5 py-3 rounded-2xl text-sm font-semibold text-white shadow-2xl" style={{background:toast.type==='success'?'linear-gradient(135deg,#10b981,#059669)':'linear-gradient(135deg,#f87171,#ef4444)'}}>{toast.msg}</div>}

      <div className="space-y-6 animate-[tiltIn_0.35s_ease_forwards]" onClick={()=>setOpenMenu(null)}>

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

        {/* Controls */}
        <div className="flex items-center justify-between flex-wrap gap-3">
          <div className="flex items-center gap-2 flex-wrap">
            {[{k:'table',I:List},{k:'kanban',I:LayoutGrid}].map(({k,I})=>(
              <button key={k} onClick={()=>setView(k)} className="px-3 py-2 rounded-xl text-xs font-bold flex items-center gap-1.5 transition-all" style={{background:view===k?'linear-gradient(135deg,#9f67ff,#7C3AED)':'var(--bg-input)',color:view===k?'#fff':'var(--text-muted)',border:`1px solid ${view===k?'transparent':'var(--border)'}`}}>
                <I size={13}/>{k==='table'?'Table':'Kanban'}
              </button>
            ))}
            <div className="flex gap-1 ml-2">
              <button onClick={()=>setFilter('all')} className="px-3 py-1.5 rounded-xl text-[11px] font-bold transition-all" style={{background:filter==='all'?'linear-gradient(135deg,#9f67ff,#7C3AED)':'var(--bg-input)',color:filter==='all'?'#fff':'var(--text-muted)',border:'1px solid var(--border)'}}>All</button>
              {statuses.map(s=>(
                <button key={s.id} onClick={()=>setFilter(s.id)} className="px-3 py-1.5 rounded-xl text-[11px] font-bold transition-all" style={{background:filter===s.id?s.color:'var(--bg-input)',color:filter===s.id?'#fff':'var(--text-muted)',border:'1px solid var(--border)'}}>{s.name}</button>
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
                        <td className="py-3 px-3 relative">
                          <button onClick={e=>{e.stopPropagation();setOpenMenu(openMenu===l.id?null:l.id)}} className="w-7 h-7 rounded-lg flex items-center justify-center hover:bg-[rgba(124,58,237,0.08)]"><MoreVertical size={14} style={{color:'var(--text-muted)'}}/></button>
                          {openMenu===l.id && (
                            <div className="absolute right-0 top-full mt-1 w-40 rounded-2xl p-1.5 z-50 shadow-2xl" style={{background:'var(--bg-card)',border:'1px solid var(--border)'}}>
                              <button onClick={()=>{setOpenMenu(null);navigate(`/app/sales/leads/${l.id}`)}} className="w-full flex items-center gap-2 px-3 py-2 rounded-xl text-xs font-semibold hover:bg-[rgba(124,58,237,0.06)]" style={{color:'var(--text-h)'}}><Eye size={12}/>View</button>
                              {!l.lost && <button onClick={()=>handleAction('lost',l)} className="w-full flex items-center gap-2 px-3 py-2 rounded-xl text-xs font-semibold hover:bg-[rgba(239,68,68,0.06)]" style={{color:'#f87171'}}><XCircle size={12}/>Mark Lost</button>}
                              {(l.lost||l.junk) && <button onClick={()=>handleAction('restore',l)} className="w-full flex items-center gap-2 px-3 py-2 rounded-xl text-xs font-semibold hover:bg-[rgba(16,185,129,0.06)]" style={{color:'#10b981'}}><RotateCcw size={12}/>Restore</button>}
                              <button onClick={()=>handleAction('delete',l)} className="w-full flex items-center gap-2 px-3 py-2 rounded-xl text-xs font-semibold hover:bg-[rgba(239,68,68,0.06)]" style={{color:'#f87171'}}><Trash2 size={12}/>Delete</button>
                            </div>
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
        {view === 'kanban' && (
          <div className="flex gap-4 overflow-x-auto pb-4" style={{minHeight:400}}>
            {kanban.map(col => (
              <div key={col.id} className="flex-shrink-0 w-72 rounded-2xl p-3" style={{background:'var(--bg-input)',border:'1px solid var(--border)'}}>
                <div className="flex items-center justify-between mb-3">
                  <div className="flex items-center gap-2">
                    <div className="w-3 h-3 rounded-full" style={{background:col.color}}/>
                    <span className="text-sm font-bold" style={{color:'var(--text-h)'}}>{col.name}</span>
                    <span className="text-[10px] font-bold px-1.5 py-0.5 rounded-md" style={{background:`${col.color}20`,color:col.color}}>{col.count}</span>
                  </div>
                  <span className="text-[10px] font-semibold" style={{color:'var(--text-muted)'}}>₹{(col.total_value/1000).toFixed(0)}K</span>
                </div>
                <div className="space-y-2">
                  {col.leads.map(l => {
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
                  {col.leads.length===0 && <p className="text-center text-[11px] py-6" style={{color:'var(--text-muted)'}}>No leads</p>}
                </div>
              </div>
            ))}
          </div>
        )}

      </div>

      {/* Create Lead Drawer */}
      {showDrawer && (
        <>
          <div className="drawer-backdrop" onClick={()=>setShowDrawer(false)}/>
          <div className="drawer-panel" style={{width:'min(580px,95vw)'}}>
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
                    <div><label className="label">Source</label><select className="input-3d text-sm" value={form.source_id} onChange={e=>sf('source_id',e.target.value)}><option value="">Select…</option>{sources.map(s=><option key={s.id} value={s.id}>{s.name}</option>)}</select></div>
                    <div><label className="label">Status</label><select className="input-3d text-sm" value={form.status_id} onChange={e=>sf('status_id',e.target.value)}><option value="">Default</option>{statuses.map(s=><option key={s.id} value={s.id}>{s.name}</option>)}</select></div>
                    <div><label className="label">Lead Value (₹)</label><input type="number" className="input-3d text-sm" placeholder="0" value={form.lead_value} onChange={e=>sf('lead_value',e.target.value)}/></div>
                  </div>
                  <div><label className="label">Tags</label><input className="input-3d text-sm" placeholder="Comma-separated tags" value={form.tags} onChange={e=>sf('tags',e.target.value)}/></div>
                  <div><label className="label">Description</label><textarea className="input-3d text-sm" rows={3} placeholder="Notes about this lead…" value={form.description} onChange={e=>sf('description',e.target.value)}/></div>
                </div>
              </div>
              {/* Business / Qualification */}
              <div className="mt-6"><p className="label-caps mb-4" style={{color:'#a78bfa'}}>Business / Qualification</p>
                <div className="space-y-3">
                  <div className="grid grid-cols-2 gap-3">
                    <div><label className="label">GST Number</label><input className="input-3d text-sm" placeholder="27AAECM1234K1Z5" value={form.gst} onChange={e=>sf('gst',e.target.value)}/></div>
                    <div><label className="label">PAN</label><input className="input-3d text-sm" placeholder="AAECM1234K" value={form.pan} onChange={e=>sf('pan',e.target.value)}/></div>
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
