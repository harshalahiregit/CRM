import { useState, useEffect, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTheme } from '@/context/ThemeContext'
import {
  Bell, LayoutGrid, FileText, AlarmClock, Send, History as HistoryIcon,
  Search, X, Eye, CheckCheck, Check, ExternalLink, RefreshCw, Power, Pencil, Plus,
  Mail, MessageSquare, Sparkles, Clock, ShieldAlert,
} from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { HrLoading, HrEmpty } from '@/components/ui/HrState'
import { GRAD, priorityStyle, statusStyle, TYPE_LABEL, CHANNELS, timeAgo } from './ui'

const fmtDate = (d) => d ? new Date(d).toLocaleString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—'

const TABS = [
  { key: 'center',    label: 'Notification Center', icon: LayoutGrid },
  { key: 'templates', label: 'Templates',           icon: FileText },
  { key: 'rules',     label: 'Reminder Rules',       icon: AlarmClock },
  { key: 'queue',     label: 'Queue Monitor',        icon: Send },
  { key: 'history',   label: 'History',              icon: HistoryIcon },
]

export default function NotificationCenter() {
  useTheme()
  const [tab, setTab] = useState('center')
  const [toast, setToast] = useState(null)
  const [catalog, setCatalog] = useState({ modules: [], events: [], priorities: ['Info', 'Success', 'Warning', 'Critical'], escalation_ladder: [] })
  const showToast = (msg, type = 'success') => { setToast({ msg, type }); setTimeout(() => setToast(null), 3000) }

  useEffect(() => { hrApi.notifications.catalog().then(setCatalog).catch(() => {}) }, [])

  return (
    <div className="space-y-6 animate-[tiltIn_0.35s_ease_forwards]">
      {toast && <div className="fixed top-5 right-5 z-[9999] px-5 py-3 rounded-2xl text-sm font-semibold text-white shadow-2xl" style={{ background: toast.type === 'success' ? 'linear-gradient(135deg,#10b981,#059669)' : 'linear-gradient(135deg,#f87171,#ef4444)' }}>{toast.msg}</div>}

      <div className="flex items-center justify-between flex-wrap gap-3">
        <div>
          <p className="label-caps mb-1">HR Settings</p>
          <h1 className="font-black flex items-center gap-2" style={{ fontSize: 'clamp(1.3rem,2vw,1.7rem)', color: 'var(--text-h)', letterSpacing: '-0.02em' }}>
            <Bell size={22} style={{ color: '#a78bfa' }} /> Notification &amp; Reminder <span className="text-gradient">Engine</span>
          </h1>
        </div>
      </div>

      <div className="flex gap-1.5 flex-wrap">
        {TABS.map(t => {
          const active = tab === t.key
          return (
            <button key={t.key} onClick={() => setTab(t.key)} className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold transition-all"
              style={{ background: active ? GRAD : 'var(--bg-input)', color: active ? '#fff' : 'var(--text-muted)', border: active ? 'none' : '1px solid var(--border)' }}>
              <t.icon size={15} /> {t.label}
            </button>
          )
        })}
      </div>

      {tab === 'center' && <CenterTab catalog={catalog} showToast={showToast} />}
      {tab === 'templates' && <TemplatesTab catalog={catalog} showToast={showToast} />}
      {tab === 'rules' && <RulesTab catalog={catalog} showToast={showToast} />}
      {tab === 'queue' && <QueueTab showToast={showToast} />}
      {tab === 'history' && <CenterTab catalog={catalog} showToast={showToast} historyMode />}
    </div>
  )
}

