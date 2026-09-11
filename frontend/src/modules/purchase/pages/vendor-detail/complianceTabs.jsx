import { useNavigate } from 'react-router-dom'
import { purchaseApi } from '@/services/purchaseApi'
import { fmtDate } from '@/modules/purchase/constants'
import { useVendorWorkspace } from './vendorWorkspaceContext'
import { VendorScopedList } from './vendorDetailShared'
import PurchaseVendorDocumentsReadOnly from '@/modules/purchase/components/PurchaseVendorDocumentsReadOnly'

/**
 * The registers a Purchase vendor is accountable under, on the vendor itself.
 *
 * TPV's workspace carries thirteen Compliance entries and eight Performance
 * ones; Purchase carried two and five. That was never a backend gap — every
 * endpoint below already existed, already accepted vendor_id, and already
 * applied it in its service. What was missing was the tab, so the only way to
 * answer "what is open against THIS vendor" on the Purchase side was to open
 * each module register and filter it by hand.
 *
 * Each tab is a thin vendor-scoped view over the module's own list endpoint —
 * not a second copy of it. Rows click through to the full module screen, which
 * stays the one place a record is worked on.
 */

/* ── Shared cell helpers ─────────────────────────────────────────────────── */

const tone = (color) => ({ color, bg: color.replace('rgb', 'rgba').replace(')', ',0.15)') })

/** Open/closed style status pill, with the vocabulary each register uses. */
const statusTone = (open, warn = []) => (st) => {
  const s = String(st || '')
  if (warn.includes(s)) return { label: s.replace(/_/g, ' '), color: '#f59e0b', bg: 'rgba(245,158,11,0.15)' }
  const isOpen = open.includes(s)
  return {
    label: s.replace(/_/g, ' ') || '—',
    color: isOpen ? '#ef4444' : '#10b981',
    bg: isOpen ? 'rgba(239,68,68,0.15)' : 'rgba(16,185,129,0.15)',
  }
}

const severityTone = (sv) => {
  const s = String(sv || '')
  const color = s === 'Critical' || s === 'High' ? '#ef4444' : s === 'Medium' ? '#f59e0b' : '#0ea5e9'
  return { label: s || '—', color, bg: color === '#ef4444' ? 'rgba(239,68,68,0.15)' : color === '#f59e0b' ? 'rgba(245,158,11,0.15)' : 'rgba(14,165,233,0.15)' }
}

/* ── Compliance group ────────────────────────────────────────────────────── */

/**
 * The vendor's statutory documents — the real panel, not a summary of it.
 *
 * This tab used to be a `VendorScopedList` over the checklist endpoint, and it
 * showed "This vendor has filed no documents yet" for every vendor in the
 * system. Three things were wrong at once and each hid the next:
 *
 *   1. The endpoint answers an OBJECT — { vendor_type, required, extras,
 *      summary, complete } — and the fetcher unwrapped `r?.documents ??
 *      r?.data ?? r`, landing on the object itself.
 *   2. `VendorScopedList` then did `Array.isArray(r) ? r : (r?.data ?? [])`,
 *      and there is no `data` key, so it rendered the empty state.
 *   3. Had a row ever reached the table, the columns read `document_label`,
 *      `document_number`, `valid_until` and `version` — none of which this
 *      endpoint returns. Every cell would have been an em dash.
 *
 * Nothing threw, so no failure banner: PV-0002 had three documents uploaded
 * through the portal and approved by an admin, and this tab said none.
 *
 * A checklist is not a list of rows — it is a required set, an extras set and a
 * progress summary, with approve/reject and version history hanging off each
 * row. `PurchaseVendorDocumentsReadOnly` is that panel and already reads the
 * real shape; the tab is now a placement of it.
 */
export function VendorDocumentsTab() {
  const { vendor } = useVendorWorkspace()

  return (
    <div className="card-3d" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', borderRadius: 14, padding: 18 }}>
      <PurchaseVendorDocumentsReadOnly key={`doc-${vendor.id}`} vendorId={vendor.id} />
    </div>
  )
}

export function VendorComplianceRegisterTab() {
  const { vendor } = useVendorWorkspace()

  return (
    <VendorScopedList
      key={`cmp-${vendor.id}`}
      title="Compliance Register"
      fetcher={(vid) => purchaseApi.vendorCompliance.matrix(vid).then(r => r?.matrix ?? r?.data ?? r ?? [])}
      emptyText="No compliance categories are being tracked for this vendor"
      statusCfg={statusTone(['Non_Compliant', 'Expired'], ['Partially_Compliant', 'Expiring', 'Under_Review'])}
      columns={[
        { header: 'Category', strong: true, cell: (r) => r.category_label || r.category || '—' },
        { header: 'Requirement', cell: (r) => r.requirement || '—' },
        { header: 'Valid until', cell: (r) => (r.valid_until ? fmtDate(r.valid_until) : '—') },
      ]}
    />
  )
}

