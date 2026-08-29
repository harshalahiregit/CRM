import { useEffect, useState } from 'react'
import { Loader2 } from 'lucide-react'

/**
 * Vendor-360 governance panels — read-only views that surface, ON THE VENDOR
 * RECORD, data that already lived only in the standalone TPV registers. Each one
 * reads an EXISTING vendor-scoped endpoint (no new API, no second copy):
 *   Performance Index  → api.vpi.vendor              Renewal   → api.renewals.assess/list
 *   Offboarding        → api.offboardings.list       Compliance→ api.vendorCompliance.matrix
 *   Inspections/NCR/CAPA → api.*.list({vendor_id})    Work Pkgs → api.workPackages.list
 *   Vault              → api.documentVault.vendor
 * Management of each still lives in its dedicated module page; here it is a
 * consolidated read so opening a vendor tells its whole story.
 */

const card = { background: 'var(--bg-card, rgba(255,255,255,0.03))', border: '1px solid var(--border, rgba(148,163,184,0.2))', borderRadius: 12, padding: 16 }
const th = { textAlign: 'left', fontSize: 10.5, textTransform: 'uppercase', letterSpacing: '0.05em', color: 'var(--text-muted, #9ca3af)', padding: '8px 12px', borderBottom: '1px solid var(--border, rgba(148,163,184,0.2))', whiteSpace: 'nowrap' }
const td = { padding: '10px 12px', borderBottom: '1px solid var(--border, rgba(148,163,184,0.12))', color: 'var(--text-body, #cbd5e1)', fontSize: 13 }
const date = v => (v ? String(v).slice(0, 10) : '—')
const fmt = v => (v == null || v === '' ? '—' : String(v).replace(/_/g, ' '))
const Spin = () => <div style={{ display: 'flex', justifyContent: 'center', padding: 48 }}><Loader2 className="tpv-spin" size={22} /></div>
const Empty = ({ text }) => <div style={{ ...card, color: 'var(--text-muted, #9ca3af)', fontSize: 14 }}>{text}</div>
const H = ({ children, sub }) => (
  <div style={{ marginBottom: 14 }}>
    <h3 style={{ fontSize: 16, fontWeight: 800, color: 'var(--text-h, #e5e7eb)', margin: 0 }}>{children}</h3>
    {sub && <p style={{ fontSize: 12, color: 'var(--text-muted, #9ca3af)', margin: '3px 0 0' }}>{sub}</p>}
  </div>
)

const scoreTone = s => (s >= 85 ? '#10b981' : s >= 70 ? '#0ea5e9' : s >= 55 ? '#f59e0b' : '#ef4444')
const STATUS_TONE = {
  Open: '#f59e0b', Raised: '#f59e0b', Assigned: '#0ea5e9', 'In_Progress': '#0ea5e9', Action: '#0ea5e9',
  Verification: '#8b5cf6', Closed: '#10b981', Done: '#10b981', Verified: '#10b981', Compliant: '#10b981',
  'Non_Compliant': '#ef4444', Expired: '#ef4444', Expiring: '#f59e0b', 'Partially_Compliant': '#f59e0b',
}
const Pill = ({ value }) => {
  const tone = STATUS_TONE[value] || '#94a3b8'
  return <span style={{ display: 'inline-flex', padding: '3px 10px', borderRadius: 20, fontSize: 11, fontWeight: 700, background: `${tone}22`, color: tone, whiteSpace: 'nowrap' }}>{fmt(value)}</span>
}

// Generic hook: fetch once per vendor, expose rows|null (null = loading).
function useRows(fetcher, dep) {
  const [rows, setRows] = useState(null)
  useEffect(() => {
    let alive = true
    Promise.resolve(fetcher()).then(d => { if (alive) setRows(Array.isArray(d) ? d : (d?.data ?? [])) }).catch(() => { if (alive) setRows([]) })
    return () => { alive = false }
  }, [dep]) // eslint-disable-line react-hooks/exhaustive-deps
  return rows
}

