import { useState } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import {
  ArrowLeft, Container, Loader2, AlertTriangle, History, Link2,
  Building2, Package, Boxes, Truck, UserRound, FileCheck2, ClipboardCheck,
} from 'lucide-react'
import { transportContainerApi } from '@/services/transportApi'
import { fmtDateTime } from '../constants'

/**
 * Container 360 — the Digital Passport (STOS-CTD; MS-001 §14 steps 1–2).
 *
 * TM-001 §9: "Container Number is the universal search/traceability anchor.
 * Vehicle, driver, trip, finance and maintenance retain correct domain
 * ownership." So this page ANCHORS on the container and reads across; it owns
 * none of what it shows.
 *
 * ── WHY SECTIONS ARE ABSENT RATHER THAN EMPTY ────────────────────────────
 * CTD §9 lists thirty passport sections. Nine of them — GPS, temperature,
 * Genset, fuel, FASTag, port/gate, feedback, CAPA, profitability — have no
 * entity anywhere in this codebase. **An empty panel implies the feature
 * exists**, which is the rule the consignment drawer already follows, so those
 * sections are not rendered at all. What is here is what we can actually prove.
 *
 * ── AND WHY IT LINKS OUT RATHER THAN RE-RENDERING ────────────────────────
 * Documents, POD, billing and collection are Person 3's, keyed on the trip.
 * This page reports that they exist and links to the trip screen that already
 * renders them in full. Copying those panels here would duplicate a boundary
 * that took two blocks to establish.
 *
 * Dialect: mirrors TransportContainers.jsx / Bills.jsx — Tailwind for layout,
 * var(--…) for every colour, zero raw hex.
 */