/* ───────────────────────── Notification Center + History ───────────────────────── */
function CenterTab({ catalog, showToast, historyMode = false }) {
  const navigate = useNavigate()
  const [rows, setRows] = useState([])
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1, total: 0 })
  const [stats, setStats] = useState({ unread: 0, read: 0, critical: 0, overdue: 0, today: 0 })
  const [loading, setLoading] = useState(true)
  const [page, setPage] = useState(1)
  const [f, setF] = useState({ module: 'All', priority: 'All', is_read: 'All', notification_type: 'All', from: '', to: '', search: '' })
  const [drawer, setDrawer] = useState(null)

  const load = useCallback(() => {
    setLoading(true)
    const params = { page, per_page: 20 }
    Object.entries(f).forEach(([k, v]) => { if (v && v !== 'All') params[k] = v })
    Promise.all([hrApi.notifications.list(params), historyMode ? null : hrApi.notifications.stats()])
      .then(([list, s]) => { setRows(list.data || []); setMeta(list.meta || meta); if (s) setStats(s) })
      .catch(() => showToast('Failed to load notifications', 'error'))
      .finally(() => setLoading(false))
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [page, f, historyMode])
  useEffect(() => { load() }, [load])

  const openDrawer = async (n) => {
    try { const full = await hrApi.notifications.get(n.id); setDrawer(full) } catch { setDrawer(n) }
  }
  const markRead = async (n) => {
    try { await hrApi.notifications.markRead(n.id); load(); if (drawer?.id === n.id) setDrawer(d => ({ ...d, is_read: true })) } catch { showToast('Failed', 'error') }
  }
  const markAll = async () => { try { await hrApi.notifications.markAllRead(); showToast('All marked read'); load() } catch { showToast('Failed', 'error') } }
  const resend = async (n) => { try { const r = await hrApi.notifications.resend(n.id); showToast(r.ok ? 'Email re-queued & sent' : 'Resend attempted (see queue)'); } catch (e) { showToast(e.response?.data?.message || 'Resend failed', 'error') } }
  const openRecord = (n) => { if (n.action_url) navigate(n.action_url) }

  const KPIS = [
    { l: "Unread", v: stats.unread, c: '#7C3AED' },
    { l: 'Read', v: stats.read, c: '#10b981' },
    { l: 'Critical', v: stats.critical, c: '#ef4444' },
    { l: 'Overdue', v: stats.overdue, c: '#f59e0b' },
    { l: "Today's", v: stats.today, c: '#3b82f6' },
  ]
  const hasF = Object.entries(f).some(([k, v]) => v && v !== 'All')

  return (
    <div className="space-y-4">
      {!historyMode && (
        <div className="grid grid-cols-2 md:grid-cols-5 gap-4">
          {KPIS.map(k => <div key={k.l} className="kpi-3d"><p className="text-3xl font-black" style={{ color: k.c }}>{k.v}</p><p className="text-xs font-medium mt-1" style={{ color: 'var(--text-muted)' }}>{k.l}</p></div>)}
        </div>
      )}

      <div className="card-3d" style={{ padding: '16px' }}>
        <div className="flex gap-3 flex-wrap items-end">
          <div className="relative flex-1 min-w-[180px]"><label className="label">Search</label><Search size={14} className="absolute left-3 top-[34px]" style={{ color: 'var(--text-muted)' }} /><input className="input-3d pl-9 text-sm" placeholder="Title or message…" value={f.search} onChange={e => { setPage(1); setF(s => ({ ...s, search: e.target.value })) }} /></div>
          <Sel label="Module" val={f.module} set={v => { setPage(1); setF(s => ({ ...s, module: v })) }} opts={['All', ...catalog.modules]} />
          <Sel label="Priority" val={f.priority} set={v => { setPage(1); setF(s => ({ ...s, priority: v })) }} opts={['All', ...catalog.priorities]} />
          <Sel label="Status" val={f.is_read} set={v => { setPage(1); setF(s => ({ ...s, is_read: v })) }} opts={['All', 'Unread', 'Read']} />
          <Sel label="Type" val={f.notification_type} set={v => { setPage(1); setF(s => ({ ...s, notification_type: v })) }} opts={['All', 'event', 'reminder', 'escalation']} />
          <div className="min-w-[130px]"><label className="label">From</label><input type="date" className="input-3d text-sm" value={f.from} onChange={e => { setPage(1); setF(s => ({ ...s, from: e.target.value })) }} /></div>
          <div className="min-w-[130px]"><label className="label">To</label><input type="date" className="input-3d text-sm" value={f.to} onChange={e => { setPage(1); setF(s => ({ ...s, to: e.target.value })) }} /></div>
          {hasF && <button onClick={() => { setPage(1); setF({ module: 'All', priority: 'All', is_read: 'All', notification_type: 'All', from: '', to: '', search: '' }) }} className="px-3 py-2.5 rounded-xl text-xs font-bold" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>Clear</button>}
          {!historyMode && <button onClick={markAll} className="px-3 py-2.5 rounded-xl text-xs font-bold flex items-center gap-1.5 text-white" style={{ background: GRAD }}><CheckCheck size={13} /> Mark all read</button>}
        </div>
      </div>

      {loading ? <HrLoading /> : rows.length === 0 ? <HrEmpty icon={Bell} title="No notifications" hint="Notifications from every HR module appear here." /> : (
        <div className="card-3d overflow-hidden" style={{ padding: 0 }}>
          <table className="w-full text-sm">
            <thead><tr style={{ borderBottom: '1px solid var(--border)' }}>
              {['', 'Module / Event', 'Message', 'Type', 'Status', 'When', ''].map((h, i) => <th key={i} className="text-left px-4 py-3 text-[11px] font-bold uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>{h}</th>)}
            </tr></thead>
            <tbody>
              {rows.map(n => {
                const ps = priorityStyle(n.priority)
                return (
                  <tr key={n.id} style={{ borderBottom: '1px solid var(--border)', background: n.is_read ? 'transparent' : 'rgba(124,58,237,0.03)' }}>
                    <td className="px-4 py-3"><span className="w-2.5 h-2.5 rounded-full inline-block" title={n.priority} style={{ background: ps.dot, boxShadow: n.is_read ? 'none' : `0 0 6px ${ps.dot}` }} /></td>
                    <td className="px-4 py-3"><div className="text-[11px] font-bold uppercase" style={{ color: ps.fg }}>{n.module}</div><div className="text-xs" style={{ color: 'var(--text-muted)' }}>{n.event}</div></td>
                    <td className="px-4 py-3 max-w-[320px]"><div className="text-xs font-semibold truncate" style={{ color: 'var(--text-h)' }}>{n.title}</div>{n.message && <div className="text-[11px] truncate" style={{ color: 'var(--text-muted)' }}>{n.message}</div>}</td>
                    <td className="px-4 py-3"><span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background: ps.bg, color: ps.fg }}>{TYPE_LABEL[n.notification_type] || n.notification_type}</span></td>
                    <td className="px-4 py-3"><span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={n.is_read ? { background: 'var(--bg-input)', color: 'var(--text-muted)' } : { background: 'rgba(124,58,237,0.12)', color: '#a78bfa' }}>{n.is_read ? 'Read' : 'Unread'}</span></td>
                    <td className="px-4 py-3 text-xs whitespace-nowrap" style={{ color: 'var(--text-muted)' }}>{timeAgo(n.created_at)}</td>
                    <td className="px-4 py-3"><div className="flex gap-1.5 justify-end">
                      <button onClick={() => openDrawer(n)} className="p-1.5 rounded-lg" style={{ background: 'rgba(124,58,237,0.1)', color: '#a78bfa' }} title="View"><Eye size={13} /></button>
                      {!n.is_read && <button onClick={() => markRead(n)} className="p-1.5 rounded-lg" style={{ background: 'rgba(16,185,129,0.1)', color: '#10b981' }} title="Mark read"><Check size={13} /></button>}
                      {n.action_url && <button onClick={() => openRecord(n)} className="p-1.5 rounded-lg" style={{ background: 'rgba(59,130,246,0.1)', color: '#3b82f6' }} title="Open related record"><ExternalLink size={13} /></button>}
                    </div></td>
                  </tr>
                )
              })}
            </tbody>
          </table>
          <div className="flex items-center justify-between px-4 py-3" style={{ borderTop: '1px solid var(--border)' }}>
            <span className="text-xs" style={{ color: 'var(--text-muted)' }}>{meta.total} total · page {meta.current_page} / {meta.last_page}</span>
            <div className="flex gap-2">
              <button disabled={page <= 1} onClick={() => setPage(p => p - 1)} className="px-3 py-1.5 rounded-lg text-xs font-bold disabled:opacity-40" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>Prev</button>
              <button disabled={page >= meta.last_page} onClick={() => setPage(p => p + 1)} className="px-3 py-1.5 rounded-lg text-xs font-bold disabled:opacity-40" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>Next</button>
            </div>
          </div>
        </div>
      )}

      {drawer && <NotificationDrawer n={drawer} onClose={() => setDrawer(null)} onMarkRead={markRead} onResend={resend} onOpenRecord={openRecord} />}
    </div>
  )
}

