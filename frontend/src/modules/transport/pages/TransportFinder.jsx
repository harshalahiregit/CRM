import { useState, useMemo, useRef, useEffect } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useNavigate, Link } from 'react-router-dom'
import {
  Search, Loader2, ArrowRight, Package, Truck, Boxes, Container as ContainerIcon,
} from 'lucide-react'
import { transportSearchApi } from '@/services/transportApi'

/**
 * The entry point — STOS-CTD §4 and §5.
 *
 * ── WHY THIS PAGE EXISTS AT ALL ──────────────────────────────────────────
 * §4 calls the container number "the preferred entry point", and until now
 * Transport had no entry point of any kind: `/app/transport` redirected
 * straight to the Orders list, so the module opened on a table. The one search
 * that understands transport identifiers lived in two places, neither of them
 * an entry point — a filter box INSIDE the Containers list, and ⌘K, which is
 * invisible until somebody tells you it exists.
 *
 * So this takes the index slot the redirect was using. An entry point that
 * needs its own URL is not an entry point; everybody already arrives here.
 *
 * ── IT OWNS NO SEARCH LOGIC, DELIBERATELY ────────────────────────────────
 * Every answer on this page comes from `transportSearchApi.resolve()`, which is
 * the same call ⌘K makes and the same `TransportSearchService` behind it. The
 * follow-through to the Digital Passport, the several-journeys case and the
 * chain-ran-out sentences are all SERVER side.
 *
 * That is not tidiness. Two screens asking the same question in two different
 * places is how they come to give two different answers, and this page and the
 * palette are the two most likely to drift. Anything that looks like it belongs
 * here belongs in the service, where the palette gets it for free.
 *
 * ── THE KEYS ARE NAMED, INCLUDING THE ONES THAT DO NOT WORK ──────────────
 * A search box with no stated vocabulary teaches nobody what it accepts. A user
 * who types a POD number and gets nothing concludes the search is broken rather
 * than that the key is unsupported — so the nine that work and the two that do
 * not are named in the same breath. See D-117: §4 lists eleven keys, §97 lists
 * nine, and POD has no field to search in any table.
 */

/** §4's list, in the order a person is likely to have one in front of them. */
const KEYS = [
  'Container', 'LR', 'Delivery order', 'Transport order', 'Trip',
  'Consignment', 'Customer reference', 'Vehicle', 'Driver',
]

const ICON = {
  container: ContainerIcon, consignment: Boxes, order: Package,
  trip: Truck, vehicle: Truck, driver: Truck,
}

