import { useState } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import {
  ArrowLeft, Container, Loader2, AlertTriangle, History, Link2,
  Building2, Package, Boxes, Truck, UserRound, FileCheck2, ClipboardCheck, FileText,
} from 'lucide-react'
import { transportContainerApi } from '@/services/transportApi'
import { fmtDateTime, TRIP_STATUS_LABEL } from '../constants'

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

/**
 * A warning or blocker as a sentence — D-147.
 *
 * Fleet's eligibility answers `{code, why, owner}` since D-134, and rendering
 * that object into JSX is the "Objects are not valid as a React child" crash.
 * `why (owner)` matches DriversBoard and VehicleAllocationModal — the desk that
 * can clear it is the useful half.
 */
const reason = (r) => (r?.owner ? `${r.why} (${r.owner})` : r?.why ?? '')

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
  // CTD §35 filters the timeline by CATEGORY — documents, operations, GPS,
  // temperature, financial, customer, incident — not by which of our three
  // tables an audit row happened to sit on. That is what it filtered by until
  // trip_events existed, because a category was not something we recorded.
  const rows = filter === 'all' ? timeline : timeline.filter((t) => t.category === filter)
  const categories = [...new Set(timeline.map((t) => t.category))].filter(Boolean).sort()

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
          {/* ── WHERE IS IT, IN ONE LINE ────────────────────────────────
              CTD §5's own search example is identity and status first, and
              short: container, status, customer, vehicle, driver. The customer
              and the crew used to be five and eight rows down inside the chain,
              so the one question a person arrives with was answered by scrolling.

              "matched as SGOE7710402" used to lead this line. It is §7's
              normalised key and it means nothing to a reader — matched against
              what? It is now last, in plain words, and only when it differs
              from what is printed above it. */}
          <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>
            {[
              container.container_type,
              chain.customer?.name,
              chain.vehicle?.registration,
              chain.driver?.name || (chain.driver?.id ? `#${chain.driver.id}` : null),
            ].filter(Boolean).join(' · ')}
          </p>
          {container.container_number_normalized !== container.container_number && (
            <p className="text-[11px] mt-0.5" style={{ color: 'var(--text-faint)' }}>
              also written {container.container_number_normalized}
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

      {/* ── THE STORY COMES FIRST ──────────────────────────────────────
          This screen is a Digital Passport and CTD's whole argument for it is
          the story: one number, the whole history. It used to sit at 1245px on
          a 2113px page — 59% down, behind a five-row block of identifiers — so
          the thing the screen exists for was the last thing on it. */}
      {/* ── CTD-021, §31–§35: one chronological view, filterable by source ── */}
      <Section title="Everything that has happened" icon={History}>
        {categories.length > 1 && (
          <div className="flex gap-1.5 flex-wrap mb-3">
            {['all', ...categories].map((s) => (
              <button key={s} onClick={() => setFilter(s)}
                className="px-2.5 py-1 rounded-lg text-[11px] font-bold"
                style={filter === s
                  ? { background: 'rgba(124,58,237,0.16)', color: 'var(--accent)', border: '1px solid var(--accent)' }
                  : { background: 'var(--bg-input)', color: 'var(--text-body)', border: '1px solid var(--border)' }}>
                {s === 'all' ? 'Everything' : (CATEGORY_LABEL[s] || s)}
              </button>
            ))}
          </div>
        )}
        <div className="space-y-1.5">
          {rows.map((r, i) => (
            <div key={i} className="flex gap-3 py-1.5 border-b last:border-0" style={{ borderColor: 'var(--border)' }}>
              <span className="text-[11px] shrink-0 w-36" style={{ color: 'var(--text-muted)' }}>{fmtDateTime(r.at)}</span>
              {/* The category is carried by a COLOUR, not a word.
                  It used to print CATEGORY_LABEL on every row — so the six
                  category names appeared 23 times down a 2,100px page while the
                  chips above named the same six. The filing system was stated
                  twice and the events themselves came third. The chips filter;
                  the dot says which kind without spending a column on it.
                  Title attribute so the meaning is still one hover away. */}
              <span className="shrink-0 rounded-full" title={CATEGORY_LABEL[r.category] || r.category}
                style={{ width: 7, height: 7, marginTop: 6, background: CATEGORY_DOT[r.category] || 'var(--text-faint)' }} />
              <span className="text-xs flex-1" style={{ color: 'var(--text-h)' }}>
                {r.label}
                {/* WHICH change it was. A walked trip produced seven identical
                    "Trip status changed" rows before this — a timeline nobody
                    could read. Labels, never the stored codes. */}
                {r.from && r.to && (
                  <span style={{ color: 'var(--text-muted)' }}>
                    {' — '}{TRIP_STATUS_LABEL[r.from] || r.from} → {TRIP_STATUS_LABEL[r.to] || r.to}
                  </span>
                )}
              </span>
              {r.actor && <span className="text-[11px] shrink-0" style={{ color: 'var(--text-muted)' }}>{r.actor}</span>}
            </div>
          ))}
        </div>
      </Section>

      {/* ── CTD §71: the two chains, every node clickable ──
          Hidden entirely when the container is on nothing: a section whose only
          row is "you are here" tells the reader less than the status sentence
          above it already did. */}
      {/* Navigation, not the headline. A wrapping strip of chips instead of
          nine stacked rows — same values, a fraction of the height. */}
      {(chain.customer || chain.order || chain.consignment || chain.trip) && (
      <Section title="Where this container sits" icon={Link2}>
        <div className="flex flex-wrap gap-2">
          <ChainRow icon={Building2} label="Customer" value={chain.customer?.name} />
          <ChainRow icon={Package} label="Transport order" value={chain.order?.number}
            hint={chain.order?.service_type}
            onOpen={chain.order && (() => navigate(`/app/transport/orders/${chain.order.id}`))} />
          <ChainRow icon={Boxes} label="Consignment" value={chain.consignment?.number}
            hint={chain.consignment?.cargo_description}
            onOpen={chain.consignment && (() => navigate(`/app/transport/consignments?open=${chain.consignment.id}`))} />
          {/* CTD-004 and CTD-005, both P0 — "LR visible", "DO visible".
              CTD §5's worked search example puts the LR exactly here, between
              the Transport Order and the Vehicle. Absent until the consignment
              has one, because ChainRow renders nothing for a missing value and
              a dash is not an LR. */}
          <ChainRow icon={FileText} label="LR / Bilty" value={chain.lr?.number}
            hint={chain.lr ? `${chain.lr.has_file ? 'Filed' : 'Recorded'}${chain.lr.version > 1 ? ` · version ${chain.lr.version}` : ''}` : null}
            onOpen={chain.consignment && (() => navigate(`/app/transport/consignments?open=${chain.consignment.id}`))} />
          <ChainRow icon={FileText} label="Delivery order" value={chain.do?.number}
            hint={chain.do ? `${chain.do.has_file ? 'Filed' : 'Recorded'}${chain.do.version > 1 ? ` · version ${chain.do.version}` : ''}` : null}
            onOpen={chain.consignment && (() => navigate(`/app/transport/consignments?open=${chain.consignment.id}`))} />
          <ChainRow icon={Container} label="Container" value={container.container_number} hint="you are here" />
          <ChainRow icon={Truck} label="Trip" value={chain.trip?.number}
            hint={chain.trip ? `${chain.trip.status_label}${chain.trip.route ? ` · ${chain.trip.route}` : ''}` : null}
            onOpen={chain.trip && (() => navigate(`/app/transport/trips/${chain.trip.id}`))} />
          <ChainRow icon={Truck} label="Vehicle" value={chain.vehicle?.registration} hint={chain.vehicle?.type} />
          <ChainRow icon={UserRound} label="Driver" value={chain.driver?.name || (chain.driver?.id ? `#${chain.driver.id}` : null)} hint={chain.driver?.licence_class} />
        </div>
      </Section>
      )}

      {/* ── MS-001 §14 step 5: dispatch eligibility. Ours (pre-trip). ── */}
      {readiness && (
        <Section title="Ready to leave?" icon={ClipboardCheck}>
          <div className="flex items-center gap-2 flex-wrap">
            <span className="text-sm font-bold"
              style={{ color: readiness.ready ? 'var(--color-success-500)' : 'var(--text-h)' }}>
              {readiness.status_label}
            </span>
            <span className="text-xs" style={{ color: 'var(--text-muted)' }}>
              {readiness.completed} of {readiness.total} pre-trip checks confirmed
            </span>
          </div>
          {(readiness.blockers ?? []).length > 0 && (
            <ul className="mt-2 space-y-1">
              {readiness.blockers.map((b, i) => (
                <li key={i} className="text-xs flex gap-1.5" style={{ color: 'var(--color-danger-500)' }}>
                  <AlertTriangle size={12} className="mt-0.5 shrink-0" />{reason(b)}
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
            {/* The bill's own two states, in words. `prepared` is the stored
                code; "Ready to invoice" is what it means to whoever is reading. */}
            {linked.billing && <Pill
              label={linked.billing.invoiced ? 'Invoiced' : 'Ready to invoice'}
              sub={linked.billing.invoiced ? 'Accounts have posted it' : 'Handed to Accounts, not yet posted'} />}
            {/* "1 collection(s)" was a developer's plural on a customer-facing count. */}
            {linked.collections && <Pill label={`${linked.collections.total} payment${linked.collections.total === 1 ? '' : 's'} recorded`} />}
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

    </div>
  )
}

/**
 * CTD §101's nine branches of the event stream, as a reader would say them.
 *
 * The stored values are lower-case tokens — `gps`, `temperature`, `operational`
 * — and CSS uppercasing them does not make them English. "GPS" and "Paperwork"
 * are what a dispatcher calls these; `document` and `quality` are what the
 * document calls them.
 */
/**
 * One colour per category — the word's replacement, not its decoration.
 *
 * Tokens only, so both themes follow. `--text-faint` is the fallback for a
 * category the registry gains before this map does: an unknown kind gets a
 * neutral dot rather than no dot, which keeps the rows aligned.
 */
const CATEGORY_DOT = {
  commercial:  'var(--accent)',
  operational: 'var(--color-info-500, #38bdf8)',
  document:    'var(--color-warning-500, #f59e0b)',
  financial:   'var(--color-success-500, #22c55e)',
  compliance:  'var(--color-info-500, #38bdf8)',
  quality:     'var(--color-danger-500, #f87171)',
  temperature: 'var(--color-danger-500, #f87171)',
  gps:         'var(--color-info-500, #38bdf8)',
  customer:    'var(--accent)',
}

const CATEGORY_LABEL = {
  commercial: 'Commercial',
  operational: 'Operations',
  document: 'Paperwork',
  compliance: 'Compliance',
  gps: 'GPS',
  temperature: 'Temperature',
  financial: 'Money',
  customer: 'Customer',
  quality: 'Incidents',
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

/**
 * A node in the chain — rendered only when there is something to say.
 *
 * It used to fall back to "—". On a free container that produced SIX cards in a
 * row reading "—" (customer, order, consignment, trip, vehicle, driver), which
 * is precisely the empty section this screen was told never to show. An em-dash
 * is not an answer to "where is this container"; the surrounding sentence
 * already gives the real one ("This container is free").
 */
/**
 * One link in the chain, as a chip rather than a row.
 *
 * This was a full-width row with an icon, a 32-unit uppercase label, a value, a
 * hint on its own line and an Open button — nine of them stacked, which made
 * the chain the tallest and most prominent block on the page. Nobody opens a
 * passport to read nine identifiers; they are how you LEAVE this screen, not
 * what it is for.
 *
 * So: the noun and the number on one line, the whole chip clickable where there
 * is somewhere to go, and the hint kept as a tooltip rather than a second line.
 * Every value that was here is still here — including the LR and the delivery
 * order, which CTD §150 makes non-negotiable.
 */
function ChainRow({ icon: Icon, label, value, hint, onOpen }) {
  if (!value) return null

  const body = (
    <>
      <Icon size={12} className="shrink-0" style={{ color: 'var(--text-muted)' }} />
      <span className="text-[11px]" style={{ color: 'var(--text-muted)' }}>{label}</span>
      <span className="text-[12.5px] font-bold" style={{ color: onOpen ? 'var(--accent)' : 'var(--text-h)' }}>{value}</span>
    </>
  )

  const style = {
    display: 'inline-flex', alignItems: 'center', gap: 6,
    padding: '5px 10px', borderRadius: 999,
    background: 'var(--bg-input)', border: '1px solid var(--border)',
  }

  return onOpen
    ? <button type="button" onClick={onOpen} title={hint || undefined} style={{ ...style, cursor: 'pointer' }}>{body}</button>
    : <span title={hint || undefined} style={style}>{body}</span>
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