// A read-only table driven by a column spec: [label, rowFn].
function Table({ rows, cols, empty }) {
  if (rows === null) return <Spin />
  if (!rows.length) return <Empty text={empty} />
  return (
    <div style={{ ...card, padding: '6px 4px' }}>
      <div style={{ overflowX: 'auto' }}>
        <table style={{ width: '100%', borderCollapse: 'collapse' }}>
          <thead><tr>{cols.map(([l]) => <th key={l} style={th}>{l}</th>)}</tr></thead>
          <tbody>
            {rows.map((r, i) => <tr key={r.id ?? i}>{cols.map(([l, fn]) => <td key={l} style={td}>{fn(r)}</td>)}</tr>)}
          </tbody>
        </table>
      </div>
    </div>
  )
}

/* ── Performance Index (VPI, §27) ─────────────────────────────────────────── */
export function VendorVpiPanel({ vendorId, api }) {
  const [d, setD] = useState(undefined) // undefined=loading, null=none
  useEffect(() => { let a = true; api.vpi.vendor(vendorId).then(x => a && setD(x || null)).catch(() => a && setD(null)); return () => { a = false } }, [vendorId]) // eslint-disable-line react-hooks/exhaustive-deps
  if (d === undefined) return <Spin />
  if (!d) return <Empty text="No performance index computed for this vendor yet." />
  const dims = Object.entries(d.dimensions || {})
  return (
    <div>
      <H sub="Eight-dimension weighted index (VRS + governance), A–E banded.">Performance Index</H>
      <div style={{ ...card, marginBottom: 14, display: 'flex', alignItems: 'center', gap: 22, flexWrap: 'wrap' }}>
        <div><div style={{ fontSize: 11, color: 'var(--text-muted)', fontWeight: 700 }}>OVERALL</div><div style={{ fontSize: 30, fontWeight: 900, color: scoreTone(d.overall_score) }}>{d.overall_score ?? '—'}</div></div>
        <div><div style={{ fontSize: 11, color: 'var(--text-muted)', fontWeight: 700 }}>BAND</div><div style={{ fontSize: 22, fontWeight: 900, color: scoreTone(d.overall_score) }}>{d.band ?? '—'}</div></div>
        {d.vrs_band && <div><div style={{ fontSize: 11, color: 'var(--text-muted)', fontWeight: 700 }}>VRS BAND</div><div style={{ fontSize: 18, fontWeight: 800, color: 'var(--text-h)' }}>{d.vrs_band}</div></div>}
      </div>
      {dims.length > 0 && (
        <div style={{ ...card, display: 'flex', flexDirection: 'column', gap: 12 }}>
          {dims.map(([k, dim]) => {
            const score = typeof dim === 'object' ? (dim.score ?? dim.value ?? 0) : dim
            const label = (typeof dim === 'object' && dim.label) ? dim.label : k.replace(/_/g, ' ')
            return (
              <div key={k}>
                <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 12.5, marginBottom: 4 }}>
                  <span style={{ color: 'var(--text-h)', fontWeight: 600, textTransform: 'capitalize' }}>{label}</span>
                  <span style={{ fontWeight: 900, color: scoreTone(score) }}>{score}</span>
                </div>
                <div style={{ height: 6, borderRadius: 4, background: 'var(--bg-input)' }}><div style={{ width: `${Math.max(0, Math.min(100, score))}%`, height: '100%', borderRadius: 4, background: scoreTone(score) }} /></div>
              </div>
            )
          })}
        </div>
      )}
    </div>
  )
}

