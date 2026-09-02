import { useState, useEffect, useCallback, useMemo } from 'react'
import {
  ShieldCheck, Siren, AlertTriangle, Building2, HardHat, FileWarning,
  Loader2, RefreshCw, TrendingUp, Ban, ClipboardList, Info,
} from 'lucide-react'
import api from '@/lib/api'
import LoadError from '@/components/ui/LoadError'
import { Empty } from './PurchaseGateLog'
import { KIT3D_STYLE, StatusBadge } from '@/components/ui/kit3d'

/**
 * Purchase HSSE Governance — this module's standing safety posture.
 *
 * SCOPE, because this is the thing that is easy to get wrong here: this reads
 * PurchaseGovernanceService, which counts Purchase's OWN registers —
 * purchase_hsse_incidents, purchase_vendors, purchase_documents,
 * purchase_workers, purchase_worker_medicals, purchase_work_permits and
 * purchase_gate_scans.
 *
 * It deliberately does NOT reuse TPV's GovernanceDashboardService. That one
 * reads hsse_incidents, the shared `vendors` register and tpv_workers, so every
 * figure on it is a TPV figure. Printing "Active Vendors: 42" under a Purchase
 * heading from that source would be a straight claim about the Purchase vendor
 * master that is not true, and labelling it would not make a procurement lead
 * read it any differently. The site-wide view is still available, clearly named,
 * at /purchase/governance/shared-dashboard.
 *
 * Two TPV cards have no Purchase equivalent and were dropped rather than shown
 * as zero: safety strikes (no table here) and vendor ratings (scored over the
 * shared register; Purchase scores its own through VPI).
 */

// purchaseApi has no governance namespace — the shared-register aliases live
// here in exactly the shape it uses (`api.<verb>(…).then(r => r.data)`), ready
// to lift into services/purchaseApi.js unchanged.
//
// /purchase/governance/report (DPR/WPR/MCR) and /purchase/governance/
// authority-matrix are also aliased server-side, but they belong to their own
// screens the way TpvReports and TpvAuthorityMatrix hold them on the TPV side —
// this page is the dashboard only, and does not call them.
const governanceApi = {
  dashboard: () => api.get('/purchase/governance/dashboard').then(r => r.data),
}

const sevColor  = (s) => ({ Minor: '#10b981', Moderate: '#f59e0b', Serious: '#f97316', Fatal: '#ef4444' }[s] || '#94a3b8')
const bandColor = (b) => ({ A: '#10b981', B: '#0ea5e9', C: '#f59e0b', D: '#ef4444' }[b] || '#94a3b8')
const pretty    = (s) => (s || '').replace(/_/g, ' ')

// config/vrs.php names A/B/C, with D the implicit floor — but the bands map is
// config-driven, so whatever the server sends is rendered rather than assumed,
// with the known letters kept in their proper order.
const BAND_ORDER = ['A', 'B', 'C', 'D']
const bandKeys = (bands) => {
  const keys = Object.keys(bands || {})
  if (!keys.length) return BAND_ORDER
  return [...BAND_ORDER.filter(b => keys.includes(b)), ...keys.filter(k => !BAND_ORDER.includes(k)).sort()]
}

// `rfq-spin` is NOT defined globally — PurchaseRfqDetail injects its own copy,
// so a page relying on that class renders a spinner that does not spin.
const SPIN_STYLE = '@keyframes prGovSpin{to{transform:rotate(360deg)}}.pr-gov-spin{animation:prGovSpin .9s linear infinite}'