export function VendorInspectionsTab() {
  const { vendor } = useVendorWorkspace()
  const navigate = useNavigate()

  return (
    <VendorScopedList
      key={`insp-${vendor.id}`}
      title="Inspections"
      fetcher={(vid) => purchaseApi.inspections.list({ vendor_id: vid })}
      emptyText="No inspections recorded against this vendor"
      statusCfg={statusTone(['Open', 'Failed', 'In_Progress'], ['Scheduled'])}
      onRowClick={() => navigate('/app/purchase/inspections')}
      columns={[
        { header: 'Reference', strong: true, cell: (r) => r.reference_no || r.reference || `#${r.id}` },
        { header: 'Type', cell: (r) => (r.type || '—').replace(/_/g, ' ') },
        { header: 'Date', cell: (r) => (r.inspected_at || r.scheduled_at ? fmtDate(r.inspected_at || r.scheduled_at) : '—') },
        { header: 'Result', cell: (r) => r.result || '—' },
      ]}
    />
  )
}

export function VendorNcrTab() {
  const { vendor } = useVendorWorkspace()
  const navigate = useNavigate()

  return (
    <VendorScopedList
      key={`ncr-${vendor.id}`}
      title="NCRs"
      fetcher={(vid) => purchaseApi.ncrs.list({ vendor_id: vid })}
      emptyText="No non-conformance reports against this vendor"
      statusCfg={statusTone(['Open', 'Under_Review'], ['Pending_Verification'])}
      onRowClick={() => navigate('/app/purchase/ncr')}
      columns={[
        { header: 'Reference', strong: true, cell: (r) => r.reference_no || `#${r.id}` },
        { header: 'Title', cell: (r) => r.title || '—' },
        { header: 'Severity', cell: (r) => r.severity || '—' },
        { header: 'Raised', cell: (r) => (r.raised_at || r.created_at ? fmtDate(r.raised_at || r.created_at) : '—') },
      ]}
    />
  )
}

export function VendorCapaTab() {
  const { vendor } = useVendorWorkspace()
  const navigate = useNavigate()

  return (
    <VendorScopedList
      key={`capa-${vendor.id}`}
      title="CAPAs"
      fetcher={(vid) => purchaseApi.capas.list({ vendor_id: vid })}
      emptyText="No corrective or preventive actions for this vendor"
      statusCfg={statusTone(['Open', 'In_Progress'], ['Pending_Verification', 'Overdue'])}
      onRowClick={() => navigate('/app/purchase/capa')}
      columns={[
        { header: 'Reference', strong: true, cell: (r) => r.reference_no || `#${r.id}` },
        { header: 'Title', cell: (r) => r.title || '—' },
        { header: 'Type', cell: (r) => (r.type || '—').replace(/_/g, ' ') },
        { header: 'Due', cell: (r) => (r.due_date ? fmtDate(r.due_date) : '—') },
      ]}
    />
  )
}

export function VendorPermitsTab() {
  const { vendor } = useVendorWorkspace()
  const navigate = useNavigate()

  return (
    <VendorScopedList
      key={`ptw-${vendor.id}`}
      title="Permits to Work"
      fetcher={(vid) => purchaseApi.permits.list({ vendor_id: vid })}
      emptyText="No permits to work raised for this vendor"
      statusCfg={statusTone(['Rejected', 'Expired'], ['Draft', 'Pending_Approval'])}
      onRowClick={() => navigate('/app/purchase/permits')}
      columns={[
        { header: 'Permit', strong: true, cell: (r) => r.permit_no || r.reference_no || `#${r.id}` },
        { header: 'Type', cell: (r) => (r.type || '—').replace(/_/g, ' ') },
        { header: 'Valid from', cell: (r) => (r.valid_from ? fmtDate(r.valid_from) : '—') },
        { header: 'Valid to', cell: (r) => (r.valid_to ? fmtDate(r.valid_to) : '—') },
      ]}
    />
  )
}

export function VendorIncidentsTab() {
  const { vendor } = useVendorWorkspace()
  const navigate = useNavigate()

  return (
    <VendorScopedList
      key={`inc-${vendor.id}`}
      title="Incidents"
      fetcher={(vid) => purchaseApi.incidents.list({ vendor_id: vid })}
      emptyText="No incidents recorded against this vendor"
      statusCfg={statusTone(['Open', 'Under_Investigation'])}
      onRowClick={() => navigate('/app/purchase/incidents')}
      columns={[
        { header: 'Reference', strong: true, cell: (r) => r.reference_no || `#${r.id}` },
        { header: 'Title', cell: (r) => r.title || r.description || '—' },
        { header: 'Severity', cell: (r) => severityTone(r.severity).label },
        { header: 'Occurred', cell: (r) => (r.occurred_at ? fmtDate(r.occurred_at) : '—') },
      ]}
    />
  )
}

