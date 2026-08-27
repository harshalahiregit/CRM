import { useEffect, useState } from 'react'
import { Loader2 } from 'lucide-react'
import { portalApi } from '@/services/portalApi'

/**
 * TPV portal — "My Work": the Projects, Tasks and Tickets this vendor (or its
 * employees) is linked to. Read-only lists for now; vendor writes (task status,
 * ticket raise/reply) land in a follow-up. Data comes from the role-gated
 * my-work endpoints (VendorWorkController).
 */
const VIEWS = {
  projects: {
    title: 'My Projects', load: () => portalApi.myWork.projects(),
    cols: [
      { k: 'name', h: 'Project', strong: true }, { k: 'role', h: 'Role' },
      { k: 'progress', h: 'Progress', fmt: v => `${v ?? 0}%` },
      { k: 'deadline', h: 'Deadline' }, { k: 'status', h: 'Status', pill: true },
    ],
  },
  tasks: {
    title: 'My Tasks', load: () => portalApi.myWork.tasks(),
    cols: [
      { k: 'name', h: 'Task', strong: true }, { k: 'project', h: 'Project' },
      { k: 'priority', h: 'Priority' }, { k: 'due_date', h: 'Due' }, { k: 'status', h: 'Status', pill: true },
    ],
  },
  tickets: {
    title: 'My Tickets', load: () => portalApi.myWork.tickets(),
    cols: [
      { k: 'subject', h: 'Subject', strong: true }, { k: 'priority', h: 'Priority' }, { k: 'status', h: 'Status', pill: true },
    ],
  },
}

const TONE = {
  completed: 'ok', done: 'ok', closed: 'muted', resolved: 'ok', active: 'info', in_progress: 'info',
  open: 'warn', pending: 'warn', on_hold: 'warn', overdue: 'bad', cancelled: 'bad',
}
function Pill({ value }) {
  const label = String(value ?? '—').replace(/_/g, ' ')
  const tone = TONE[String(value ?? '').toLowerCase()] || 'muted'
  const bg = { ok: 'rgba(34,197,94,0.15)', info: 'rgba(59,130,246,0.15)', warn: 'rgba(245,158,11,0.15)', bad: 'rgba(239,68,68,0.15)', muted: 'rgba(148,163,184,0.15)' }[tone]
  const fg = { ok: '#22c55e', info: '#3b82f6', warn: '#f59e0b', bad: '#ef4444', muted: '#94a3b8' }[tone]
  return <span style={{ padding: '2px 10px', borderRadius: 999, fontSize: 11, fontWeight: 700, textTransform: 'capitalize', background: bg, color: fg }}>{label}</span>
}

export default function MyWork({ view }) {
  const cfg = VIEWS[view]
  const [rows, setRows] = useState(null)

  useEffect(() => {
    setRows(null)
    cfg?.load().then(d => setRows(Array.isArray(d) ? d : (d?.data || []))).catch(() => setRows([]))
  }, [view])

  if (!cfg) return null

  return (
    <div style={{ maxWidth: 940, margin: '0 auto' }}>
      <style>{CSS}</style>
      <h2 style={{ fontSize: 18, fontWeight: 800, color: 'var(--text-h)', margin: '0 0 16px' }}>{cfg.title}</h2>
      {rows === null ? <div style={{ display: 'flex', justifyContent: 'center', padding: 60 }}><Loader2 className="mw-spin" size={22} /></div>
        : rows.length === 0 ? <div style={{ textAlign: 'center', color: 'var(--text-muted)', padding: 48, fontSize: 14 }}>Nothing assigned yet.</div>
        : (
          <div className="mw-card">
            <div style={{ overflowX: 'auto' }}>
              <table className="mw-table">
                <thead><tr>{cfg.cols.map(c => <th key={c.k}>{c.h}</th>)}</tr></thead>
                <tbody>
                  {rows.map(r => (
                    <tr key={r.id}>
                      {cfg.cols.map(c => (
                        <td key={c.k} style={{ fontWeight: c.strong ? 700 : 400, color: c.strong ? 'var(--text-h)' : undefined }}>
                          {c.pill ? <Pill value={r[c.k]} /> : c.fmt ? c.fmt(r[c.k]) : (r[c.k] ?? '—')}
                        </td>
                      ))}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        )}
    </div>
  )
}

const CSS = `
.mw-card { background: var(--bg-card, rgba(255,255,255,0.02)); border: 1px solid var(--border, rgba(255,255,255,0.08)); border-radius: 14px; padding: 8px 4px; }
.mw-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.mw-table th { text-align: left; font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); padding: 10px 14px; border-bottom: 1px solid var(--border, rgba(255,255,255,0.08)); white-space: nowrap; }
.mw-table td { padding: 11px 14px; border-bottom: 1px solid var(--border, rgba(255,255,255,0.05)); color: var(--text-body, #cbd5e1); }
.mw-table tbody tr:last-child td { border-bottom: none; }
.mw-table tbody tr:hover { background: var(--bg-input, rgba(255,255,255,0.03)); }
.mw-spin { animation: mw-spin 0.9s linear infinite; }
@keyframes mw-spin { to { transform: rotate(360deg); } }
`