/* ── Renewal & Extension (§28) ────────────────────────────────────────────── */
export function VendorRenewalPanel({ vendorId, api }) {
  const [a, setA] = useState(undefined)
  const rows = useRows(() => api.renewals.list({ vendor_id: vendorId }), vendorId)
  useEffect(() => { let m = true; api.renewals.assess(vendorId).then(x => m && setA(x || null)).catch(() => m && setA(null)); return () => { m = false } }, [vendorId]) // eslint-disable-line react-hooks/exhaustive-deps
  const Stat = ({ label, value }) => (
    <div style={{ minWidth: 120 }}><div style={{ fontSize: 10.5, color: 'var(--text-muted)', fontWeight: 700, textTransform: 'uppercase' }}>{label}</div><div style={{ fontSize: 16, fontWeight: 800, color: 'var(--text-h)' }}>{value}</div></div>
  )
  return (
    <div>
      <H sub="Performance-driven renewal readiness + decision history.">Renewal & Extension</H>
      {a === undefined ? <Spin /> : a && (
        <div style={{ ...card, marginBottom: 16, display: 'flex', gap: 22, flexWrap: 'wrap' }}>
          <Stat label="VRS" value={a.vrs_score != null ? `${a.vrs_score} (${a.vrs_band || '—'})` : '—'} />
          <Stat label="Compliance %" value={a.compliance?.percent != null ? `${a.compliance.percent}%` : '—'} />
          <Stat label="Open NCRs" value={a.open_ncrs ?? 0} />
          <Stat label="Open CAPAs" value={a.open_capas ?? 0} />
          <Stat label="Active strikes" value={a.active_strikes ?? 0} />
          <Stat label="Violation level" value={fmt(a.violation_level)} />
          <Stat label="Active contracts" value={a.contract ? `${a.contract.active}/${a.contract.total}` : '—'} />
          <Stat label="Workforce (active)" value={a.workforce ? `${a.workforce.active_workers}/${a.workforce.total_workers}` : '—'} />
          <Stat label="Client feedback" value={a.client_feedback?.vrs_band ? `VRS ${a.client_feedback.vrs_band}` : '—'} />
        </div>
      )}
      <Table rows={rows} empty="No renewal cycle has been initiated for this vendor yet."
        cols={[
          ['Reference', r => r.reference || `#${r.id}`],
          ['Status', r => <Pill value={r.status} />],
          ['Decision', r => fmt(r.decision)],
          ['Initiated', r => date(r.created_at)],
        ]} />
    </div>
  )
}

/* ── Offboarding / Closure (§29) ──────────────────────────────────────────── */
export function VendorOffboardingPanel({ vendorId, api }) {
  const rows = useRows(() => api.offboardings.list({ vendor_id: vendorId }), vendorId)
  return (
    <div>
      <H sub="Controlled exit checklist and final closure status.">Offboarding & Closure</H>
      <Table rows={rows} empty="No offboarding has been initiated for this vendor."
        cols={[
          ['Reference', r => r.reference || `#${r.id}`],
          ['Status', r => <Pill value={r.status} />],
          ['Final status', r => fmt(r.final_status)],
          ['Initiated', r => date(r.created_at || r.initiated_at)],
          ['Completed', r => date(r.completed_at)],
        ]} />
    </div>
  )
}

/* ── Compliance register (§21) ────────────────────────────────────────────── */
export function VendorComplianceRegisterPanel({ vendorId, api }) {
  const [d, setD] = useState(undefined)
  useEffect(() => { let m = true; api.vendorCompliance.matrix(vendorId).then(x => m && setD(x || null)).catch(() => m && setD(null)); return () => { m = false } }, [vendorId]) // eslint-disable-line react-hooks/exhaustive-deps
  if (d === undefined) return <Spin />
  const rows = Array.isArray(d) ? d : (d?.categories ?? d?.items ?? d?.data ?? [])
  return (
    <div>
      <H sub="Per-category statutory & HSE compliance for this vendor (§21).">Compliance Register</H>
      <Table rows={rows} empty="No compliance categories recorded for this vendor yet."
        cols={[
          ['Category', r => r.category_label || fmt(r.category)],
          ['Status', r => <Pill value={r.status} />],
          ['Valid until', r => date(r.valid_until)],
          ['Reference', r => r.reference_no || r.reference || '—'],
        ]} />
    </div>
  )
}

