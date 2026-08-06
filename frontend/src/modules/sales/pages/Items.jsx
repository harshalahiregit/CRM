import { useState, useEffect, useRef } from 'react'
import { Plus, Search, Edit2, Copy, Trash2, X, LayoutGrid, List, Upload, CheckCircle, Tag } from 'lucide-react'
import { salesApi } from '@/services/salesApi'
import { useToast } from '@/hooks/useToast'

const fmt = v => '₹' + Number(v||0).toLocaleString('en-IN')
const CATEGORIES = ['All','Development','Design','Infrastructure','Marketing','Analytics','Consulting','Support']
const UNITS = ['project','monthly','hours','days','pieces','kg','Year']
const TAXES = [0,5,12,18,28]

const catColor = cat =>
  cat==='Development'    ? {bg:'rgba(124,58,237,0.1)', color:'#a78bfa'} :
  cat==='Design'         ? {bg:'rgba(245,158,11,0.1)', color:'#fbbf24'} :
  cat==='Infrastructure' ? {bg:'rgba(59,130,246,0.1)', color:'#3b82f6'} :
  cat==='Marketing'      ? {bg:'rgba(236,72,153,0.1)', color:'#f472b6'} :
  cat==='Analytics'      ? {bg:'rgba(20,184,166,0.1)', color:'#2dd4bf'} :
  cat==='Consulting'     ? {bg:'rgba(249,115,22,0.1)', color:'#fb923c'} :
                           {bg:'rgba(16,185,129,0.1)', color:'#10b981'}

const EMPTY_FORM = { name:'', description:'', long_description:'', rate:'', unit:'project', tax_rate:18, tax_rate_2:0, category:'Development' }

