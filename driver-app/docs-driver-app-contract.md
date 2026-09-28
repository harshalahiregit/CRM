# Driver app — the two Ops endpoints it needs (for Dev 1)

The driver phone app (SNG-TRN-026) is a thin client on the existing API. Almost
everything it needs exists. Two things do not, and both are Ops-owned because
they are about trips and who may progress them.

## 1. A driver-scoped trip list

Today `GET /transport/trips` is the office list, gated to ops/dispatcher. A
driver needs **their own** trips only (`SCOPE_OWN`), by the driver behind the
logged-in user.

Proposed: `GET /transport/driver/trips` (or a `mine=1` filter on the existing
list) that returns the trips whose active assignment's `driver_id` resolves to
the caller, open trips first. Same row shape the app already reads:
`{ id, trip_number, status, route, dispatch_destination, planned_arrival_at }`.

## 2. Driver-reportable progress events

The driver taps **departed / arrived at pickup / arrived at delivery /
delivered**. Today departure and delivery are dispatcher/ops actions
(`/trips/{id}/depart`, `/trips/{id}/deliver`), not callable by a driver.

Proposed: driver-callable progress endpoints, `SCOPE_OWN`, e.g.
`POST /transport/driver/trips/{id}/progress { event: 'departed' | 'arrived_pickup' | 'loaded' | 'arrived_delivery' | 'delivered' }`,
recording a timestamped event and advancing the trip's status under your state
machine's rules. The office still owns dispatch; this is the driver *reporting*
what happened on the road, which is a different act from the office *deciding*.

## What is already fine (no change)

- **POD** — `POST /trips/{id}/pod`, driver `POD_SUBMIT SCOPE_OWN`. The app uses
  this today.
- **Auth** — `POST /auth/login`, bearer token.
- **GPS** — Fleet's telemetry ingest (mine), device-token authed.

## Impact on the app

The app screens are built to this shape already. When these two land, the change
on my side is a URL swap in `src/api.js` and making the journey strip tappable —
no rewrite. Tell me the final paths and I will point at them the same day.