export default function TransportFinder() {
  const navigate = useNavigate()
  const [q, setQ] = useState('')
  const box = useRef(null)

  useEffect(() => { box.current?.focus() }, [])

  const term = q.trim()

  const { data, isFetching } = useQuery({
    queryKey: ['transport-finder', term],
    // Four characters, matching the palette. Shorter than that is somebody
    // still typing, and an identifier search has nothing useful to say about
    // two letters.
    enabled: term.length >= 4,
    queryFn: () => transportSearchApi.resolve(term),
    retry: false,
    staleTime: 60_000,
  })

  const hit = data?.result ?? null

  /* §5 — "Sangoe should immediately show". On an unambiguous match the answer
   * IS the passport, so pressing Enter goes straight there rather than
   * selecting a row that then has to be clicked. Where the term names several
   * journeys the server sends `options` instead and there is nothing to go
   * straight to — see below. */
  const go = () => {
    if (hit && !hit.options) navigate(hit.path)
  }

  const searched = term.length >= 4

  return (
    <div style={{ maxWidth: 780, margin: '0 auto', padding: '56px 16px 40px' }}>
      <h1 style={{ fontSize: 26, fontWeight: 800, color: 'var(--text-h)', margin: 0, textAlign: 'center' }}>
        Find anything in Transport
      </h1>
      <p style={{ fontSize: 13.5, color: 'var(--text-muted)', textAlign: 'center', margin: '8px 0 22px' }}>
        Type a number from the paperwork. It opens the container&rsquo;s whole journey.
      </p>

      <form onSubmit={(e) => { e.preventDefault(); go() }}>
        <div style={{
          display: 'flex', alignItems: 'center', gap: 10, padding: '14px 16px',
          background: 'var(--bg-input)', border: '1px solid var(--border)', borderRadius: 12,
        }}>
          {isFetching
            ? <Loader2 size={18} className="animate-spin" style={{ color: 'var(--text-muted)', flexShrink: 0 }} />
            : <Search size={18} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />}
          <input
            ref={box} value={q} onChange={(e) => setQ(e.target.value)}
            placeholder="Container, trip, LR, vehicle…"
            style={{
              flex: 1, background: 'transparent', border: 'none', outline: 'none',
              color: 'var(--text-h)', fontSize: 16, fontWeight: 600,
            }}
          />
        </div>
      </form>

      <p style={{ fontSize: 12, color: 'var(--text-muted)', margin: '12px 2px 0', lineHeight: 1.7 }}>
        {KEYS.join(' · ')}
        <br />
        <span style={{ opacity: 0.8 }}>Invoice and POD numbers cannot be searched yet.</span>
      </p>

      {searched && !isFetching && !hit && <NoMatch term={term} />}
      {hit?.options && <Journeys hit={hit} />}
      {hit && !hit.options && <Answer hit={hit} onOpen={go} />}

      {!searched && (
        <div style={{ marginTop: 34, display: 'flex', gap: 14, flexWrap: 'wrap', justifyContent: 'center' }}>
          {[
            ['Transport orders', '/app/transport/orders'],
            ['Trips', '/app/transport/trips'],
            ['Consignments', '/app/transport/consignments'],
            ['Containers', '/app/transport/containers'],
          ].map(([label, to]) => (
            <Link key={to} to={to}
              style={{ fontSize: 12.5, fontWeight: 700, color: 'var(--text-muted)', textDecoration: 'none' }}>
              {label}
            </Link>
          ))}
        </div>
      )}
    </div>
  )
}

/**
 * One match. The chain it walked is shown rather than hidden.
 *
 * A plate resolving to a container passport looks like magic without it, and
 * "magic" is the word people use right before they stop trusting a screen.
 */
function Answer({ hit, onOpen }) {
  const Icon = ICON[hit.type] || ContainerIcon

  return (
    <div style={card}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
        <Icon size={20} style={{ color: 'var(--accent)', flexShrink: 0 }} />
        <div style={{ flex: 1, minWidth: 0 }}>
          <p style={{ margin: 0, fontSize: 15, fontWeight: 800, color: 'var(--text-h)' }}>{hit.label}</p>
          <p style={{ margin: '2px 0 0', fontSize: 12, color: 'var(--text-muted)' }}>
            {hit.matched_on && hit.matched_on !== hit.label
              ? `${hit.kind} · matched as ${hit.matched_on}`
              : hit.kind}
          </p>
        </div>
        <button type="button" onClick={onOpen} style={openBtn}>
          {hit.passport ? 'Open the passport' : 'Open'} <ArrowRight size={13} />
        </button>
      </div>

      {hit.passport && (
        <p style={trail}>
          {[hit.label, ...(hit.via || []), hit.passport.container_number].join('  →  ')}
        </p>
      )}

      {/* The chain ran out. Says which hop, and leaves the last real record
          reachable — not a dead end, and not a passport that is not this
          thing's passport. */}
      {hit.note && <p style={{ ...trail, color: 'var(--text-p)' }}>{hit.note}</p>}
    </div>
  )
}

/**
 * Several journeys — a vehicle or a driver.
 *
 * These are assets with a history, not journeys, and going straight through
 * would pick one out of many. Every row still offers a passport, so the list is
 * a set of routes to §4's destination rather than a dead end with extra steps.
 */
