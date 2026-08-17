import { useState, useEffect, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { FolderKanban } from 'lucide-react'
import { projectApi, PROJECT_STATUS } from '@/services/projectApi'
import { SectionTable } from './VendorSectionTable'
import ProjectFormDrawer from '@/modules/projects/components/ProjectFormDrawer'

/**
 * Projects awarded to this TPV vendor.
 *
 * A project already records its vendor link on the projects table
 * (vendor_id + link_type), so this is the EXISTING project list read one vendor
 * at a time — GET /projects?vendor_id= — not a TPV-side project store. There is
 * one set of projects; this is a second view of it, and a row opens the real
 * project detail page rather than a reduced copy of it.
 *
 * The endpoint sits behind role:admin,staff, so the portal roles never reach it.
 */

/** Thin bar — progress is a magnitude, and a number alone reads as noise in a row. */
function Progress({ value }) {
  const pct = Math.max(0, Math.min(100, Number(value) || 0))

  return (
    <span style={{ display: 'inline-flex', alignItems: 'center', gap: 8, minWidth: 96 }}>
      <span style={{ flex: 1, height: 4, borderRadius: 4, background: 'var(--border)', overflow: 'hidden' }}>
        <span style={{ display: 'block', width: `${pct}%`, height: '100%', background: '#a78bfa' }} />
      </span>
      <span style={{ fontSize: 11.5, color: 'var(--text-muted)', minWidth: 30, textAlign: 'right' }}>{pct}%</span>
    </span>
  )
}

const asDate = v => (v ? new Date(v).toLocaleDateString() : '—')

/**
 * Status wears the project module's own labels and colours (PROJECT_STATUS), not
 * a local guess — the stored values are snake_case ('in_progress'), so a vendor
 * screen inventing its own mapping would drift from the project list it links to.
 */
function StatusPill({ status }) {
  const meta = PROJECT_STATUS[status] || { label: status || '—', color: 'var(--text-muted)' }

  return (
    <span style={{
      display: 'inline-flex', padding: '2px 8px', borderRadius: 20,
      fontSize: 11, fontWeight: 700, color: meta.color, background: 'color-mix(in srgb, currentColor 12%, transparent)',
    }}>
      {meta.label}
    </span>
  )
}

export function VendorProjects({ vendorId, vendorName, manage }) {
  const navigate = useNavigate()
  const [rows, setRows] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [adding, setAdding] = useState(false)

  const load = useCallback(() => {
    setLoading(true); setError(null)

    projectApi.list({ vendor_id: vendorId })
      .then(d => setRows(d?.data ?? d ?? []))
      // projectApi routes failures through handleErr, which throws a normalised
      // ApiError (title/status) — not a raw axios error, so there is no
      // e.response.data.message to read here.
      .catch(e => setError(e?.title || e?.message || 'Could not load projects.'))
      .finally(() => setLoading(false))
  }, [vendorId])

  useEffect(() => { load() }, [load])

  return (
    <>
    {/* The project module's OWN form, not a reduced copy — a project raised here
        gets the same billing, members, tags, visible-tabs and portal-permission
        settings as one raised from the Projects screen. The only difference is
        that this vendor arrives pre-assigned and locked. */}
    <ProjectFormDrawer
      open={adding}
      onClose={() => setAdding(false)}
      onSaved={load}
      preset={{ link_type: 'tpv_vendor', vendor_id: vendorId }}
      presetLabel={vendorName}
    />
    <SectionTable
      icon={FolderKanban} title="Projects" loading={loading} error={error} rows={rows}
      hint="Projects this vendor is linked to. Select a row to open the project."
      onAdd={manage ? () => setAdding(true) : undefined} addLabel="Add Project"
      searchFields={['name', 'status', 'description']}
      onRowClick={p => navigate(`/app/projects/${p.id}`)}
      columns={[
        { key: 'name', label: 'Project', render: p => <span style={{ fontWeight: 700 }}>{p.name}</span> },
        { key: 'status', label: 'Status', render: p => <StatusPill status={p.status} /> },
        { key: 'progress', label: 'Progress', render: p => <Progress value={p.progress} /> },
        { key: 'start_date', label: 'Start', render: p => asDate(p.start_date) },
        { key: 'deadline', label: 'Deadline', render: p => asDate(p.deadline) },
      ]}
    />
    </>
  )
}