export default function Items() {
  const csvRef = useRef(null)
  const [data, setData]         = useState([])
  const [loading, setLoading]   = useState(true)
  const [category, setCategory] = useState('All')
  const [search, setSearch]     = useState('')
  const [viewMode, setViewMode] = useState('grid')
  const [showDrawer, setShowDrawer] = useState(false)
  const [editing, setEditing]   = useState(null)
  const [form, setForm]         = useState(EMPTY_FORM)

  // Routed through the shared Toast so every module notifies identically
  // (and error toasts get the per-field validation detail + tip).
  const toast = useToast()
  const showToast = (msg, type = 'success') =>
    type === 'error' ? toast.error(msg) : type === 'info' ? toast.info(msg) : toast.success(msg)
  const sf = (k,v) => setForm(p=>({...p,[k]:v}))

  const loadData = () => {
    setLoading(true)
    salesApi.items.list({ category: category!=='All'?category:undefined, search:search||undefined })
      .then(d=>{setData(d);setLoading(false)})
  }
  useEffect(()=>{ loadData() },[category,search])

  const openCreate = () => { setEditing(null); setForm(EMPTY_FORM); setShowDrawer(true) }
  const openEdit = item => {
    setEditing(item)
    setForm({ name:item.name, description:item.description, long_description:item.long_description, rate:item.rate, unit:item.unit, tax_rate:item.tax_rate, tax_rate_2:item.tax_rate_2||0, category:item.category })
    setShowDrawer(true)
  }

  const handleSave = async () => {
    if(!form.name||!form.rate) return showToast('Name & rate required','error')
    if(editing) { await salesApi.items.update(editing.id,form); showToast('Item updated!') }
    else { await salesApi.items.create(form); showToast('Item created!') }
    setShowDrawer(false); loadData()
  }

  const handleCSV = e => {
    const file = e.target.files?.[0]
    if(!file) return
    showToast(`Imported items from "${file.name}"`)
    e.target.value = ''
  }

  return (
    <>
      <div className="space-y-6 animate-[tiltIn_0.35s_ease]">

      {/* Header */}
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div>
          <p className="label-caps mb-1">Sales & Revenue</p>
          <h1 className="font-black" style={{fontSize:'clamp(1.3rem,2vw,1.8rem)',color:'var(--text-h)',letterSpacing:'-0.02em'}}>
            Product & Service <span className="text-gradient">Catalog</span>
          </h1>
          <p className="text-xs mt-0.5" style={{color:'var(--text-muted)'}}>Reusable items for proposals, estimates & invoices</p>
        </div>
        <div className="flex items-center gap-2">
          {/* CSV Import */}
          <input ref={csvRef} type="file" accept=".csv" className="hidden" onChange={handleCSV}/>
          <button onClick={()=>csvRef.current?.click()}
            className="flex items-center gap-2 px-4 py-2.5 rounded-2xl text-sm font-semibold transition-all hover:scale-[1.02]"
            style={{background:'var(--bg-input)',color:'var(--text-muted)',border:'1px solid var(--border)'}}>
            <Upload size={14}/> Import CSV
          </button>
          {/* View Toggle */}
          <div className="flex rounded-2xl overflow-hidden p-1" style={{background:'var(--bg-input)',border:'1px solid var(--border)'}}>
            {[{k:'grid',I:LayoutGrid},{k:'list',I:List}].map(({k,I})=>(
              <button key={k} onClick={()=>setViewMode(k)} className="px-3 py-2 rounded-xl transition-all"
                style={{background:viewMode===k?'linear-gradient(135deg,#7C3AED,#5b21b6)':'transparent',color:viewMode===k?'#fff':'var(--text-muted)'}}>
                <I size={14}/>
              </button>
            ))}
          </div>
          <button onClick={openCreate} className="flex items-center gap-2 px-5 py-2.5 rounded-2xl text-sm font-bold text-white hover:scale-[1.03] transition-all"
            style={{background:'linear-gradient(135deg,#9f67ff,#7C3AED,#5b21b6)',boxShadow:'0 6px 20px rgba(124,58,237,0.45)'}}>
            <Plus size={15}/> New Item
          </button>
        </div>
      </div>

      {/* Category Filters + Search */}
      <div className="flex items-center gap-3 flex-wrap">
        <div className="flex gap-1.5 flex-wrap p-1 rounded-2xl" style={{background:'var(--bg-input)',border:'1px solid var(--border)'}}>
          {CATEGORIES.map(cat=>(
            <button key={cat} onClick={()=>setCategory(cat)} className="px-3 py-1.5 rounded-xl text-xs font-bold transition-all"
              style={{background:category===cat?'linear-gradient(135deg,#7C3AED,#5b21b6)':'transparent',color:category===cat?'#fff':'var(--text-muted)'}}>
              {cat}
            </button>
          ))}
        </div>
        <div className="flex-1 min-w-[200px] relative">
          <Search size={14} className="absolute left-3.5 top-1/2 -translate-y-1/2" style={{color:'var(--text-muted)'}}/>
          <input value={search} onChange={e=>setSearch(e.target.value)} placeholder="Search items…" className="input-3d text-sm pl-10 w-full"/>
        </div>
      </div>

      {/* Grid / List */}
      {loading ? (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
          {[1,2,3,4,5,6].map(i=><div key={i} className="skeleton h-40 rounded-2xl" style={{background:'var(--border)'}}/>)}
        </div>
      ) : viewMode==='grid' ? (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
          {data.map(item=>{
            const c=catColor(item.category)
            return (
              <div key={item.id} className="card-3d group relative overflow-hidden" style={{padding:'20px'}}>
                <div className="absolute -top-3 -right-3 w-16 h-16 rounded-full opacity-[0.06]" style={{background:c.color}}/>
                <div className="flex items-start justify-between mb-3">
                  <span className="px-2.5 py-1 rounded-xl text-[10px] font-bold" style={{background:c.bg,color:c.color}}>{item.category}</span>
                  <div className="flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                    <button onClick={()=>openEdit(item)} className="p-1.5 rounded-lg hover:bg-[rgba(124,58,237,0.08)] transition-colors"><Edit2 size={12} style={{color:'var(--text-muted)'}}/></button>
                    <button onClick={()=>showToast('Duplicated!')} className="p-1.5 rounded-lg hover:bg-[rgba(124,58,237,0.08)] transition-colors"><Copy size={12} style={{color:'var(--text-muted)'}}/></button>
                    <button onClick={()=>showToast('Deleted!','error')} className="p-1.5 rounded-lg hover:bg-[rgba(239,68,68,0.08)] transition-colors"><Trash2 size={12} style={{color:'#f87171'}}/></button>
                  </div>
                </div>
                <h3 className="font-bold text-sm mb-1" style={{color:'var(--text-h)'}}>{item.name}</h3>
                <p className="text-xs mb-3 line-clamp-2" style={{color:'var(--text-muted)'}}>{item.description}</p>
                <div className="flex items-end justify-between">
                  <div>
                    <p className="text-lg font-black" style={{color:'#a78bfa'}}>{fmt(item.rate)}</p>
                    <p className="text-[10px]" style={{color:'var(--text-muted)'}}>per {item.unit} · Tax {item.tax_rate}%{item.tax_rate_2?` + ${item.tax_rate_2}%`:''}</p>
                  </div>
                </div>
              </div>
            )
          })}
          {data.length===0 && <div className="col-span-3 py-12 text-center" style={{color:'var(--text-muted)'}}>No items found.</div>}
        </div>
      ) : (
        <div className="card-3d overflow-hidden" style={{padding:0}}>
          <div className="overflow-x-auto">
            <table className="w-full text-xs">
              <thead><tr style={{background:'rgba(124,58,237,0.04)',borderBottom:'1px solid var(--border)'}}>
                {['Name','Description','Category','Rate','Unit','Tax 1','Tax 2',''].map(h=><th key={h} className="py-3.5 px-4 text-left label-caps whitespace-nowrap">{h}</th>)}
              </tr></thead>
              <tbody>
                {data.map(item=>{
                  const c=catColor(item.category)
                  return (
                    <tr key={item.id} className="transition-colors" style={{borderBottom:'1px solid var(--border)'}}
                      onMouseEnter={e=>e.currentTarget.style.background='rgba(124,58,237,0.04)'}
                      onMouseLeave={e=>e.currentTarget.style.background='transparent'}>
                      <td className="py-3.5 px-4 font-bold" style={{color:'var(--text-h)'}}>{item.name}</td>
                      <td className="py-3.5 px-4 max-w-[200px]" style={{color:'var(--text-muted)'}}><span className="truncate block">{item.description}</span></td>
                      <td className="py-3.5 px-4"><span className="px-2.5 py-1 rounded-xl text-[10px] font-bold" style={{background:c.bg,color:c.color}}>{item.category}</span></td>
                      <td className="py-3.5 px-4 font-bold" style={{color:'#a78bfa'}}>{fmt(item.rate)}</td>
                      <td className="py-3.5 px-4" style={{color:'var(--text-muted)'}}>{item.unit}</td>
                      <td className="py-3.5 px-4" style={{color:'var(--text-muted)'}}>{item.tax_rate}%</td>
                      <td className="py-3.5 px-4" style={{color:'var(--text-muted)'}}>{item.tax_rate_2||0}%</td>
                      <td className="py-3.5 px-4">
                        <div className="flex gap-1">
                          <button onClick={()=>openEdit(item)} className="p-1.5 rounded-lg hover:bg-[rgba(124,58,237,0.08)] transition-colors"><Edit2 size={12} style={{color:'var(--text-muted)'}}/></button>
                          <button onClick={()=>showToast('Duplicated!')} className="p-1.5 rounded-lg hover:bg-[rgba(124,58,237,0.08)] transition-colors"><Copy size={12} style={{color:'var(--text-muted)'}}/></button>
                          <button onClick={()=>showToast('Deleted!','error')} className="p-1.5 rounded-lg hover:bg-[rgba(239,68,68,0.08)] transition-colors"><Trash2 size={12} style={{color:'#f87171'}}/></button>
                        </div>
                      </td>
                    </tr>
                  )
                })}
                {data.length===0 && <tr><td colSpan="8" className="py-12 text-center" style={{color:'var(--text-muted)'}}>No items found.</td></tr>}
              </tbody>
            </table>
          </div>
        </div>
      )}
      
      </div>

      {/* ── Side Drawer ── */}
      {showDrawer && (
        <>
          <div className="drawer-backdrop" />
          <div className="drawer-panel" style={{ width: 'min(820px, 95vw)' }}>
            <div className="drawer-header">
              <div>
                <h2 className="font-black text-lg" style={{ color: 'var(--text-h)', letterSpacing: '-0.02em' }}>{editing ? 'Edit Item' : 'New Item'}</h2>
                <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{editing ? 'Update catalog item details' : 'Add a new product or service to the catalog'}</p>
              </div>
              <button onClick={() => setShowDrawer(false)} className="w-9 h-9 rounded-xl flex items-center justify-center hover:bg-[rgba(239,68,68,0.08)] transition-colors" style={{ border: '1px solid var(--border)' }}>
                <X size={16} style={{ color: 'var(--text-muted)' }} />
              </button>
            </div>

            <div className="drawer-body">
              <div>
                <p className="label-caps mb-4" style={{ color: '#a78bfa' }}>Item Details</p>
                <div className="space-y-4">
                  <div>
                    <label className="label">Item / Service Name *</label>
                    <input className="input-3d text-sm" placeholder="e.g. Web Development" value={form.name} onChange={e => sf('name', e.target.value)} />
                  </div>
                  <div>
                    <label className="label">Short Description</label>
                    <input className="input-3d text-sm" placeholder="Brief one-line description" value={form.description} onChange={e => sf('description', e.target.value)} />
                  </div>
                  <div>
                    <label className="label">Long Description <span style={{ color: 'var(--text-muted)', fontWeight: 400, textTransform: 'none', letterSpacing: 'normal' }}>(shown on documents)</span></label>
                    <textarea className="input-3d text-sm resize-none" rows={3} placeholder="Detailed description visible on proposals, invoices…" value={form.long_description} onChange={e => sf('long_description', e.target.value)} />
                  </div>
                </div>
              </div>

              <div>
                <p className="label-caps mb-4" style={{ color: '#a78bfa' }}>Pricing</p>
                <div className="space-y-4">
                  <div className="grid grid-cols-2 gap-4">
                    <div>
                      <label className="label">Rate / Price *</label>
                      <input type="number" min="0" className="input-3d text-sm" placeholder="0.00" value={form.rate} onChange={e => sf('rate', e.target.value)} />
                    </div>
                    <div>
                      <label className="label">Unit of Measure</label>
                      <select className="input-3d text-sm" value={form.unit} onChange={e => sf('unit', e.target.value)}>
                        {UNITS.map(u => <option key={u} value={u}>{u}</option>)}
                      </select>
                    </div>
                  </div>
                  <div className="grid grid-cols-2 gap-4">
                    <div>
                      <label className="label">Tax 1 (Primary)</label>
                      <select className="input-3d text-sm" value={form.tax_rate} onChange={e => sf('tax_rate', Number(e.target.value))}>
                        {TAXES.map(t => <option key={t} value={t}>GST {t}%</option>)}
                      </select>
                    </div>
                    <div>
                      <label className="label">Tax 2 (Secondary)</label>
                      <select className="input-3d text-sm" value={form.tax_rate_2} onChange={e => sf('tax_rate_2', Number(e.target.value))}>
                        {TAXES.map(t => <option key={t} value={t}>{t === 0 ? 'None' : `${t}%`}</option>)}
                      </select>
                    </div>
                  </div>

                  {/* Tax preview */}
                  {(form.rate && (form.tax_rate || form.tax_rate_2)) ? (
                    <div className="p-3 rounded-xl" style={{ background: 'rgba(124,58,237,0.06)', border: '1px solid rgba(124,58,237,0.15)' }}>
                      <p className="text-xs font-bold mb-2" style={{ color: '#a78bfa' }}>Tax Preview (per unit)</p>
                      <div className="space-y-1 text-xs" style={{ color: 'var(--text-muted)' }}>
                        <div className="flex justify-between"><span>Base Rate</span><span className="font-semibold" style={{ color: 'var(--text-h)' }}>{fmt(form.rate)}</span></div>
                        {form.tax_rate > 0 && <div className="flex justify-between"><span>Tax 1 ({form.tax_rate}%)</span><span style={{ color: 'var(--text-h)' }}>{fmt(form.rate * form.tax_rate / 100)}</span></div>}
                        {form.tax_rate_2 > 0 && <div className="flex justify-between"><span>Tax 2 ({form.tax_rate_2}%)</span><span style={{ color: 'var(--text-h)' }}>{fmt(form.rate * form.tax_rate_2 / 100)}</span></div>}
                        <div className="flex justify-between pt-1 font-black text-sm" style={{ borderTop: '1px solid var(--border)', color: 'var(--text-h)' }}>
                          <span>Total</span><span style={{ color: '#a78bfa' }}>{fmt(Number(form.rate) * (1 + (form.tax_rate + form.tax_rate_2) / 100))}</span>
                        </div>
                      </div>
                    </div>
                  ) : null}

                  <div>
                    <label className="label">Category / Group</label>
                    <select className="input-3d text-sm" value={form.category} onChange={e => sf('category', e.target.value)}>
                      {CATEGORIES.filter(c => c !== 'All').map(c => <option key={c} value={c}>{c}</option>)}
                    </select>
                  </div>
                </div>
              </div>
            </div>

            <div className="drawer-footer">
              <button onClick={() => setShowDrawer(false)} className="flex-1 py-3 rounded-2xl text-sm font-semibold" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>Cancel</button>
              <button onClick={handleSave} className="flex-[2] py-3 rounded-2xl text-sm font-bold text-white hover:scale-[1.01] transition-all" style={{ background: 'linear-gradient(135deg,#9f67ff,#7C3AED,#5b21b6)', boxShadow: '0 6px 20px rgba(124,58,237,0.4)' }}>
                {editing ? 'Save Changes' : 'Create Item'}
              </button>
            </div>
          </div>
        </>
      )}
    </>
  )
}
