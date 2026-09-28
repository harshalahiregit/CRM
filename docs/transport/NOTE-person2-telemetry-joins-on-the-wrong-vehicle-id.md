# Your telemetry reaches the timeline, and then joins on the wrong vehicle id

**From:** Person 1 · **To:** Person 2 (Shivam), cc the lead · **2026-09-19**

## First, the good part

`TripTimelinePublisher` is the right shape and it works. Emitting on change rather than per ping,
keeping the full trail in `telemetry_records`, and using the device's clock for `occurredAt` — all
three are judgements I'd have wanted and didn't have to ask for. The docblock explaining why a
timeline is not a trail is better than the one I wrote for the recorder.

I fed it a reading directly and it produced exactly what step 8 of the 30 September script asks
for:

```
gps.activated        —  Tracking active on MH12DEMO01
temperature.reading  —  4.2°C on MH12DEMO01
```

## The problem: `openTripFor()` compares two different id spaces

```php
->where('vehicle_id', $vehicle->id)     // $vehicle is a Fleet Vehicle
```

`transport_trips.vehicle_id` holds a **`transport_vehicles`** id — my placeholder table. That is
D-100(c), the repoint that has not happened. The two tables issue ids independently and there is
no foreign key on the column, so this is two unrelated integers being compared.

**Right now it fails silent.** Fleet holds ids 1–2 here, `transport_vehicles` holds 35–36, nothing
overlaps, and every reading falls into your `if (! $trip) return;` branch — which reads as "a truck
idling in the yard with no trip". That is why I could not walk step 8 even after your commit
landed, and why I nearly wrote it up as "still no read contract". The contract is built. It joins
on the wrong key.

**It does not fail silent once the ranges overlap.** I constructed that case to find out which kind
of bug this is: a trip `in_transit` with `vehicle_id = 1`, and a reading published for **Fleet**
vehicle 1. Both events above landed on that trip — naming a truck that is not the truck the trip's
`vehicle_id` points at. No error, no log line, a plausible-looking timeline entry. I removed the
rows and put the trip back.

## Why I'd fix it before the repoint, not after

The repoint is what creates the collision. Afterwards, some trips will carry Fleet ids and older
rows will still carry `transport_vehicles` ids, and **both will match something.** At that point
the wrong-join is mixed into real data and is very hard to tell from a correct one. Today it is
inert and easy.

Two options, and the choice is yours:

1. **Resolve through the gateway.** `FleetResourceGateway` is the seam that exists for this. A
   `tripForFleetVehicle(Vehicle $v)` on that interface keeps the id translation in one place and
   survives the repoint without a second edit.
2. **Match on the registration number** rather than the id, which is the one identifier both
   tables agree on today. Cruder, and it does not need D-100(c) resolved first.

I have not touched your file — it is yours, and the standing rule is not to edit another section's
code to make my step pass. Logged as **D-116**.

## One thing this changes at my end

MS-001 §14 **step 8 stays NOT REACHABLE**, with the reason corrected. The walk document used to say
it needed a read contract from telemetry to the trip. It does not; it needs that contract to join
on something both sides mean the same way.

Tell me when it lands and I will re-walk it the same afternoon.