/* ── Inspections & Audits (§22) ───────────────────────────────────────────── */
export function VendorInspectionsPanel({ vendorId, api }) {
  const rows = useRows(() => api.inspections.list({ vendor_id: vendorId }), vendorId)
  return (
    <div>
      <H sub="Planned & completed inspections and their findings (§22).">Inspections & Audits</H>
      <Table rows={rows} empty="No inspections recorded for this vendor."
        cols={[
          ['Reference', r => r.reference || `#${r.id}`],
          ['Type', r => fmt(r.type || r.inspection_type)],
          ['Status', r => <Pill value={r.status} />],
          ['Findings', r => r.findings_count ?? (r.findings?.length ?? '—')],
          ['Date', r => date(r.scheduled_at || r.inspected_at || r.created_at)],
        ]} />
    </div>
  )
}

/* ── NCR (§24) ────────────────────────────────────────────────────────────── */
export function VendorNcrPanel({ vendorId, api }) {
  const rows = useRows(() => api.ncrs.list({ vendor_id: vendorId }), vendorId)
  return (
    <div>
      <H sub="Non-Conformance Reports raised against this vendor (§24).">NCR</H>
      <Table rows={rows} empty="No NCRs raised for this vendor."
        cols={[
          ['Reference', r => r.reference || `#${r.id}`],
          ['Finding', r => r.title || r.finding || '—'],
          ['Severity', r => fmt(r.severity)],
          ['Status', r => <Pill value={r.status} />],
          ['Due', r => date(r.due_date)],
        ]} />
    </div>
  )
}

/* ── CAPA (§25) ───────────────────────────────────────────────────────────── */
export function VendorCapaPanel({ vendorId, api }) {
  const rows = useRows(() => api.capas.list({ vendor_id: vendorId }), vendorId)
  return (
    <div>
      <H sub="Corrective & preventive actions linked to this vendor (§25).">CAPA</H>
      <Table rows={rows} empty="No CAPAs linked to this vendor."
        cols={[
          ['Reference', r => r.reference || `#${r.id}`],
          ['Title', r => r.title || '—'],
          ['Source', r => fmt(r.source_kind)],
          ['Status', r => <Pill value={r.status} />],
          ['Due', r => date(r.due_date)],
        ]} />
    </div>
  )
}

/* ── Work Packages & Activities (§13) ─────────────────────────────────────── */
export function VendorWorkPackagesPanel({ vendorId, api }) {
  const rows = useRows(() => api.workPackages.list({ vendor_id: vendorId }), vendorId)
  return (
    <div>
      <H sub="What this vendor is engaged to execute — the accountability spine (§13).">Work Packages</H>
      <Table rows={rows} empty="No work packages assigned to this vendor."
        cols={[
          ['Reference', r => r.reference || `#${r.id}`],
          ['Name', r => r.name || '—'],
          ['Status', r => <Pill value={r.status} />],
          ['Activities', r => r.activities_count ?? '—'],
          ['Workers', r => r.workers_count ?? '—'],
        ]} />
    </div>
  )
}

/* ── Document Vault (§30) ─────────────────────────────────────────────────── */
export function VendorVaultPanel({ vendorId, api }) {
  const [d, setD] = useState(undefined)
  useEffect(() => { let m = true; api.documentVault.vendor(vendorId).then(x => m && setD(x)).catch(() => m && setD(null)); return () => { m = false } }, [vendorId]) // eslint-disable-line react-hooks/exhaustive-deps
  if (d === undefined) return <Spin />
  const rows = Array.isArray(d) ? d : (d?.documents ?? d?.items ?? d?.data ?? [])
  return (
    <div>
      <H sub="Every document held for this vendor, with expiry that drives risk (§30).">Document Vault</H>
      <Table rows={rows} empty="No documents in the vault for this vendor yet."
        cols={[
          ['Document', r => r.name || r.title || fmt(r.type)],
          ['Category', r => fmt(r.category || r.type)],
          ['Status', r => <Pill value={r.status} />],
          ['Version', r => r.version ?? '—'],
          ['Expires', r => date(r.expires_at || r.valid_until)],
        ]} />
    </div>
  )
}