function NotificationDrawer({ n, onClose, onMarkRead, onResend, onOpenRecord }) {
  const ps = priorityStyle(n.priority)
  return (
    <div className="fixed inset-0 z-[9998] flex justify-end" style={{ background: 'rgba(0,0,0,0.45)' }} onClick={onClose}>
      <div className="h-full w-full max-w-md overflow-y-auto animate-[slideInRight_0.25s_ease] p-6" style={{ background: 'var(--bg-card)', borderLeft: '1px solid var(--border)' }} onClick={e => e.stopPropagation()}>
        <div className="flex items-start justify-between mb-4">
          <div><div className="text-[11px] font-bold uppercase" style={{ color: ps.fg }}>{n.module} · {n.event}</div><h3 className="text-lg font-black mt-0.5" style={{ color: 'var(--text-h)' }}>{n.title}</h3></div>
          <button onClick={onClose} className="p-1.5 rounded-lg" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)' }}><X size={16} /></button>
        </div>

        <div className="flex flex-wrap gap-2 mb-4">
          <Badge style={{ background: ps.bg, color: ps.fg }}>{n.priority}</Badge>
          <Badge style={{ background: 'rgba(59,130,246,0.12)', color: '#3b82f6' }}>{TYPE_LABEL[n.notification_type] || n.notification_type}</Badge>
          <Badge style={n.is_read ? { background: 'var(--bg-input)', color: 'var(--text-muted)' } : { background: 'rgba(124,58,237,0.12)', color: '#a78bfa' }}>{n.is_read ? 'Read' : 'Unread'}</Badge>
        </div>

        {n.message && <div className="card-3d text-sm mb-4" style={{ padding: '14px', color: 'var(--text-h)' }}>{n.message}</div>}

        <dl className="text-sm space-y-2 mb-5">
          <Row k="Recipient" v={n.recipient_user_id ? `User #${n.recipient_user_id}` : (n.recipient_role ? `Role: ${n.recipient_role}` : '—')} />
          <Row k="Related record" v={n.entity_type ? `${n.entity_type} #${n.entity_id}` : '—'} />
          <Row k="Created" v={fmtDate(n.created_at)} />
          {n.read_at && <Row k="Read at" v={fmtDate(n.read_at)} />}
        </dl>

        <div className="flex flex-wrap gap-2 mb-6">
          {!n.is_read && <ActBtn icon={Check} label="Mark Read" onClick={() => onMarkRead(n)} c="#10b981" />}
          {n.action_url && <ActBtn icon={ExternalLink} label={n.action_label || 'Open Related Record'} onClick={() => onOpenRecord(n)} c="#3b82f6" />}
          <ActBtn icon={Mail} label="Resend Email" onClick={() => onResend(n)} c="#7C3AED" />
        </div>

        <div>
          <p className="label-caps mb-2 flex items-center gap-1.5"><Clock size={12} /> Audit Timeline</p>
          {(n.timeline && n.timeline.length) ? (
            <div className="space-y-2.5">
              {n.timeline.map((t, i) => (
                <div key={i} className="flex gap-3">
                  <div className="flex flex-col items-center"><span className="w-2 h-2 rounded-full mt-1.5" style={{ background: '#a78bfa' }} />{i < n.timeline.length - 1 && <span className="flex-1 w-px my-1" style={{ background: 'var(--border)' }} />}</div>
                  <div className="pb-1"><p className="text-xs font-bold" style={{ color: 'var(--text-h)' }}>{t.action}</p><p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>{t.actor_name || 'System'} · {fmtDate(t.created_at)}</p></div>
                </div>
              ))}
            </div>
          ) : <p className="text-xs" style={{ color: 'var(--text-muted)' }}>No audit entries.</p>}
        </div>
      </div>
    </div>
  )
}