/**
 * The site visitor book — site-wide, not this vendor's.
 *
 * `site_visitors` is a shared register with no vendor column: a visitor signs
 * in at the gate against a host and a company, not against a purchase_vendor
 * row. `SiteRegisterController::visitors` therefore scopes by tenant and
 * nothing else, and the `vendor_id` this tab was sending was accepted and
 * ignored — every vendor's workspace showed the whole site's book under the
 * heading "Visitors" and the empty state "No visitors logged for this vendor".
 * A visitor for another vendor read as a visitor for this one.
 *
 * Three columns were dead on top of that: `name` and `checked_in_at` are not
 * fields this table has (`visitor_name`, `check_in_at`), and the status pill
 * asked for a `status` column that does not exist — so every row, including
 * people who signed out weeks ago, drew as "On site".
 *
 * Until a visit can be attributed to a vendor, the honest thing is to say what
 * this is. Scoping it for real needs a vendor column on a register TPV and
 * Purchase share, which is a schema change on another team's table.
 */
export function VendorVisitorsTab() {
  const { vendor } = useVendorWorkspace()

  return (
    <VendorScopedList
      key={`vis-${vendor.id}`}
      title="Visitors — site register"
      fetcher={() => purchaseApi.visitors.list()}
      emptyText="No visitors have signed in at the gate"
      statusCfg={(_st, row) => (row?.check_out_at
        ? { label: 'Departed', color: '#94a3b8', bg: 'rgba(148,163,184,0.15)' }
        : { label: 'On site', color: '#10b981', bg: 'rgba(16,185,129,0.15)' })}
      columns={[
        { header: 'Visitor', strong: true, cell: (r) => r.visitor_name || '—' },
        { header: 'Company', cell: (r) => r.company || '—' },
        { header: 'Host', cell: (r) => r.host || '—' },
        { header: 'Purpose', cell: (r) => r.purpose || '—' },
        { header: 'In', cell: (r) => (r.check_in_at ? fmtDate(r.check_in_at) : '—') },
      ]}
    />
  )
}

/* ── Operations group ────────────────────────────────────────────────────── */

export function VendorWorkPackagesTab() {
  const { vendor } = useVendorWorkspace()

  return (
    <VendorScopedList
      key={`wp-${vendor.id}`}
      title="Work Packages"
      fetcher={(vid) => purchaseApi.workPackages.list({ vendor_id: vid })}
      emptyText="No work packages assigned to this vendor"
      statusCfg={statusTone(['Draft', 'On_Hold'], ['In_Progress'])}
      columns={[
        { header: 'Package', strong: true, cell: (r) => r.name || `#${r.id}` },
        // purchase_work_packages numbers itself in `reference`; `code` and
        // `reference_no` are not columns, so this read as an em dash always.
        { header: 'Code', cell: (r) => r.reference || '—' },
        { header: 'Starts', cell: (r) => (r.start_date ? fmtDate(r.start_date) : '—') },
        { header: 'Ends', cell: (r) => (r.end_date ? fmtDate(r.end_date) : '—') },
      ]}
    />
  )
}

/* ── Performance group ───────────────────────────────────────────────────── */

export function VendorRenewalTab() {
  const { vendor } = useVendorWorkspace()
  const navigate = useNavigate()

  return (
    <VendorScopedList
      key={`ren-${vendor.id}`}
      title="Renewal"
      fetcher={(vid) => purchaseApi.renewals.list({ vendor_id: vid })}
      emptyText="No renewal has been raised for this vendor"
      statusCfg={statusTone(['Rejected', 'Lapsed'], ['Pending', 'Under_Review'])}
      onRowClick={() => navigate('/app/purchase/renewals')}
      columns={[
        { header: 'Cycle', strong: true, cell: (r) => r.cycle || r.reference_no || `#${r.id}` },
        { header: 'Due', cell: (r) => (r.due_date || r.expires_at ? fmtDate(r.due_date || r.expires_at) : '—') },
        { header: 'Decision', cell: (r) => (r.decision || '—').replace(/_/g, ' ') },
        { header: 'Decided', cell: (r) => (r.decided_at ? fmtDate(r.decided_at) : '—') },
      ]}
    />
  )
}

export function VendorOffboardingTab() {
  const { vendor } = useVendorWorkspace()
  const navigate = useNavigate()

  return (
    <VendorScopedList
      key={`off-${vendor.id}`}
      title="Offboarding"
      fetcher={(vid) => purchaseApi.offboardings.list({ vendor_id: vid })}
      emptyText="This vendor is not being offboarded"
      statusCfg={statusTone(['Blocked'], ['In_Progress', 'Pending'])}
      onRowClick={() => navigate('/app/purchase/offboarding')}
      columns={[
        { header: 'Reference', strong: true, cell: (r) => r.reference_no || `#${r.id}` },
        { header: 'Reason', cell: (r) => (r.reason || '—').replace(/_/g, ' ') },
        { header: 'Initiated', cell: (r) => (r.initiated_at || r.created_at ? fmtDate(r.initiated_at || r.created_at) : '—') },
        { header: 'Completed', cell: (r) => (r.completed_at ? fmtDate(r.completed_at) : '—') },
      ]}
    />
  )
}
