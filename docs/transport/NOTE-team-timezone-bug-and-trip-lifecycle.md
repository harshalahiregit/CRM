# Shivam, Zafar — check your date fields today. Then: the trip lifecycle is complete.

**From:** Mohammad (Person 1) · **2026-09-18**

---

## 1. FIRST — a bug you may have too, and would not know about

**The symptom, so you can recognise it without reading a defect entry:**

> A form has a date-and-time field that defaults to *now*. The user presses the button without
> touching it. The server answers **"that cannot be recorded in the future."**
>
> Or, worse, nothing is refused at all — the time is simply **stored wrong**, by exactly your
> UTC offset, and nobody notices for weeks.

### What causes it

`<input type="datetime-local">` gives you a **wall clock with no timezone**: `"2026-09-20T14:00"`.
Our API runs in **UTC**. So Carbon reads that as 14:00 *UTC*, and for us in IST that is 19:30.

If the field defaults to `now`, "now" in IST is **five and a half hours in the future in UTC** —
so the server rejects the user's very first click, every time.

### What it cost us

Two shipped Block 3 features — **Record departure** and **Record delivery** — **could not be used
at all** by anyone outside UTC. And dispatch has been storing the wrong planned times since the
day it shipped:

| | typed | stored | shown back |
|---|---|---|---|
| Dispatch ETD | 14:00 | 08:30 UTC | **19:30** |
| Order "Required by" | 09:00 | 09:00 UTC | **14:30** |

**1311 tests were green the whole time.** They could not have caught it: every service test
builds its times on the server, where there is nothing to convert.

### I checked yours already — and here is the honest answer

I ran the sweep across your areas rather than asking you to. **Neither of you has the throwing
variant of this bug**, and I would rather tell you that than have you spend an afternoon looking
for it:

```bash
grep -rn 'datetime-local' frontend/src/modules/transport/fleet          # Shivam → no hits
grep -rn 'datetime-local' frontend/src/modules/transport/components     # Zafar  → no hits in yours
```

**Every date field either of you has is `type="date"`, date-only**, and date-only does not carry a
time to get wrong. So no action is needed from either of you today.

| | file | field |
|---|---|---|
| **Shivam** | `fleet/components/VehicleFormModal.jsx` | purchase date, and the compliance dates |
| **Shivam** | `fleet/pages/DriversBoard.jsx` | licence expiry |
| **Zafar** | `components/CostsPanel.jsx` | cost date |
| **Zafar** | `components/CollectionPanel.jsx` | due date, next follow-up |

**What you ARE exposed to** is the quieter one below — the day-drift — because those four fields
are exactly the kind it affects. And one specific thing worth a look, Zafar:

```js
// CostsPanel.jsx:195
<input type="date" max={new Date().toISOString().slice(0, 10)} ... />
```

`toISOString()` is a **UTC** date being used as the ceiling on a **local** date picker. Between
midnight and 05:30 IST those are different days, so someone recording a cost at 1am cannot date it
today. Small, real, and yours.

**If you add a `datetime-local` anywhere later, read the fix below first.** There is now a test
(`TransportDateTimeContractTest`) that fails if a file in the transport module renders one without
converting it — including indirectly, through a shared field config.

### The fix

Send an **instant**, not a wall clock:

```js
// constants.js — shared, already fixed on my side
export const fromLocalInput = (v) => {
  if (!v) return null
  const d = new Date(v)                 // parsed in the browser's own zone
  return Number.isNaN(d.getTime()) ? null : d.toISOString()
}
```

And where a field **defaults to now**, send `null` instead and let the server stamp its own
clock — otherwise a browser a few seconds ahead of the API refuses the default action.

### One more, and it is not fixed

Plain `<input type="date">` sends `"2026-09-25"`, stored as **midnight UTC**. Rendered with
`toLocaleDateString` that is correct in IST and **a day early** west of Greenwich:

```
Asia/Kolkata      picked 25 Sep  →  25 Sept 2026   ✓
America/New_York  picked 25 Sep  →  24 Sept 2026   ✗
```

It does not bite us today. It will the first time anyone opens Sangoé from the US, and a licence
expiry shown a day early is a compliance answer that is wrong. Raised as **D-111**; the fix
crosses all three of our sections, so it needs a decision rather than a quiet edit.

---

## 2. THEN — the trip lifecycle is complete, end to end

A trip stopped dead at `dispatched` for the whole project. It does not any more.

`draft → viability_pending → approved → allocated → pretrip_ok → dispatched → in_transit →
delivered → pod_verified → billable → billed → collection_pending → closed`

### Zafar — what changed for you

- **`delivered` is now reachable.** Trips actually arrive there, so `TripDocumentService::verify()`
  fires. That closes **C-09**, exactly as you predicted: no change on your side.
- **`EVT-012 TripClosed` exists and is emitted.** Payload is `trip_id` and `closure_timestamp`
  per the registry, plus a `controls()` accessor telling you which closure checks actually ran.
  Two of five report **not checked** rather than passed (`trip_settlements` has no table,
  `trip_exceptions` has no model) — so **do not read a `TripClosed` as evidence a trip had no
  unresolved exception.**
- **Closure is PLUMBED, not BUILT** — and it is waiting on you. Nothing can reach
  `collection_pending`, because `TripBill::markInvoiced()` has no caller and no route. One route
  on your side makes closure live with no change on mine. Detail and the specific ask are in
  `REQUEST-person3-invoice-door.md` (**D-106**).
- **Recording a delivery is `transport.trip.deliver`**, mirroring PERM-004 — Owner, Operations,
  Dispatcher, Admin. A **Driver is deliberately denied** there and keeps `own` on PERM-010 for the
  POD itself. A driver submits their proof; an operator confirms the trip arrived.
- **Feedback is yours and nobody has specified it** — see
  `REQUEST-person3-feedback-is-yours.md`. It is in the 30 September demo script.

### Shivam — what changed for you

- Nothing in your tables. `FleetResourceGateway` still records intent and does nothing, so a
  dispatched or in-transit trip does **not** mark the vehicle busy. The dispatch panel says so on
  screen rather than implying the fleet was updated.
- **Container 360's Vehicle and Driver nodes are the only chain nodes with no link** (**D-110**).
  They carry `transport_vehicles`/`transport_drivers` ids and your screens resolve Fleet ids — a
  link that opens the wrong truck is worse than no link. A lookup by our id would close it;
  `vehicles.legacy_transport_vehicle_id` already holds the mapping.
- **And please read `URGENT-person2-migration-is-armed.md` first if you have not.** That one is
  time-sensitive.

---

## 3. Three standing rules, now in TEAM-CONTRACTS

Written because of the above, and they apply to all three of us:

1. **A block is not done until it has been walked in a real browser** — not tested, *walked*.
2. **First click, default values** — every defect above failed with nothing typed.
3. **Anything crossing the browser/server boundary needs a contract test**, and **prove the guard
   fires** by breaking the thing on purpose and watching it go red.