/* ───────────────────────── Templates ───────────────────────── */
function TemplatesTab({ catalog, showToast }) {
  const [rows, setRows] = useState([]); const [loading, setLoading] = useState(true)
  const [moduleF, setModuleF] = useState('All'); const [search, setSearch] = useState('')
  const [modal, setModal] = useState(null); const [saving, setSaving] = useState(false); const [seeding, setSeeding] = useState(false)

  const load = useCallback(() => {
    setLoading(true)
    const params = {}; if (moduleF !== 'All') params.module = moduleF; if (search) params.search = search
    hrApi.notifications.templates.list(params).then(r => setRows(r || [])).catch(() => showToast('Failed to load templates', 'error')).finally(() => setLoading(false))
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [moduleF, search])
  useEffect(() => { load() }, [load])

  const seed = async () => { setSeeding(true); try { const r = await hrApi.notifications.templates.seed(); showToast(`Seeded ${r.templates_created} templates, ${r.rules_created} rules`); load() } catch { showToast('Seed failed', 'error') } finally { setSeeding(false) } }
  const toggle = async (t) => { try { await hrApi.notifications.templates.setStatus(t.id, !t.is_active); load() } catch { showToast('Failed', 'error') } }
  const save = async () => {
    const f = modal.form
    if (!f.subject.trim() || !f.body.trim()) return showToast('Subject and body are required', 'error')
    setSaving(true)
    try {
      if (modal.editing) await hrApi.notifications.templates.update(modal.editing, f)
      else { if (!f.module || !f.event) { setSaving(false); return showToast('Pick a module and event', 'error') } await hrApi.notifications.templates.create(f) }
      showToast(`Template ${modal.editing ? 'updated' : 'created'}`); setModal(null); load()
    } catch (e) { showToast(e.response?.data?.message || 'Save failed', 'error') } finally { setSaving(false) }
  }

  const eventsForModule = (m) => catalog.events.filter(e => e.module === m).map(e => e.event)

  return (
    <div className="space-y-4">
      <div className="card-3d" style={{ padding: '16px' }}>
        <div className="flex gap-3 flex-wrap items-end">
          <div className="relative flex-1 min-w-[180px]"><label className="label">Search</label><Search size={14} className="absolute left-3 top-[34px]" style={{ color: 'var(--text-muted)' }} /><input className="input-3d pl-9 text-sm" placeholder="Event or subject…" value={search} onChange={e => setSearch(e.target.value)} /></div>
          <Sel label="Module" val={moduleF} set={setModuleF} opts={['All', ...catalog.modules]} />
          <button onClick={seed} disabled={seeding} className="px-3 py-2.5 rounded-xl text-xs font-bold flex items-center gap-1.5" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}><Sparkles size={13} /> {seeding ? 'Seeding…' : 'Seed Defaults'}</button>
          <button onClick={() => setModal({ editing: null, form: { module: catalog.modules[0] || '', event: '', subject: '', body: '', email_enabled: true, in_app_enabled: true, is_active: true } })} className="px-3 py-2.5 rounded-xl text-xs font-bold flex items-center gap-1.5 text-white" style={{ background: GRAD }}><Plus size={13} /> New Template</button>
        </div>
      </div>

      {loading ? <HrLoading /> : rows.length === 0 ? <HrEmpty icon={FileText} title="No templates" hint="Seed defaults to generate templates for every registered module event." /> : (
        <div className="card-3d overflow-hidden" style={{ padding: 0 }}>
          <table className="w-full text-sm">
            <thead><tr style={{ borderBottom: '1px solid var(--border)' }}>{['Module / Event', 'Subject', 'Channels', 'Status', ''].map((h, i) => <th key={i} className="text-left px-4 py-3 text-[11px] font-bold uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>{h}</th>)}</tr></thead>
            <tbody>
              {rows.map(t => (
                <tr key={t.id} style={{ borderBottom: '1px solid var(--border)' }}>
                  <td className="px-4 py-3"><div className="text-[11px] font-bold uppercase" style={{ color: '#a78bfa' }}>{t.module}</div><div className="text-xs" style={{ color: 'var(--text-muted)' }}>{t.event}</div></td>
                  <td className="px-4 py-3 max-w-[280px]"><div className="text-xs font-semibold truncate" style={{ color: 'var(--text-h)' }}>{t.subject}</div></td>
                  <td className="px-4 py-3"><div className="flex gap-1 flex-wrap">{CHANNELS.filter(c => t[`${c.key}_enabled`]).map(c => <span key={c.key} className="text-[10px] font-bold px-1.5 py-0.5 rounded-md" style={{ background: c.live ? 'rgba(16,185,129,0.12)' : 'var(--bg-input)', color: c.live ? '#10b981' : 'var(--text-muted)' }}>{c.label}</span>)}</div></td>
                  <td className="px-4 py-3"><span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={t.is_active ? { background: 'rgba(16,185,129,0.12)', color: '#10b981' } : { background: 'var(--bg-input)', color: 'var(--text-muted)' }}>{t.is_active ? 'Active' : 'Inactive'}</span></td>
                  <td className="px-4 py-3"><div className="flex gap-1.5 justify-end">
                    <button onClick={() => setModal({ editing: t.id, form: { ...t } })} className="p-1.5 rounded-lg" style={{ background: 'rgba(124,58,237,0.1)', color: '#a78bfa' }}><Pencil size={13} /></button>
                    <button onClick={() => toggle(t)} className="p-1.5 rounded-lg" style={t.is_active ? { background: 'rgba(239,68,68,0.1)', color: '#f87171' } : { background: 'rgba(16,185,129,0.1)', color: '#10b981' }}><Power size={13} /></button>
                  </div></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {modal && (
        <Modal title={modal.editing ? 'Edit Template' : 'New Template'} onClose={() => setModal(null)} onSave={save} saving={saving}>
          {!modal.editing && (
            <div className="grid grid-cols-2 gap-3">
              <div><label className="label">Module</label><select className="input-3d text-sm" value={modal.form.module} onChange={e => setModal(m => ({ ...m, form: { ...m.form, module: e.target.value, event: '' } }))}>{catalog.modules.map(x => <option key={x}>{x}</option>)}</select></div>
              <div><label className="label">Event</label><select className="input-3d text-sm" value={modal.form.event} onChange={e => setModal(m => ({ ...m, form: { ...m.form, event: e.target.value } }))}><option value="">Select…</option>{eventsForModule(modal.form.module).map(x => <option key={x}>{x}</option>)}</select></div>
            </div>
          )}
          <div><label className="label">Subject</label><input className="input-3d text-sm" value={modal.form.subject} onChange={e => setModal(m => ({ ...m, form: { ...m.form, subject: e.target.value } }))} /></div>
          <div><label className="label">Body</label><textarea rows={5} className="input-3d text-sm" value={modal.form.body} onChange={e => setModal(m => ({ ...m, form: { ...m.form, body: e.target.value } }))} /><p className="text-[11px] mt-1" style={{ color: 'var(--text-muted)' }}>Placeholders: {'{{employee}} {{department}} {{designation}} {{manager}} {{remaining_days}} {{date}} {{module}} {{company}}'}</p></div>
          <div><label className="label">Channels</label><div className="flex gap-2 flex-wrap">{CHANNELS.map(c => { const on = !!modal.form[`${c.key}_enabled`]; return (<button key={c.key} type="button" onClick={() => setModal(m => ({ ...m, form: { ...m.form, [`${c.key}_enabled`]: !on } }))} className="text-[11px] font-bold px-2.5 py-1.5 rounded-lg flex items-center gap-1" style={on ? { background: 'rgba(124,58,237,0.14)', color: '#a78bfa', border: '1px solid rgba(124,58,237,0.3)' } : { background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>{c.label}{!c.live && <span className="text-[9px] opacity-70">(prep)</span>}</button>) })}</div></div>
        </Modal>
      )}
    </div>
  )
}

/* ───────────────────────── Reminder Rules ───────────────────────── */
function RulesTab({ catalog, showToast }) {
  const [rows, setRows] = useState([]); const [loading, setLoading] = useState(true)
  const [moduleF, setModuleF] = useState('All')
  const [modal, setModal] = useState(null); const [saving, setSaving] = useState(false)

  const load = useCallback(() => {
    setLoading(true)
    const params = {}; if (moduleF !== 'All') params.module = moduleF
    hrApi.notifications.rules.list(params).then(r => setRows(r || [])).catch(() => showToast('Failed to load rules', 'error')).finally(() => setLoading(false))
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [moduleF])
  useEffect(() => { load() }, [load])

  const toggle = async (r) => { try { await hrApi.notifications.rules.setStatus(r.id, !r.enabled); load() } catch { showToast('Failed', 'error') } }
  const save = async () => {
    const f = modal.form
    const payload = {
      ...(modal.editing ? {} : { module: f.module, event: f.event }),
      reminder_days: String(f.reminder_days).split(',').map(s => parseInt(s.trim(), 10)).filter(n => !isNaN(n)),
      repeat_daily: !!f.repeat_daily, priority: f.priority, enabled: !!f.enabled,
      escalation_days: f.escalation_on ? catalog.escalation_ladder : null,
    }
    if (!modal.editing && (!f.module || !f.event)) return showToast('Pick a module and event', 'error')
    setSaving(true)
    try { if (modal.editing) await hrApi.notifications.rules.update(modal.editing, payload); else await hrApi.notifications.rules.create(payload); showToast(`Rule ${modal.editing ? 'updated' : 'created'}`); setModal(null); load() }
    catch (e) { showToast(e.response?.data?.message || 'Save failed', 'error') } finally { setSaving(false) }
  }
  const eventsForModule = (m) => catalog.events.filter(e => e.module === m).map(e => e.event)
  const openEdit = (r) => setModal({ editing: r.id, form: { module: r.module, event: r.event, reminder_days: (r.reminder_days || []).join(', '), repeat_daily: r.repeat_daily, priority: r.priority, enabled: r.enabled, escalation_on: (r.escalation_days || []).length > 0 } })

  return (
    <div className="space-y-4">
      <div className="card-3d" style={{ padding: '16px' }}>
        <div className="flex gap-3 flex-wrap items-end">
          <Sel label="Module" val={moduleF} set={setModuleF} opts={['All', ...catalog.modules]} />
          <div className="flex-1" />
          <button onClick={() => setModal({ editing: null, form: { module: catalog.modules[0] || '', event: '', reminder_days: '7, 3, 1, 0', repeat_daily: false, priority: 'Warning', enabled: true, escalation_on: false } })} className="px-3 py-2.5 rounded-xl text-xs font-bold flex items-center gap-1.5 text-white" style={{ background: GRAD }}><Plus size={13} /> New Rule</button>
        </div>
      </div>

      {loading ? <HrLoading /> : rows.length === 0 ? <HrEmpty icon={AlarmClock} title="No reminder rules" hint="Seed defaults from the Templates tab, or add a rule here." /> : (
        <div className="card-3d overflow-hidden" style={{ padding: 0 }}>
          <table className="w-full text-sm">
            <thead><tr style={{ borderBottom: '1px solid var(--border)' }}>{['Module / Event', 'Reminder Days', 'Repeat', 'Escalation', 'Priority', 'Status', ''].map((h, i) => <th key={i} className="text-left px-4 py-3 text-[11px] font-bold uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>{h}</th>)}</tr></thead>
            <tbody>
              {rows.map(r => {
                const ps = priorityStyle(r.priority)
                return (
                  <tr key={r.id} style={{ borderBottom: '1px solid var(--border)' }}>
                    <td className="px-4 py-3"><div className="text-[11px] font-bold uppercase" style={{ color: '#a78bfa' }}>{r.module}</div><div className="text-xs" style={{ color: 'var(--text-muted)' }}>{r.event}</div></td>
                    <td className="px-4 py-3"><div className="flex gap-1 flex-wrap">{(r.reminder_days || []).map((d, i) => <span key={i} className="text-[10px] font-bold px-1.5 py-0.5 rounded-md" style={{ background: 'rgba(59,130,246,0.12)', color: '#3b82f6' }}>{d === 0 ? 'due' : `${d}d`}</span>)}</div></td>
                    <td className="px-4 py-3"><span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={r.repeat_daily ? { background: 'rgba(16,185,129,0.12)', color: '#10b981' } : { background: 'var(--bg-input)', color: 'var(--text-muted)' }}>{r.repeat_daily ? 'Daily' : 'Once'}</span></td>
                    <td className="px-4 py-3">{(r.escalation_days || []).length ? <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg flex items-center gap-1 w-fit" style={{ background: 'rgba(245,158,11,0.14)', color: '#f59e0b' }}><ShieldAlert size={11} />{r.escalation_days.length} steps</span> : <span className="text-xs" style={{ color: 'var(--text-muted)' }}>—</span>}</td>
                    <td className="px-4 py-3"><span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background: ps.bg, color: ps.fg }}>{r.priority}</span></td>
                    <td className="px-4 py-3"><span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={r.enabled ? { background: 'rgba(16,185,129,0.12)', color: '#10b981' } : { background: 'var(--bg-input)', color: 'var(--text-muted)' }}>{r.enabled ? 'Enabled' : 'Disabled'}</span></td>
                    <td className="px-4 py-3"><div className="flex gap-1.5 justify-end">
                      <button onClick={() => openEdit(r)} className="p-1.5 rounded-lg" style={{ background: 'rgba(124,58,237,0.1)', color: '#a78bfa' }}><Pencil size={13} /></button>
                      <button onClick={() => toggle(r)} className="p-1.5 rounded-lg" style={r.enabled ? { background: 'rgba(239,68,68,0.1)', color: '#f87171' } : { background: 'rgba(16,185,129,0.1)', color: '#10b981' }}><Power size={13} /></button>
                    </div></td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      )}

      {modal && (
        <Modal title={modal.editing ? 'Edit Reminder Rule' : 'New Reminder Rule'} onClose={() => setModal(null)} onSave={save} saving={saving}>
          {!modal.editing && (
            <div className="grid grid-cols-2 gap-3">
              <div><label className="label">Module</label><select className="input-3d text-sm" value={modal.form.module} onChange={e => setModal(m => ({ ...m, form: { ...m.form, module: e.target.value, event: '' } }))}>{catalog.modules.map(x => <option key={x}>{x}</option>)}</select></div>
              <div><label className="label">Event</label><select className="input-3d text-sm" value={modal.form.event} onChange={e => setModal(m => ({ ...m, form: { ...m.form, event: e.target.value } }))}><option value="">Select…</option>{eventsForModule(modal.form.module).map(x => <option key={x}>{x}</option>)}</select></div>
            </div>
          )}
          <div><label className="label">Reminder days (comma-separated; 0 = on due date)</label><input className="input-3d text-sm" value={modal.form.reminder_days} onChange={e => setModal(m => ({ ...m, form: { ...m.form, reminder_days: e.target.value } }))} placeholder="30, 15, 7, 1, 0" /></div>
          <div className="grid grid-cols-2 gap-3">
            <div><label className="label">Priority</label><select className="input-3d text-sm" value={modal.form.priority} onChange={e => setModal(m => ({ ...m, form: { ...m.form, priority: e.target.value } }))}>{['Info', 'Success', 'Warning', 'Critical'].map(p => <option key={p}>{p}</option>)}</select></div>
            <div className="flex items-end gap-3 pb-1">
              <Toggle label="Repeat daily" on={modal.form.repeat_daily} onClick={() => setModal(m => ({ ...m, form: { ...m.form, repeat_daily: !m.form.repeat_daily } }))} />
              <Toggle label="Escalation" on={modal.form.escalation_on} onClick={() => setModal(m => ({ ...m, form: { ...m.form, escalation_on: !m.form.escalation_on } }))} />
            </div>
          </div>
          {modal.form.escalation_on && <div className="card-3d text-[11px]" style={{ padding: '10px', color: 'var(--text-muted)' }}>Configuration-driven ladder: {catalog.escalation_ladder.map(s => `Day ${s.days} → ${s.role}`).join(' · ')}</div>}
        </Modal>
      )}
    </div>
  )
}

/* ───────────────────────── Queue Monitor ───────────────────────── */
function QueueTab({ showToast }) {
  const [rows, setRows] = useState([]); const [meta, setMeta] = useState({ current_page: 1, last_page: 1, total: 0 })
  const [stats, setStats] = useState({ pending: 0, processing: 0, sent: 0, failed: 0 })
  const [loading, setLoading] = useState(true); const [page, setPage] = useState(1)
  const [statusF, setStatusF] = useState('All'); const [channelF, setChannelF] = useState('All'); const [processing, setProcessing] = useState(false)

  const load = useCallback(() => {
    setLoading(true)
    const params = { page, per_page: 25 }; if (statusF !== 'All') params.status = statusF; if (channelF !== 'All') params.channel = channelF
    Promise.all([hrApi.notifications.queue.list(params), hrApi.notifications.queue.stats()])
      .then(([list, s]) => { setRows(list.data || []); setMeta(list.meta || meta); setStats(s) })
      .catch(() => showToast('Failed to load queue', 'error')).finally(() => setLoading(false))
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [page, statusF, channelF])
  useEffect(() => { load() }, [load])

  const drain = async () => { setProcessing(true); try { const r = await hrApi.notifications.queue.process(); showToast(`Processed ${r.processed} · sent ${r.sent} · failed ${r.failed}`); load() } catch { showToast('Failed', 'error') } finally { setProcessing(false) } }
  const retry = async (id) => { try { const r = await hrApi.notifications.queue.retry(id); showToast(r.ok ? 'Delivered' : 'Retry failed'); load() } catch (e) { showToast(e.response?.data?.message || 'Retry failed', 'error') } }

  const KPIS = [{ l: 'Pending', v: stats.pending, c: '#f59e0b' }, { l: 'Processing', v: stats.processing, c: '#3b82f6' }, { l: 'Sent', v: stats.sent, c: '#10b981' }, { l: 'Failed', v: stats.failed, c: '#ef4444' }]

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4">{KPIS.map(k => <div key={k.l} className="kpi-3d"><p className="text-3xl font-black" style={{ color: k.c }}>{k.v}</p><p className="text-xs font-medium mt-1" style={{ color: 'var(--text-muted)' }}>{k.l}</p></div>)}</div>

      <div className="card-3d" style={{ padding: '16px' }}>
        <div className="flex gap-3 flex-wrap items-end">
          <Sel label="Status" val={statusF} set={v => { setPage(1); setStatusF(v) }} opts={['All', 'Pending', 'Processing', 'Sent', 'Failed']} />
          <Sel label="Channel" val={channelF} set={v => { setPage(1); setChannelF(v) }} opts={['All', ...CHANNELS.map(c => c.key)]} />
          <div className="flex-1" />
          <button onClick={drain} disabled={processing} className="px-3 py-2.5 rounded-xl text-xs font-bold flex items-center gap-1.5 text-white" style={{ background: GRAD }}><RefreshCw size={13} className={processing ? 'animate-spin' : ''} /> {processing ? 'Processing…' : 'Process Queue'}</button>
        </div>
      </div>

      {loading ? <HrLoading /> : rows.length === 0 ? <HrEmpty icon={Send} title="Queue is empty" hint="Delivery attempts across all channels appear here." /> : (
        <div className="card-3d overflow-hidden" style={{ padding: 0 }}>
          <table className="w-full text-sm">
            <thead><tr style={{ borderBottom: '1px solid var(--border)' }}>{['Notification', 'Channel', 'Status', 'Retries', 'Error', 'Sent', ''].map((h, i) => <th key={i} className="text-left px-4 py-3 text-[11px] font-bold uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>{h}</th>)}</tr></thead>
            <tbody>
              {rows.map(i => {
                const ss = statusStyle(i.status)
                return (
                  <tr key={i.id} style={{ borderBottom: '1px solid var(--border)' }}>
                    <td className="px-4 py-3"><div className="text-[11px] font-bold uppercase" style={{ color: '#a78bfa' }}>{i.notification?.module || '—'}</div><div className="text-xs truncate max-w-[220px]" style={{ color: 'var(--text-muted)' }}>{i.notification?.title || `#${i.id}`}</div></td>
                    <td className="px-4 py-3"><span className="text-[10px] font-bold px-2 py-0.5 rounded-lg flex items-center gap-1 w-fit" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)' }}>{i.channel === 'email' ? <Mail size={10} /> : <MessageSquare size={10} />}{i.channel}</span></td>
                    <td className="px-4 py-3"><span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background: ss.bg, color: ss.fg }}>{i.status}</span></td>
                    <td className="px-4 py-3 text-xs" style={{ color: 'var(--text-muted)' }}>{i.retry_count}</td>
                    <td className="px-4 py-3 max-w-[220px]"><span className="text-[11px] truncate block" style={{ color: i.error_message ? '#f87171' : 'var(--text-muted)' }}>{i.error_message || '—'}</span></td>
                    <td className="px-4 py-3 text-xs whitespace-nowrap" style={{ color: 'var(--text-muted)' }}>{i.sent_at ? timeAgo(i.sent_at) : '—'}</td>
                    <td className="px-4 py-3">{i.status === 'Failed' && <button onClick={() => retry(i.id)} className="p-1.5 rounded-lg" style={{ background: 'rgba(124,58,237,0.1)', color: '#a78bfa' }} title="Retry"><RefreshCw size={13} /></button>}</td>
                  </tr>
                )
              })}
            </tbody>
          </table>
          <div className="flex items-center justify-between px-4 py-3" style={{ borderTop: '1px solid var(--border)' }}>
            <span className="text-xs" style={{ color: 'var(--text-muted)' }}>{meta.total} total · page {meta.current_page} / {meta.last_page}</span>
            <div className="flex gap-2">
              <button disabled={page <= 1} onClick={() => setPage(p => p - 1)} className="px-3 py-1.5 rounded-lg text-xs font-bold disabled:opacity-40" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>Prev</button>
              <button disabled={page >= meta.last_page} onClick={() => setPage(p => p + 1)} className="px-3 py-1.5 rounded-lg text-xs font-bold disabled:opacity-40" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>Next</button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}

/* ───────────────────────── shared bits ───────────────────────── */
const Sel = ({ label, val, set, opts }) => <div className="min-w-[130px]"><label className="label">{label}</label><select className="input-3d text-sm" value={val} onChange={e => set(e.target.value)}>{opts.map(o => <option key={o} value={o}>{o}</option>)}</select></div>
const Badge = ({ children, style }) => <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={style}>{children}</span>
const Row = ({ k, v }) => <div className="flex justify-between gap-4"><dt style={{ color: 'var(--text-muted)' }}>{k}</dt><dd className="font-semibold text-right" style={{ color: 'var(--text-h)' }}>{v}</dd></div>
const ActBtn = ({ icon: Icon, label, onClick, c }) => <button onClick={onClick} className="flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-bold" style={{ background: `${c}1a`, color: c, border: `1px solid ${c}33` }}><Icon size={13} /> {label}</button>
const Toggle = ({ label, on, onClick }) => <button type="button" onClick={onClick} className="flex items-center gap-1.5 text-xs font-bold px-2.5 py-2 rounded-lg" style={on ? { background: 'rgba(124,58,237,0.14)', color: '#a78bfa', border: '1px solid rgba(124,58,237,0.3)' } : { background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}><Power size={12} /> {label}</button>

function Modal({ title, children, onClose, onSave, saving }) {
  return (
    <div className="modal-backdrop" onClick={onClose}>
      <div className="modal-box w-full max-w-lg" onClick={e => e.stopPropagation()}>
        <div className="flex items-center justify-between mb-4"><h3 className="text-lg font-black" style={{ color: 'var(--text-h)' }}>{title}</h3><button onClick={onClose} className="p-1.5 rounded-lg" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)' }}><X size={16} /></button></div>
        <div className="space-y-3">{children}</div>
        <div className="flex gap-2 justify-end mt-5">
          <button onClick={onClose} className="px-4 py-2.5 rounded-xl text-sm font-bold" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>Cancel</button>
          <button onClick={onSave} disabled={saving} className="px-4 py-2.5 rounded-xl text-sm font-bold text-white disabled:opacity-60" style={{ background: GRAD }}>{saving ? 'Saving…' : 'Save'}</button>
        </div>
      </div>
    </div>
  )
}