function Journeys({ hit }) {
  const rows = hit.options.items || []

  return (
    <div style={card}>
      <p style={{ margin: 0, fontSize: 15, fontWeight: 800, color: 'var(--text-h)' }}>{hit.label}</p>
      <p style={{ margin: '2px 0 12px', fontSize: 12, color: 'var(--text-muted)' }}>{hit.options.heading}</p>

      <div style={{ display: 'grid', gap: 7 }}>
        {rows.map((r) => (
          <Link key={r.trip_number} to={r.path} style={row}>
            <span style={{ fontWeight: 700, color: 'var(--text-h)', fontSize: 13 }}>{r.trip_number}</span>
            <span style={{ fontSize: 12, color: 'var(--text-muted)', flex: 1 }}>
              {r.label}{r.container_number ? ` · ${r.container_number}` : ' · no container'}
            </span>
            <ArrowRight size={13} style={{ color: 'var(--text-muted)' }} />
          </Link>
        ))}
      </div>
    </div>
  )
}

/**
 * Nothing matched.
 *
 * Quotes the term, because the thing the reader needs to check is the
 * characters in the box; names the vocabulary; and names the two keys that
 * cannot be searched at all, so those do not read as faults.
 */
function NoMatch({ term }) {
  return (
    <div style={card}>
      <p style={{ margin: 0, fontSize: 14, fontWeight: 700, color: 'var(--text-h)' }}>
        Nothing matches &ldquo;{term}&rdquo;.
      </p>
      <p style={{ margin: '8px 0 0', fontSize: 12.5, color: 'var(--text-muted)', lineHeight: 1.7 }}>
        {/* Written out rather than `KEYS.join(', ').toLowerCase()`, which is
            what this was first: it printed "lr" and "do" in lower case and read
            as a list of nouns rather than a sentence. An empty state is the one
            screen a confused person actually reads. */}
        You can search a container number, an LR or delivery order number, a transport order,
        trip or consignment number, a customer reference, a vehicle registration or a driver name.
        Identifiers are matched exactly — a container number can be typed however it appears on
        the paperwork, but the rest must match.
        <br />
        <span style={{ opacity: 0.85 }}>
          Invoice numbers live in Accounts and are not searchable here. A proof of delivery is a file
          attached to a trip rather than a numbered document, so it has no number to search.
          {/* The honest limit on vehicles, stated rather than left to look like
              a fault. Transport keeps its own vehicle rows until the Fleet
              repoint (D-100c); a truck Fleet knows about and Transport has
              never run a trip on genuinely does not resolve here. We do NOT
              read Fleet's table to check — the gateway between the two is
              write-only today, and reaching around it to make one search
              message nicer is the boundary violation that blocker exists to
              prevent. Asked P2 for a read method instead. */}
          <br />
          A vehicle registration finds a journey only where Transport has run a trip on that
          vehicle. Trucks that exist only in Fleet are searched from the Fleet screens until the
          two vehicle records become one.
        </span>
      </p>
    </div>
  )
}

const card = {
  marginTop: 18, padding: 16, borderRadius: 12,
  background: 'var(--bg-input)', border: '1px solid var(--border)',
}

const trail = {
  margin: '12px 0 0', paddingTop: 11, borderTop: '1px solid var(--border)',
  fontSize: 12, color: 'var(--text-muted)', lineHeight: 1.6,
}

const row = {
  display: 'flex', alignItems: 'center', gap: 10, padding: '9px 11px',
  borderRadius: 9, border: '1px solid var(--border)', textDecoration: 'none',
}

const openBtn = {
  display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 13px',
  borderRadius: 9, background: 'var(--accent)', border: '1px solid var(--accent)',
  color: '#fff', fontSize: 12.5, fontWeight: 800, cursor: 'pointer', flexShrink: 0,
}
