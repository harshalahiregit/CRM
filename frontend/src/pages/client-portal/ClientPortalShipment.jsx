import { useState, useEffect } from 'react'
import { useParams, Link } from 'react-router-dom'
import { clientPortalApi } from '@/lib/clientPortalApi'

/**
 * One shipment, as the customer reads it — STOS-CLP §27.
 *
 * "Single Trip/Container 360 view", in plain language, with the story first.
 * Built in the shape of ClientPortalStatement rather than of our internal trip
 * page: same card, same widths, same four states (denied, error, loading,
 * loaded), so it belongs to this portal rather than looking like an internal
 * screen that wandered in.
 *
 * ── THE JOURNEY IS THE POINT, SO IT COMES FIRST ──────────────────────────
 * The internal Container 360 spent its first 1245px on a chain of identifiers
 * and put the story below the fold; the September redesign moved it up and the
 * screen became readable. The same ordering is applied here from the start
 * rather than learned again.
 *
 * ── THE MOMENTS ARE INTERIM, AND SAY SO ON SCREEN ────────────────────────
 * These are the moments we genuinely record, in plain words. They are NOT
 * CLP §8's M01–M14 — that model is deferred under D-126 because four of its
 * fourteen have no event type anywhere and it needs planned-versus-actual
 * times we do not carry. A customer is told the list is "the moments we record"
 * rather than being given a milestone model that quietly is not one.
 */
const date = (v) => (v ? new Date(v).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—')
const stamp = (v) => (v ? new Date(v).toLocaleString('en-IN', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—')

export default function ClientPortalShipment() {
  const { id } = useParams()
  const [d, setD] = useState(null)
  const [denied, setDenied] = useState(false)
  const [err, setErr] = useState('')

  useEffect(() => {
    let alive = true
    setD(null); setDenied(false); setErr('')
    clientPortalApi.shipment(id)
      .then((x) => { if (alive) setD(x) })
      .catch((e) => {
        if (!alive) return
        if (e?.response?.status === 403) setDenied(true)
        // A shipment that is not this customer's answers 404, exactly as one
        // that does not exist does. The wording must not distinguish them
        // either, or the screen becomes the oracle the endpoint refuses to be.
        else if (e?.response?.status === 404) setErr('We could not find that shipment.')
        else setErr(e?.response?.data?.message || 'Could not load this shipment.')
      })
    return () => { alive = false }
  }, [id])

  const card = { background: 'var(--bg-card,#12141b)', border: '1px solid var(--border,#2a2f3a)', borderRadius: 14 }
  const muted = { fontSize: 12, color: 'var(--text-muted,#9ca3af)' }

  if (denied) {
    return (
      <div style={{ maxWidth: 1000 }}>
        <div style={{ ...card, padding: 22 }}>
          <p style={{ fontSize: 13, fontWeight: 600, color: 'var(--text-h,#fff)', margin: 0 }}>
            This section has not been shared with you.
          </p>
          <p style={{ ...muted, margin: '5px 0 0' }}>Ask your account manager if you need access to shipments.</p>
        </div>
      </div>
    )
  }

  if (err) return <div style={{ maxWidth: 1000 }}><div style={{ ...card, padding: 22, color: '#ef4444', fontSize: 13 }}>{err}</div></div>
  if (!d) return <div style={{ maxWidth: 1000 }}><div style={{ ...card, padding: 22, ...muted, fontSize: 13 }}>Loading…</div></div>

  return (
    <div style={{ maxWidth: 1000, display: 'grid', gap: 16 }}>
      <div>
        <Link to="/portal/shipments" style={{ ...muted, textDecoration: 'none' }}>← All shipments</Link>
        <h1 style={{ fontSize: 21, fontWeight: 800, color: 'var(--text-h,#fff)', margin: '6px 0 0' }}>
          {d.trip_number}
        </h1>
        <p style={{ ...muted, margin: '4px 0 0' }}>
          {[d.status, d.route, d.customer_reference && `Your reference ${d.customer_reference}`]
            .filter(Boolean).join(' · ')}
        </p>
      </div>

      {/* The story, first. */}
      <div style={{ ...card, padding: 20 }}>
        <h2 style={{ fontSize: 13, fontWeight: 800, color: 'var(--text-h,#fff)', margin: '0 0 3px' }}>What has happened</h2>
        <p style={{ ...muted, margin: '0 0 14px' }}>The moments we record against this shipment.</p>

        {d.journey?.length ? (
          <div style={{ display: 'grid', gap: 0 }}>
            {d.journey.map((m, i) => (
              <div key={i} style={{
                display: 'flex', gap: 12, alignItems: 'baseline',
                padding: '9px 0', borderTop: i ? '1px solid var(--border,#2a2f3a)' : 'none',
              }}>
                <span style={{ ...muted, minWidth: 150, fontVariantNumeric: 'tabular-nums' }}>{stamp(m.at)}</span>
                <span style={{ fontSize: 13, color: 'var(--text-h,#fff)' }}>{m.what}</span>
              </div>
            ))}
          </div>
        ) : (
          <p style={{ ...muted, margin: 0 }}>Nothing has been recorded against this shipment yet.</p>
        )}
      </div>

      <div style={{ ...card, padding: 20 }}>
        <h2 style={{ fontSize: 13, fontWeight: 800, color: 'var(--text-h,#fff)', margin: '0 0 14px' }}>Details</h2>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(180px,1fr))', gap: 14 }}>
          <Fact label="Consignment" value={d.consignment_number} />
          <Fact label="What is being moved" value={d.cargo_description} />
          <Fact label="Packages" value={d.package_count} />
          <Fact label="Weight" value={d.gross_weight_kg ? `${Number(d.gross_weight_kg).toLocaleString('en-IN')} kg` : null} />
          <Fact label="Expected collection" value={date(d.planned_departure_at)} />
          <Fact label="Expected delivery" value={date(d.planned_arrival_at)} />
          <Fact label="Collected" value={date(d.departed_at)} />
          <Fact label="Delivered" value={date(d.delivered_at)} />
        </div>
      </div>
    </div>
  )
}

function Fact({ label, value }) {
  return (
    <div>
      <p style={{ fontSize: 10.5, fontWeight: 800, letterSpacing: '.06em', textTransform: 'uppercase',
        color: 'var(--text-muted,#9ca3af)', margin: 0 }}>{label}</p>
      <p style={{ fontSize: 13, color: 'var(--text-h,#fff)', margin: '3px 0 0' }}>{value || '—'}</p>
    </div>
  )
}