export default function ContainerPassport() {
  const { id } = useParams()
  const navigate = useNavigate()
  const [filter, setFilter] = useState('all')

  const { data, isLoading, error } = useQuery({
    queryKey: ['transport', 'containers', id, 'passport'],
    queryFn: () => transportContainerApi.passport(id),
    retry: false,
  })

  if (isLoading) {
    return <div className="p-10 text-center"><Loader2 className="animate-spin mx-auto" style={{ color: 'var(--text-muted)' }} /></div>
  }

  if (error || !data?.container) {
    return (
      <div className="p-10 text-center">
        <AlertTriangle size={28} className="mx-auto mb-3" style={{ color: 'var(--text-muted)' }} />
        <p className="text-base font-bold" style={{ color: 'var(--text-h)' }}>Container not found</p>
        <p className="text-sm mt-1" style={{ color: 'var(--text-muted)' }}>
          It may have been removed, or it belongs to another workspace.
        </p>
        <button onClick={() => navigate('/app/transport/containers')} className="btn-3d mt-4">Back to containers</button>
      </div>
    )
  }

  const { container, chain, status, lifecycle, readiness, linked, timeline } = data
  const rows = filter === 'all' ? timeline : timeline.filter((t) => t.source === filter)
  const sources = [...new Set(timeline.map((t) => t.source))]

  return (
    <div className="space-y-5 animate-fade-in">
      {/* ── CTD §10, the header: an immediate operational summary ── */}
      <div className="flex items-start gap-3 flex-wrap">
        <button onClick={() => navigate('/app/transport/containers')}
          className="p-2 rounded-xl" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
          <ArrowLeft size={16} style={{ color: 'var(--text-body)' }} />
        </button>
        <div className="flex-1 min-w-0">
          <p className="text-[11px] font-extrabold tracking-wider" style={{ color: 'var(--accent)' }}>CONTAINER 360</p>
          <div className="flex items-center gap-3 flex-wrap">
            <h1 className="text-2xl font-black" style={{ color: 'var(--text-h)' }}>{container.container_number}</h1>
            <StatusChip label={status.label} />
          </div>
          {/* §7 — the entered value and the key it matched, when they differ. */}
          {container.container_number_normalized !== container.container_number && (
            <p className="text-xs mt-0.5" style={{ color: 'var(--text-faint)' }}>
              matched as {container.container_number_normalized}
              {container.container_type ? ` · ${container.container_type}` : ''}
            </p>
          )}
        </div>
      </div>

      {/* ── CTD §12: explain the status, never merely show it ── */}
      <div className="p-4 rounded-2xl" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
        <p className="text-sm font-bold" style={{ color: 'var(--text-h)' }}>{status.explanation}</p>
        <p className="text-xs mt-1.5 flex items-center gap-1.5" style={{ color: 'var(--text-muted)' }}>
          <span className="font-extrabold uppercase tracking-wider text-[10px]">Next</span>
          {status.next_action}
        </p>
      </div>

      {/* ── CTD §71: the two chains, every node clickable ── */}
      <Section title="Where this container sits" icon={Link2}>
        <div className="grid gap-2">
          <ChainRow icon={Building2} label="Customer" value={chain.customer?.name} />
          <ChainRow icon={Package} label="Transport order" value={chain.order?.number}
            hint={chain.order?.service_type}
            onOpen={chain.order && (() => navigate(`/app/transport/orders/${chain.order.id}`))} />
          <ChainRow icon={Boxes} label="Consignment" value={chain.consignment?.number}
            hint={chain.consignment?.cargo_description}
            onOpen={chain.consignment && (() => navigate(`/app/transport/consignments?open=${chain.consignment.id}`))} />
          <ChainRow icon={Container} label="Container" value={container.container_number} hint="you are here" />
          <ChainRow icon={Truck} label="Trip" value={chain.trip?.number}
            hint={chain.trip ? `${chain.trip.status_label}${chain.trip.route ? ` · ${chain.trip.route}` : ''}` : null}
            onOpen={chain.trip && (() => navigate(`/app/transport/trips/${chain.trip.id}`))} />
          <ChainRow icon={Truck} label="Vehicle" value={chain.vehicle?.registration} hint={chain.vehicle?.type} />
          <ChainRow icon={UserRound} label="Driver" value={chain.driver?.name} hint={chain.driver?.licence_class} />
        </div>
      </Section>

      {/* ── MS-001 §14 step 5: dispatch eligibility. Ours (pre-trip). ── */}
      {readiness && (
        <Section title="Ready to leave?" icon={ClipboardCheck}>
          <div className="flex items-center gap-2 flex-wrap">
            <span className="text-sm font-bold"
              style={{ color: readiness.ready ? 'var(--color-success-500)' : 'var(--text-h)' }}>
              {readiness.status_label}
            </span>
            <span className="text-xs" style={{ color: 'var(--text-muted)' }}>
              {readiness.completed} of {readiness.total} checks confirmed
            </span>
          </div>
          {(readiness.blockers ?? []).length > 0 && (
            <ul className="mt-2 space-y-1">
              {readiness.blockers.map((b, i) => (
                <li key={i} className="text-xs flex gap-1.5" style={{ color: 'var(--color-danger-500)' }}>
                  <AlertTriangle size={12} className="mt-0.5 shrink-0" />{b}
                </li>
              ))}
            </ul>
          )}
        </Section>
      )}

      {/* ── CTD-016/017/019/020: reported, not re-rendered. P3 owns these. ── */}
      {linked && (
        <Section title="Paperwork and money" icon={FileCheck2}>
          <div className="flex flex-wrap gap-2">
            {linked.documents && (
              <Pill label={`${linked.documents.total} document${linked.documents.total === 1 ? '' : 's'}`}
                sub={`${linked.documents.verified} verified${linked.documents.pod ? ' · POD on file' : ''}`} />
            )}
            {linked.billing && <Pill label={`Billing: ${linked.billing.status}`}
              sub={linked.billing.invoiced ? 'Invoiced' : 'Not invoiced'} />}
            {linked.collections && <Pill label={`${linked.collections.total} collection(s)`} />}
          </div>
          {chain.trip && (
            <button onClick={() => navigate(`/app/transport/trips/${chain.trip.id}`)}
              className="text-xs font-bold mt-3" style={{ color: 'var(--accent)' }}>
              Open {chain.trip.number} to work on these →
            </button>
          )}
        </Section>
      )}

      {/* ── CTD §76: this lifecycle, and the ones before it ── */}
      <Section title={`Where it has been · used ${lifecycle.times_used} time${lifecycle.times_used === 1 ? '' : 's'}`} icon={History}>
        {lifecycle.times_used === 0 ? (
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
            This container has not been on a consignment yet.
          </p>
        ) : (
          <div className="space-y-2">
            {lifecycle.current && <Attachment a={lifecycle.current} current />}
            {lifecycle.previous.map((a) => <Attachment key={a.id} a={a} />)}
          </div>
        )}
      </Section>

      {/* ── CTD-021, §31–§35: one chronological view, filterable by source ── */}
      <Section title="Everything that has happened" icon={History}>
        {sources.length > 1 && (
          <div className="flex gap-1.5 flex-wrap mb-3">
            {['all', ...sources].map((s) => (
              <button key={s} onClick={() => setFilter(s)}
                className="px-2.5 py-1 rounded-lg text-[11px] font-bold"
                style={filter === s
                  ? { background: 'rgba(124,58,237,0.16)', color: 'var(--accent)', border: '1px solid var(--accent)' }
                  : { background: 'var(--bg-input)', color: 'var(--text-body)', border: '1px solid var(--border)' }}>
                {s === 'all' ? 'Everything' : s}
              </button>
            ))}
          </div>
        )}
        <div className="space-y-1.5">
          {rows.map((r, i) => (
            <div key={i} className="flex gap-3 py-1.5 border-b last:border-0" style={{ borderColor: 'var(--border)' }}>
              <span className="text-[11px] shrink-0 w-36" style={{ color: 'var(--text-muted)' }}>{fmtDateTime(r.at)}</span>
              <span className="text-[10px] font-bold uppercase shrink-0 w-20" style={{ color: 'var(--text-faint)' }}>{r.source}</span>
              <span className="text-xs flex-1" style={{ color: 'var(--text-h)' }}>{r.label}</span>
              {r.actor && <span className="text-[11px] shrink-0" style={{ color: 'var(--text-muted)' }}>{r.actor}</span>}
            </div>
          ))}
        </div>
      </Section>
    </div>
  )
}

