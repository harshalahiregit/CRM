import { useState, useMemo } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { Search, Plus, X, Inbox, ChevronRight } from 'lucide-react'
import { helpdeskApi } from '@/services/helpdeskApi'

/* Clean, light, Freshdesk-style ticket inbox on the existing engine. */

const normalize = (raw) => (Array.isArray(raw) ? raw : raw?.data || [])
const PRI_DOT = { urgent: '#dc2626', high: '#ef4444', medium: '#f59e0b', low: '#10b981' }
const fmtAgo = (d) => {
  if (!d) return ''
  const s = Math.floor((Date.now() - new Date(d)) / 1000)
  if (s < 3600) return `${Math.max(1, Math.floor(s / 60))}m ago`
  if (s < 86400) return `${Math.floor(s / 3600)}h ago`
  return `${Math.floor(s / 86400)}d ago`
}

export default function TicketGrid() {
  const navigate = useNavigate()
  const qc = useQueryClient()
  const [filter, setFilter] = useState('all')
  const [q, setQ] = useState('')
  const [showNew, setShowNew] = useState(false)

  const { data: raw = [], isLoading } = useQuery({ queryKey: ['helpdesk-tickets'], queryFn: () => helpdeskApi.tickets.list() })
  const { data: settings } = useQuery({ queryKey: ['helpdesk-settings'], queryFn: helpdeskApi.settings.all })
  const tickets = normalize(raw)

  const statusColor = useMemo(() => {
    const m = {}; (settings?.statuses || []).forEach(s => { m[s.name] = s.color })
    return (name) => m[name] || '#64748b'
  }, [settings])

  const counts = useMemo(() => ({
    all: tickets.length,
    open: tickets.filter(t => t.status === 'open').length,
    'in-progress': tickets.filter(t => t.status === 'in-progress').length,
    closed: tickets.filter(t => t.status === 'closed').length,
    unassigned: tickets.filter(t => !t.assigned_to).length,
  }), [tickets])

  const filtered = useMemo(() => {
    const term = q.trim().toLowerCase()
    return tickets.filter(t => {
      if (filter === 'unassigned' && t.assigned_to) return false
      if (filter !== 'all' && filter !== 'unassigned' && t.status !== filter) return false
      if (term && !t.subject.toLowerCase().includes(term)) return false
      return true
    })
  }, [tickets, filter, q])

  const TABS = [
    ['all', 'All'], ['open', 'Open'], ['in-progress', 'In Progress'], ['closed', 'Closed'], ['unassigned', 'Unassigned'],
  ]

  return (
    <div className="-m-4 md:-m-6" style={{ background: '#f4f6fb', minHeight: 'calc(100vh - 120px)', color: '#16233d' }}>
      <div className="max-w-5xl mx-auto px-4 md:px-6 py-6">
        {/* Header */}
        <div className="flex items-center justify-between gap-3 mb-4">
          <div className="flex items-center gap-2.5">
            <span style={{ width: 38, height: 38, borderRadius: 11, background: '#e8eeff', color: '#3b6fed', display: 'flex', alignItems: 'center', justifyContent: 'center' }}><Inbox size={20} /></span>
            <div>
              <h1 style={{ fontSize: 19, fontWeight: 800, letterSpacing: '-0.02em', color: '#16233d' }}>Tickets</h1>
              <p style={{ fontSize: 12.5, color: '#7a879e' }}>{filtered.length} of {tickets.length}</p>
            </div>
          </div>
          <div className="flex items-center gap-2">
            <div className="relative">
              <Search size={16} style={{ position: 'absolute', left: 12, top: '50%', transform: 'translateY(-50%)', color: '#9aa4ba' }} />
              <input value={q} onChange={e => setQ(e.target.value)} placeholder="Search tickets…"
                style={{ padding: '9px 12px 9px 34px', borderRadius: 10, border: '1px solid #e1e6f0', background: '#fff', fontSize: 13.5, outline: 'none', width: 220, color: '#16233d' }} />
            </div>
            <button onClick={() => setShowNew(true)} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 16px', borderRadius: 10, background: '#3b6fed', color: '#fff', fontSize: 13.5, fontWeight: 600, boxShadow: '0 4px 12px rgba(59,111,237,0.3)' }}>
              <Plus size={16} /> New ticket
            </button>
          </div>
        </div>

        {/* Filter tabs */}
        <div className="flex items-center gap-1.5 mb-3 flex-wrap">
          {TABS.map(([key, label]) => (
            <button key={key} onClick={() => setFilter(key)}
              style={{ padding: '6px 13px', borderRadius: 999, fontSize: 13, fontWeight: 600,
                background: filter === key ? '#3b6fed' : '#fff', color: filter === key ? '#fff' : '#5a6b8c',
                border: `1px solid ${filter === key ? '#3b6fed' : '#e1e6f0'}` }}>
              {label} <span style={{ opacity: 0.7 }}>{counts[key] ?? 0}</span>
            </button>
          ))}
        </div>

        {/* List */}
        <div style={{ background: '#fff', border: '1px solid #e7eaf2', borderRadius: 14, overflow: 'hidden', boxShadow: '0 1px 2px rgba(20,30,60,0.04)' }}>
          {isLoading && [1, 2, 3, 4, 5].map(i => <div key={i} style={{ height: 64, borderBottom: '1px solid #f0f2f7' }} className="animate-pulse" />)}
          {!isLoading && filtered.length === 0 && <p style={{ padding: '40px', textAlign: 'center', color: '#9aa4ba', fontSize: 14 }}>No tickets match.</p>}
          {!isLoading && filtered.map((t, i) => (
            <button key={t.id} onClick={() => navigate(`/app/helpdesk/tickets/${t.id}`)}
              style={{ width: '100%', display: 'flex', alignItems: 'center', gap: 14, padding: '13px 18px', textAlign: 'left', borderBottom: i < filtered.length - 1 ? '1px solid #f0f2f7' : 'none', background: '#fff' }}
              onMouseEnter={e => e.currentTarget.style.background = '#f7f9fd'}
              onMouseLeave={e => e.currentTarget.style.background = '#fff'}>
              <span title={t.priority} style={{ width: 9, height: 9, borderRadius: 999, background: PRI_DOT[t.priority] || '#cbd5e1', flexShrink: 0 }} />
              <div style={{ flex: 1, minWidth: 0 }}>
                <p style={{ fontSize: 14.5, fontWeight: 600, color: '#16233d', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{t.subject}</p>
                <p style={{ fontSize: 12.5, color: '#8a93a8', marginTop: 2 }}>
                  #{t.id}
                  {t.requester_name ? ` · ${t.requester_name}` : ''}
                  {t.source === 'widget' ? ' · via widget' : ''}
                  {` · ${fmtAgo(t.created_at)}`}
                </p>
              </div>
              <span style={{ fontSize: 11, fontWeight: 700, textTransform: 'capitalize', padding: '4px 10px', borderRadius: 999, color: statusColor(t.status), background: `${statusColor(t.status)}18` }}>
                {String(t.status).replace('-', ' ')}
              </span>
              <span style={{ fontSize: 12.5, color: '#5a6b8c', width: 96, textAlign: 'right', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                {t.assignee?.name || 'Unassigned'}
              </span>
              <ChevronRight size={16} style={{ color: '#c2cbdc', flexShrink: 0 }} />
            </button>
          ))}
        </div>
      </div>

      {showNew && <NewTicketModal settings={settings} onClose={() => setShowNew(false)} onCreated={(id) => { qc.invalidateQueries({ queryKey: ['helpdesk-tickets'] }); setShowNew(false); navigate(`/app/helpdesk/tickets/${id}`) }} />}
    </div>
  )
}

function NewTicketModal({ settings, onClose, onCreated }) {
  const [form, setForm] = useState({ subject: '', description: '', priority: 'medium', status: 'open', department_id: '', requester_name: '', requester_email: '' })
  const create = useMutation({
    mutationFn: () => {
      const payload = { ...form }
      Object.keys(payload).forEach(k => payload[k] === '' && delete payload[k])
      return helpdeskApi.tickets.create(payload)
    },
    onSuccess: (t) => onCreated(t.id),
  })
  const set = (k, v) => setForm(f => ({ ...f, [k]: v }))
  const inp = { width: '100%', padding: '10px 12px', borderRadius: 9, border: '1px solid #e1e6f0', fontSize: 14, outline: 'none', color: '#16233d', background: '#fff' }
  const lbl = { fontSize: 12, fontWeight: 600, color: '#5a6b8c', display: 'block', marginBottom: 5 }

  return (
    <div style={{ position: 'fixed', inset: 0, zIndex: 60, background: 'rgba(20,30,60,0.45)', display: 'flex', alignItems: 'flex-start', justifyContent: 'center', paddingTop: '8vh' }} onClick={onClose}>
      <div style={{ width: '100%', maxWidth: 480, background: '#fff', borderRadius: 16, padding: 24, color: '#16233d' }} onClick={e => e.stopPropagation()}>
        <div className="flex items-center justify-between" style={{ marginBottom: 16 }}>
          <h2 style={{ fontSize: 17, fontWeight: 800, color: '#16233d' }}>New ticket</h2>
          <button onClick={onClose}><X size={18} style={{ color: '#9aa4ba' }} /></button>
        </div>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
          <div><label style={lbl}>Subject *</label><input style={inp} value={form.subject} onChange={e => set('subject', e.target.value)} /></div>
          <div><label style={lbl}>Description</label><textarea style={{ ...inp, minHeight: 72, resize: 'vertical' }} value={form.description} onChange={e => set('description', e.target.value)} /></div>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
            <div><label style={lbl}>Priority</label>
              <select style={inp} value={form.priority} onChange={e => set('priority', e.target.value)}>
                {(settings?.priorities || [{ name: 'medium' }]).map(p => <option key={p.name} value={p.name}>{p.name}</option>)}
              </select>
            </div>
            <div><label style={lbl}>Status</label>
              <select style={inp} value={form.status} onChange={e => set('status', e.target.value)}>
                {(settings?.statuses || [{ name: 'open' }]).filter(s => s.name !== 'merged').map(s => <option key={s.name} value={s.name}>{s.name}</option>)}
              </select>
            </div>
          </div>
          <div><label style={lbl}>Department</label>
            <select style={inp} value={form.department_id} onChange={e => set('department_id', e.target.value)}>
              <option value="">— none —</option>
              {(settings?.departments || []).map(d => <option key={d.id} value={d.id}>{d.name}</option>)}
            </select>
          </div>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
            <div><label style={lbl}>Requester name</label><input style={inp} value={form.requester_name} onChange={e => set('requester_name', e.target.value)} /></div>
            <div><label style={lbl}>Requester email</label><input style={inp} value={form.requester_email} onChange={e => set('requester_email', e.target.value)} /></div>
          </div>
          {create.isError && <p style={{ fontSize: 12.5, color: '#dc2626' }}>{create.error?.message}</p>}
          <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 4 }}>
            <button onClick={onClose} style={{ padding: '9px 16px', borderRadius: 9, border: '1px solid #e1e6f0', fontSize: 13.5, fontWeight: 600, color: '#5a6b8c', background: '#fff' }}>Cancel</button>
            <button disabled={!form.subject.trim() || create.isPending} onClick={() => create.mutate()}
              style={{ padding: '9px 18px', borderRadius: 9, background: '#3b6fed', color: '#fff', fontSize: 13.5, fontWeight: 600, opacity: (!form.subject.trim() || create.isPending) ? 0.5 : 1 }}>
              {create.isPending ? 'Creating…' : 'Create ticket'}
            </button>
          </div>
        </div>
      </div>
    </div>
  )
}
