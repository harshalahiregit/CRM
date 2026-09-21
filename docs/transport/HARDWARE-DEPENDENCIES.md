# Hardware — what needs it, how it will work, and what we do until it arrives

**Owner:** Person 2 (Fleet & Telemetry) · **Started:** 2026-09-19 · **Status:** LIVING FILE
**Standing decision:** **hardware work is parked.** Nothing that requires a physical device gets
built right now. Every place hardware touches the system is written down here instead, so that
when the devices arrive nobody has to rediscover it.

> **How to use this file.** If you are about to build something and it needs a real GPS box, a
> temperature probe or a genset sensor to work — stop, and add it here instead. If you are
> touching something already listed here, read its row first.

---

## 1. What "hardware" means here

Four physical things, and nothing else in Transport depends on a device:

| Device | Fitted to | Sends | How it reaches us |
|---|---|---|---|
| **GPS tracker** | Every vehicle | Position, speed, ignition | Posts to our API |
| **Reefer temperature probe** | Refrigerated trailers | Body temperature °C | Same message as the GPS |
| **Genset sensor** | Reefer power units | Running or off | Same message |
| **FASTag toll tag** | Every vehicle | Toll crossings | Provider's feed, not our API |

The first three are one box sending one message. The fourth is completely separate and comes from
the toll provider, not from the truck.

---

## 2. How it is meant to work when the devices exist

A tracker sends a small message every few minutes:

```
POST /api/v1/telemetry/ingest
X-Device-Token: stos_dev_xxxxxxxx

{ "device_id": "DEV-0001", "latitude": 19.076, "longitude": 72.877,
  "speed": 46.5, "ignition": true, "generator_status": "on",
  "temperature": -18.5, "recorded_at": "2026-09-19 14:32:00" }
```

**The whole path is already built and tested.** What is missing is only the devices.

1. The **device token** identifies which unit and which company is calling.
2. The message is **stored twice** — once as history (kept forever), once as "where it is now"
   (overwritten).
3. A ping that is **older** than the one we already hold is kept in history but does not move
   "now" backwards. This matters because a truck leaving a tunnel sends its whole backlog at once.
4. The **same ping sent twice is stored once**, so a device retrying on a bad signal cannot double
   a journey.
5. If a reefer's **genset is off while the load is too warm**, the system raises a temperature
   excursion — the cold chain is breaking at that moment.

A unit with no signal for an hour can send its whole backlog in one request rather than sixty.

---

## 3. Where hardware touches the system — the full list

| # | Place | Needs a device? | What happens today with no device |
|---|---|---|---|
| 1 | `POST /v1/telemetry/ingest` | **Yes** | Endpoint works; nothing calls it |
| 2 | `POST /v1/telemetry/ingest/batch` | **Yes** | Same |
| 3 | Device tokens (issue / rotate / revoke) | **Yes** | Screens work; no unit to give a token to |
| 4 | "Where is it now" live panel | **Yes** | Shows *no signal* |
| 5 | Live map position | **Yes** | Blank |
| 6 | Temperature excursion alert | **Yes** | Never fires |
| 7 | Genset running / off | **Yes** | Unknown |
| 8 | **Nearest-truck scoring** in allocation | **Yes** | Distance is `null`; ranking uses the other three factors — see §4 |
| 9 | **"Position unknown" blocker** | **Yes** | ⚠️ **See §4 — this one bites** |
| 10 | Fleet grid tiles *moving / idle / offline* | **Yes** | Everything reads *unmonitored* |
| 11 | Demo step 8 — GPS/temp on screen | **Yes** | **Parked.** Cannot be shown without data |
| 12 | FASTag toll import | **No device** — provider feed | Nothing imports; rows only from the seeder |
| 13 | Fuel & urea litres | **No** | Typed from the paper receipt |
| 14 | Odometer readings | **No** | Typed from the fuel receipt |
| 15 | Job cards, tyres, documents, compliance | **No** | Fully working |
| 16 | Driver licence & availability | **No** | Fully working |

**Good news:** items 12–16 are most of the day-to-day work, and none of it needs a device.

---

## 4. Two things that behave badly with no hardware — read before a demo

### (a) A fitted device that never reports BLOCKS the truck

In `VehicleAllocationService` there is a rule: if a vehicle has a `gps_device_id` recorded but has
not reported recently, it is **blocked from allocation** with *"Position unknown."*

The reasoning is sound with real hardware — a tracker that has gone quiet is more worrying than a
truck that never had one, because something has failed. **With no hardware at all it is wrong:**
every vehicle that has a device id typed into its record becomes unallocatable, and nothing
explains why.

**What to do for now:** leave `gps_device_id` **blank** when adding a vehicle. A vehicle with no
device id is only warned about, never blocked. Do not change the rule — it is correct for the real
world. Fill the device id in on the day the box is actually fitted.

### (b) Nearest-truck ranking quietly stops working

Allocation ranks trucks on four things: how near, how fuel-efficient, how heavily used, and
whether a driver is free. **"How near" needs GPS.** With no device, distance is recorded as
*not measured* rather than as zero — deliberately, because a zero would make every truck look like
it was parked at the pickup point.

So the ranking still works and is still honest; it is just using three factors instead of four.
Nobody needs to do anything, but if someone asks *"why is the score lower than I expected"*, this
is usually why.

---

## 5. What is parked, and what unparks it

| Parked | Unparked by |
|---|---|
| Demo step 8 — GPS/temperature on the trip or container screen | Real pings, **or** a decision to seed fake ones |
| `trip_events` telemetry entries (`gps.position`, `genset.on`, …) | Same |
| Route / movement anomaly detection (T-55) | Real pings |
| Per-device token rollout | Devices existing to receive them |
| FASTag import & reconciliation (T-25/26/27) | The toll provider's feed, **not** a device |

> **Open question for the owner.** Demo step 8 currently reads NOT REACHABLE, and the 30 September
> demonstration is meant to show a GPS/temperature reading. With hardware parked there are only
> two honest options: **seed believable sample readings and label them as sample data**, or **drop
> step 8 from the demonstration**. Quietly showing invented numbers as if they were live is the
> one thing not to do.

---

## 6. When the devices do arrive — the order

1. Fit one box to one truck and give that unit its own token.
2. Watch a single ping land. Check the vehicle's live panel wakes up.
3. Fill in `gps_device_id` on that vehicle — and only then, because §4(a).
4. Confirm the tunnel case: switch it off for an hour, back on, check the backlog lands and
   "now" does not jump backwards.
5. Roll out unit by unit. The token list names every vehicle still without its own credential.

---

## 7. Change log

| Date | Change |
|---|---|
| 2026-09-19 | File created. Hardware work parked by the owner; everything device-dependent recorded here instead of built. |
