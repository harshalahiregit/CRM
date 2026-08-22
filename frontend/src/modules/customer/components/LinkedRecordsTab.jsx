import { useState, useEffect } from 'react'
import { ArrowUpRight, Lock } from 'lucide-react'
import { Link } from 'react-router-dom'
import { customerApi } from '@/services/customerApi'
import { useToast } from '@/hooks/useToast'

/**
 * §6 — "Don't duplicate the modules."
 *
 * Projects, Tasks, Delivery Notes and Meetings belong to other modules. This
 * shows the customer's slice and links out; there is deliberately no add, edit
 * or delete, because building those would BE the duplication the document warns
 * against. The banner says so out loud rather than leaving people hunting for a
 * button that is missing on purpose.
 */
const d10 = s => (s ? String(s).slice(0, 10) : '—')
const dt = s => (s ? new Date(String(s).replace(' ', 'T')).toLocaleString('en-IN',
  { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—')

export const LINKED = {
  projects: {
    fetch: customerApi.linked.projects, title: 'Projects',
    cols: [
      { k: 'name', h: 'Project', bold: true },
      { k: 'status', h: 'Status' },
      { k: 'start_date', h: 'Start', f: d10 },
      { k: 'deadline', h: 'Deadline', f: d10 },
      { k: 'progress', h: 'Progress', f: v => (v == null ? '—' : `${v}%`) },
    ],
  },
  tasks: {
    fetch: customerApi.linked.tasks, title: 'Tasks',
    cols: [
      { k: 'name', h: 'Task', bold: true },
      { k: 'status', h: 'Status' },
      { k: 'priority', h: 'Priority' },
      { k: 'due_date', h: 'Due', f: d10 },
      { k: 'rel_type', h: 'Linked via' },
    ],
  },
  'delivery-notes': {
    fetch: customerApi.linked.deliveryNotes, title: 'Delivery Notes',
    cols: [
      { k: 'number', h: 'Number', bold: true },
      { k: 'delivery_date', h: 'Delivered', f: d10 },
      { k: 'status', h: 'Status' },
      { k: 'invoice_number', h: 'Invoice' },
      { k: 'shipping_city', h: 'City' },
    ],
  },
  meetings: {
    fetch: customerApi.linked.meetings, title: 'Meetings',
    cols: [
      { k: 'title', h: 'Meeting', bold: true },
      { k: 'scheduled_at', h: 'Scheduled', f: dt },
      { k: 'status', h: 'Status' },
      { k: 'mom_status', h: 'Minutes' },
    ],
  },
}

export default function LinkedRecordsTab({ id, kind }) {
  const toast = useToast()
  const cfg = LINKED[kind]
  const [data, setData] = useState(null)

  useEffect(() => {
    setData(null)
    cfg.fetch(id).then(setData).catch(e => toast.error(e.message))
  }, [id, kind])

  const rows = data?.rows ?? null

  return (
    <div className="space-y-4">
      <div className="card-3d flex flex-wrap items-center gap-3" style={{ padding: '12px 16px' }}>
        <Lock size={13} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />
        <p className="text-xs" style={{ color: 'var(--text-muted)', margin: 0, flex: 1, minWidth: 200 }}>
          {data?.unavailable
            ? `The ${cfg.title} module is not installed.`
            : <>These records are owned by <strong style={{ color: 'var(--text-h)' }}>{data?.owned_by || cfg.title}</strong>. Shown here, edited there.</>}
        </p>
        {kind === 'meetings' && data?.open_actions > 0 && (
          <span className="text-[11px] font-bold px-2 py-1 rounded-lg"
            style={{ background: 'rgba(249,115,22,0.14)', color: '#f97316', border: '1px solid rgba(249,115,22,0.35)' }}>
            {data.open_actions} open action{data.open_actions === 1 ? '' : 's'}
          </span>
        )}
        {/* Link, not <a href> — an anchor reloads the whole SPA. */}
        {data?.link && (
          <Link to={data.link} className="flex items-center gap-1 text-[11px] font-bold" style={{ color: 'var(--accent)' }}>
            Open {cfg.title} <ArrowUpRight size={12} />
          </Link>
        )}
      </div>

      <div className="card-3d overflow-hidden" style={{ padding: 0 }}>
        <div className="overflow-x-auto">
          <table className="w-full text-xs">
            <thead><tr style={{ background: 'rgba(124,58,237,0.04)', borderBottom: '1px solid var(--border)' }}>
              {cfg.cols.map(c => <th key={c.k} className="py-3 px-4 text-left label-caps whitespace-nowrap">{c.h}</th>)}
            </tr></thead>
            <tbody>
              {rows === null ? (
                <tr><td colSpan={cfg.cols.length} className="p-3">
                  <div className="skeleton h-8 rounded-lg" style={{ background: 'var(--border)' }} />
                </td></tr>
              ) : rows.length === 0 ? (
                <tr><td colSpan={cfg.cols.length} className="py-10 text-center" style={{ color: 'var(--text-muted)' }}>
                  No {cfg.title.toLowerCase()} for this customer.
                </td></tr>
              ) : rows.map(r => (
                <tr key={r.id} style={{ borderBottom: '1px solid var(--border)' }}>
                  {cfg.cols.map(c => (
                    <td key={c.k} className="py-3 px-4"
                      style={{ color: c.bold ? 'var(--text-h)' : 'var(--text-muted)', fontWeight: c.bold ? 700 : 400 }}>
                      {c.f ? c.f(r[c.k]) : (r[c.k] ?? '—')}
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  )
}