const fmtWhen = (d) => (d ? new Date(d).toLocaleString('en-IN', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit', hour12: false }) : '—')

export default function PurchaseGovernanceDashboard() {
  const [d, setD] = useState(null)
  const [loading, setLoading] = useState(true)
  const [loadError, setError] = useState(null)

  const load = useCallback(async () => {
    setLoading(true)
    try { setD(await governanceApi.dashboard()); setError(null) }
    catch (e) { setD(null); setError(e) }
    finally { setLoading(false) }
  }, [])
  useEffect(() => { load() }, [load])

  // Every section is defaulted rather than read straight off `d`. A partial
  // payload would otherwise take the whole page down on `inc.open_by_severity`,
  // and a governance board that renders nothing is worse than one missing a card.
  const inc = d?.incidents || {}
  const ven = d?.vendors   || {}
  const rat = d?.ratings   || {}
  const wf  = d?.workforce || {}
  const per = d?.permits   || {}
  const gate = d?.gate     || {}
  const severities = inc.open_by_severity || {}

  // Grouped by register, because these are several different Purchase
  // registers and only the grouping makes each figure's source legible.
  const groups = useMemo(() => [
    {
      key: 'incidents',
      title: 'Site HSSE incidents',
      note: 'From purchase_hsse_incidents — this module’s own incident register.',
      cards: [
        { label: 'Open Incidents', value: inc.open,       icon: Siren,         color: inc.open ? '#ef4444' : '#10b981' },
        { label: 'Stop-Works',     value: inc.stop_works, icon: Ban,           color: inc.stop_works ? '#f97316' : '#94a3b8' },
        { label: 'Recorded (all)', value: inc.total,      icon: ClipboardList, color: '#818cf8' },
      ],
    },
    {
      key: 'vendors',
      title: 'Vendor register',
      note: 'The Purchase vendor master, and the documents on it lapsing within 30 days.',
      cards: [
        { label: 'Active Vendors',    value: ven.active,        icon: Building2,      color: '#0ea5e9' },
        { label: 'Suspended Vendors', value: ven.suspended,     icon: AlertTriangle,  color: ven.suspended ? '#f97316' : '#10b981' },
        { label: 'Docs Expiring 30d', value: ven.expiring_docs, icon: FileWarning,    color: ven.expiring_docs ? '#f59e0b' : '#10b981' },
      ],
    },
    {
      key: 'workforce',
      title: 'Workforce',
      note: 'Purchase workers — the badge register, and the medical certificates about to lapse.',
      cards: [
        { label: 'Active Workers',     value: wf.active_workers,   icon: HardHat,     color: '#a78bfa' },
        // Badged, not "strikes": Purchase has no strike register, and an
        // always-zero card reads as "no strikes" rather than "not tracked here".
        { label: 'Badged',             value: wf.badged,           icon: ShieldCheck, color: '#10b981' },
        { label: 'Medicals Expiring',  value: wf.medical_expiring, icon: FileWarning, color: wf.medical_expiring ? '#f59e0b' : '#10b981' },
      ],
    },
    {
      key: 'permits',
      title: 'Permit to work',
      note: 'Raised and awaiting a decision, live on site, and live permits already past their window.',
      cards: [
        { label: 'Awaiting Decision',  value: per.requested, icon: FileWarning, color: per.requested ? '#f59e0b' : '#10b981' },
        { label: 'Active Permits',     value: per.active,    icon: ShieldCheck, color: '#a78bfa' },
        { label: 'Past Validity',      value: per.lapsing,   icon: FileWarning, color: per.lapsing ? '#ef4444' : '#10b981' },
      ],
    },
    {
      key: 'gate',
      title: 'Site gate today',
      note: 'Badge scans recorded today. A refusal means the person did not enter.',
      cards: [
        { label: 'Scans Today',        value: gate.scans_today,  icon: HardHat,     color: '#a78bfa' },
        { label: 'Refused Today',      value: gate.denied_today, icon: ShieldCheck, color: gate.denied_today ? '#ef4444' : '#10b981' },
      ],
    },
  ], [inc, ven, wf, per, gate])

  return (
    <div style={{ padding: '24px 32px' }}>
      <style>{KIT3D_STYLE}{SPIN_STYLE}</style>

      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 16, flexWrap: 'wrap', gap: 12 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          <ShieldCheck size={22} style={{ color: '#7C3AED' }} />
          <div>
            <h1 style={{ margin: 0, fontSize: 22, fontWeight: 900, color: 'var(--text-h)', letterSpacing: '-0.02em' }}>HSSE Governance</h1>
            <p style={{ margin: '4px 0 0', fontSize: 13, color: 'var(--text-muted)' }}>
              Incidents, vendor ratings, document currency and workforce readiness — derived live, never cached.
            </p>
          </div>
        </div>
        <div style={{ display: 'flex', gap: 10, alignItems: 'center' }}>
          {/* The service stamps every build; showing it is the difference between
              a live board and one nobody can date. */}
          {d?.generated_at && (
            <span style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>as at {fmtWhen(d.generated_at)}</span>
          )}
          <button onClick={load} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 14px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', fontWeight: 700, cursor: 'pointer', fontSize: 13 }}>
            <RefreshCw size={14} />
          </button>
        </div>
      </div>

      {/* Said once, at the top, before a single number is read. */}
      <div className="pr-glass" style={{ display: 'flex', alignItems: 'flex-start', gap: 10, padding: '12px 16px', marginBottom: 18, borderRadius: 14 }}>
        <Info size={15} style={{ color: '#a78bfa', flexShrink: 0, marginTop: 1 }} />
        <p style={{ margin: 0, fontSize: 12, color: 'var(--text-muted)', lineHeight: 1.6 }}>
          Every figure below counts <strong style={{ color: 'var(--text-h)' }}>Purchase&rsquo;s own registers</strong> — its
          incidents, vendors, workers, permits and gate scans. TPV&rsquo;s equivalents are <strong style={{ color: 'var(--text-h)' }}>not</strong> included.
          The shared site-wide safety records — observations, toolbox talks, drills and evidence — have their own pages
          under Safety, Site Registers and Evidence Locker.
        </p>
      </div>

      {loadError ? (
        <LoadError error={loadError} onRetry={load} title="Could not load the governance dashboard" />
      ) : loading ? (
        <div style={{ textAlign: 'center', padding: 60, color: 'var(--text-muted)' }}><Loader2 size={18} className="pr-gov-spin" /> Loading governance data…</div>
      ) : !d ? (
        <Empty icon={ShieldCheck} title="Nothing to report"
          hint="The governance service answered with no data. Refresh, or check that the HSSE registers have been set up for this tenant." />
      ) : (
        <>
          {/* ── KPIs, grouped by the register they come from ── */}
          {groups.map(g => (
            <div key={g.key} style={{ marginBottom: 18 }}>
              <SectionHead title={g.title} note={g.note} />
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(200px, 1fr))', gap: 12 }}>
                {g.cards.map(c => (
                  // No click-through. The counts belong to registers Purchase
                  // does not own, so sending a Purchase user to the Purchase
                  // incident list would show them different rows than the number
                  // they clicked, and sending them into TPV is not this
                  // module's to do. The figure is reported, not navigated.
                  <div key={c.label} className="pr-kpi" style={{ cursor: 'default' }}>
                    <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
                      <span style={{ fontSize: 10.5, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.04em', color: 'var(--text-muted)' }}>{c.label}</span>
                      <c.icon size={16} style={{ color: c.color }} />
                    </div>
                    <div style={{ fontSize: 28, fontWeight: 900, color: c.color, marginTop: 6, lineHeight: 1.1 }}>{c.value ?? 0}</div>
                  </div>
                ))}
              </div>
            </div>
          ))}

          {/* ── Panels ── */}
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))', gap: 16 }}>
            <Panel title="Open Incidents by Severity" icon={Siren}
              note="Everything not yet Closed, on the site incident register.">
              {Object.values(severities).every(v => !v)
                ? <PanelEmpty text="No open incidents on this register." />
                : Object.entries(severities).map(([sev, n]) => (
                  // The bar is scaled against the open total, so a single Fatal
                  // among ten open incidents reads as one in ten — not as a
                  // full bar, which is what scaling each row to itself would do.
                  <Bar key={sev} label={sev} value={n} max={inc.open || 1} color={sevColor(sev)} />
                ))}
            </Panel>
            {/* The Vendor Ratings panel was removed with the switch to Purchase's
                own figures. It was scored by VendorScorecardService over the
                SHARED vendors table, so under a Purchase menu it rated other
                people's vendors. Purchase scores its own through VPI. */}

            <Panel title="Recent Incidents" icon={AlertTriangle}
              note="The five most recent by date of occurrence, open or closed.">
              {(inc.recent || []).length === 0
                ? <PanelEmpty text="None reported on this register." />
                : (inc.recent || []).map(r => (
                  <div key={r.id} style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '9px 0', borderBottom: '1px solid var(--border)' }}>
                    <span style={{ width: 8, height: 8, borderRadius: 999, background: sevColor(r.severity), flexShrink: 0 }} />
                    <div style={{ flex: 1, minWidth: 0 }}>
                      <div style={{ fontSize: 12.5, fontWeight: 700, color: 'var(--text-h)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{r.title}</div>
                      <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>
                        {r.reference} · {r.vendor?.company_name || 'No vendor'} · {fmtWhen(r.occurred_at)}
                      </div>
                    </div>
                    <StatusBadge cfg={{ label: pretty(r.status) || '—', color: sevColor(r.severity), bg: `${sevColor(r.severity)}22` }} />
                  </div>
                ))}
            </Panel>
          </div>
        </>
      )}
    </div>
  )
}

/* ── shared bits ── */
function SectionHead({ title, note }) {
  return (
    <div style={{ margin: '0 0 10px' }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
        <span style={{ fontSize: 12.5, fontWeight: 800, color: 'var(--text-h)', textTransform: 'uppercase', letterSpacing: '0.05em' }}>{title}</span>
        <span style={{ flex: 1, height: 1, background: 'var(--border)' }} />
      </div>
      {note && <p style={{ margin: '4px 0 0', fontSize: 11.5, color: 'var(--text-muted)', lineHeight: 1.5 }}>{note}</p>}
    </div>
  )
}

function Panel({ title, icon: Icon, note, children }) {
  return (
    <div className="pr-glass" style={{ borderRadius: 14, padding: 18 }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: note ? 4 : 14 }}>
        <Icon size={15} style={{ color: '#a78bfa' }} />
        <span style={{ fontSize: 13, fontWeight: 800, color: 'var(--text-h)' }}>{title}</span>
      </div>
      {note && <p style={{ margin: '0 0 14px', fontSize: 11, color: 'var(--text-muted)', lineHeight: 1.5 }}>{note}</p>}
      {children}
    </div>
  )
}

function Bar({ label, value, max, color }) {
  return (
    <div style={{ marginBottom: 9 }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 12, marginBottom: 3 }}>
        <span style={{ color: 'var(--text-h)', fontWeight: 600 }}>{label}</span>
        <span style={{ color, fontWeight: 800 }}>{value ?? 0}</span>
      </div>
      <div style={{ height: 6, borderRadius: 999, background: 'var(--bg-input)', overflow: 'hidden' }}>
        <div style={{ width: `${Math.min(100, ((value || 0) / (max || 1)) * 100)}%`, height: '100%', background: color, borderRadius: 999 }} />
      </div>
    </div>
  )
}

function PanelEmpty({ text }) {
  return <div style={{ padding: '18px 0', textAlign: 'center', fontSize: 12.5, color: 'var(--text-muted)' }}>{text}</div>
}