function Section({ title, icon: Icon, children }) {
  return (
    <div className="p-4 rounded-2xl" style={{ background: 'var(--bg-card, var(--bg-input))', border: '1px solid var(--border)' }}>
      <p className="text-[10px] uppercase font-bold mb-3 flex items-center gap-1.5" style={{ color: 'var(--text-muted)' }}>
        <Icon size={12} /> {title}
      </p>
      {children}
    </div>
  )
}

/** A node in the chain. Absent data renders as "—", never as a blank row. */
function ChainRow({ icon: Icon, label, value, hint, onOpen }) {
  return (
    <div className="flex items-center gap-3 py-1.5 border-b last:border-0" style={{ borderColor: 'var(--border)' }}>
      <Icon size={13} className="shrink-0" style={{ color: 'var(--text-muted)' }} />
      <span className="text-[11px] uppercase font-bold w-32 shrink-0" style={{ color: 'var(--text-muted)' }}>{label}</span>
      <div className="flex-1 min-w-0">
        <span className="text-sm font-bold" style={{ color: value ? 'var(--text-h)' : 'var(--text-faint)' }}>
          {value || '—'}
        </span>
        {hint && <div className="text-[11px] truncate" style={{ color: 'var(--text-muted)' }}>{hint}</div>}
      </div>
      {onOpen && (
        <button onClick={onOpen} className="text-[11px] font-bold shrink-0" style={{ color: 'var(--accent)' }}>Open</button>
      )}
    </div>
  )
}

function Attachment({ a, current }) {
  return (
    <div className="p-2.5 rounded-xl flex items-center justify-between gap-2 flex-wrap"
      style={{ background: 'var(--bg-input)', border: `1px solid ${current ? 'var(--color-success-500)' : 'var(--border)'}` }}>
      <span className="text-sm font-bold" style={{ color: 'var(--text-h)' }}>
        {a.consignment?.consignment_number ?? `Consignment #${a.consignment_id}`}
      </span>
      <span className="text-[11px]" style={{ color: current ? 'var(--color-success-500)' : 'var(--text-muted)' }}>
        {current ? 'On it now' : `until ${fmtDateTime(a.detached_at)}`}
      </span>
    </div>
  )
}

function StatusChip({ label }) {
  return (
    <span className="px-2.5 py-1 rounded-full text-[11px] font-extrabold"
      style={{ background: 'rgba(124,58,237,0.14)', color: 'var(--accent)' }}>{label}</span>
  )
}

function Pill({ label, sub }) {
  return (
    <div className="px-3 py-2 rounded-xl" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
      <p className="text-xs font-bold" style={{ color: 'var(--text-h)' }}>{label}</p>
      {sub && <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>{sub}</p>}
    </div>
  )
}
