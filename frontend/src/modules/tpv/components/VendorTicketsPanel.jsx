import { useState, useEffect, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { LifeBuoy } from 'lucide-react'
import { helpdeskApi } from '@/services/helpdeskApi'
import { projectApi } from '@/services/projectApi'
import SLABadge from '@/modules/helpdesk/components/ui/SLABadge'
import { SectionTable } from './VendorSectionTable'
import NewTicketModal from '@/modules/helpdesk/components/NewTicketModal'

/**
 * Support tickets raised against this TPV vendor's projects.
 *
 * A ticket has no vendor of its own and none was added. It reaches one through
 * its project — tickets.project_id → projects.vendor_id — so this is the EXISTING
 * helpdesk queue read one vendor at a time: GET /helpdesk/tickets?vendor_id=.
 * The backend resolves that vendor to project ids itself; nothing here decides
 * which tickets are allowed.
 *
 * A row opens the real ticket thread; there is no second ticket detail screen.
 *
 * Status colours come from the tenant's own helpdesk settings — the same source
 * the main ticket grid uses — so a renamed or recoloured status stays in step
 * instead of drifting on this one screen. Priority reuses the helpdesk SLABadge.
 */
export function VendorTickets({ vendorId, vendorName, manage }) {
  const navigate = useNavigate()
  const [rows, setRows] = useState([])
  const [statusColor, setStatusColor] = useState(() => () => '#64748b')
  const [settings, setSettings] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [adding, setAdding] = useState(false)
  const [projects, setProjects] = useState([])

  const load = useCallback(() => {
    setLoading(true); setError(null)
    helpdeskApi.tickets.list({ vendor_id: vendorId })
      .then(d => setRows(d?.data ?? d ?? []))
      .catch(e => setError(e?.title || e?.message || 'Could not load tickets.'))
      .finally(() => setLoading(false))
  }, [vendorId])

  useEffect(() => {
    let live = true
    load()

    // A ticket reaches this vendor only through a PROJECT, so the picker offers
    // this vendor's projects and nothing else — the link cannot be typed.
    projectApi.list({ vendor_id: vendorId })
      .then(d => { if (live) setProjects(d?.data ?? d ?? []) })
      .catch(() => {})

    // Colours are a nicety — a settings failure must not blank the table.
    helpdeskApi.settings.all()
      .then(s => {
        if (!live) return
        const map = {}
        ;(s?.statuses || []).forEach(x => { map[x.name] = x.color })
        setSettings(s)
        setStatusColor(() => n => map[n] || '#64748b')
      })
      .catch(() => {})

    return () => { live = false }
  }, [vendorId, load])

  return (
    <>
    {/* The Helpdesk's OWN create form, not a reduced copy — same fields, same
        department routing, same validation and discard guard. The only addition
        is a required Project field, fed ONLY this vendor's projects: a ticket has
        no vendor column, so that link is what files it against this vendor. */}
    {adding && (
      <NewTicketModal
        title={vendorName ? `New Ticket — ${vendorName}` : 'New Ticket'}
        settings={settings}
        projects={projects}
        draft={{ source: 'tpv' }}
        onClose={() => setAdding(false)}
        onCreated={() => { setAdding(false); load() }}
      />
    )}
    <SectionTable
      icon={LifeBuoy} title="Tickets" loading={loading} error={error} rows={rows}
      hint="Tickets raised on this vendor's projects. Select a row to open the ticket."
      searchFields={['subject', 'status', 'priority', 'requester_name']}
      onAdd={manage ? () => setAdding(true) : undefined} addLabel="Add Ticket"
      onRowClick={t => navigate(`/app/helpdesk/tickets/${t.id}`)}
      columns={[
        { key: 'id', label: '#', render: t => <span style={{ color: 'var(--text-muted)' }}>{t.id}</span> },
        { key: 'subject', label: 'Subject', render: t => <span style={{ fontWeight: 700 }}>{t.subject}</span> },
        {
          key: 'status',
          label: 'Status',
          render: t => {
            const c = statusColor(t.status)
            return (
              <span style={{
                display: 'inline-flex', padding: '2px 8px', borderRadius: 20,
                fontSize: 11, fontWeight: 700, color: c, background: `${c}1a`,
              }}>{t.status || '—'}</span>
            )
          },
        },
        { key: 'priority', label: 'Priority', render: t => <SLABadge priority={t.priority} showSla={false} /> },
        { key: 'project', label: 'Project', render: t => t.project?.name || '—' },
        { key: 'assignee', label: 'Assigned', render: t => t.assignee?.name || 'Unassigned' },
        { key: 'created_at', label: 'Created', render: t => t.created_at ? new Date(t.created_at).toLocaleDateString() : '—' },
        { key: 'updated_at', label: 'Updated', render: t => t.updated_at ? new Date(t.updated_at).toLocaleDateString() : '—' },
      ]}
    />
    </>
  )
}
