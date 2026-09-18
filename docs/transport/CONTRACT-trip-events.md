# `trip_events` — the timeline all three of us write to

**From:** Mohammad (Person 1) · **2026-09-18** · **Shivam and Zafar — this one is for you both.**

---

## Why this exists, in one paragraph

STOS-CTD §31 gives the Digital Passport's timeline as a worked example, and **seven of its
sixteen entries are yours**:

```
08:10  Order Approved          P1        13:10  Port Entry              P2
09:15  Driver Allocated        P1        14:00  Port Exit               P2
09:20  Vehicle Allocated       P1        16:30  Delivery Location       P1
10:05  Documents Handed Over   P3        16:42  Delivery Confirmed      P1
10:30  Dispatch                P1        16:45  Feedback Requested      P3
10:32  GPS Active              P2        16:48  Feedback Received       P3
10:35  Genset ON               P2        17:00  POD Uploaded            P3
                                         17:15  Billing Ready           P3
```

Until today the Passport's timeline read `transport_audit_logs` — **our** audit trail, written
from inside **our** models by **our** service. For you to contribute to it you would have had to
write into the record of what P1 did. That is the wrong shape, and CTD §32 says why:
*"Timeline must combine events from all connected systems."*

`trip_events` is a shared table with one documented entry point. It is now live, and
`stos:backfill-trip-events` has carried the existing history into it, so nothing lost a timeline.

---

## How you write to it

```php
app(\App\Services\Transport\TripEventRecorder::class)->record(
    type: 'genset.on',
    trip: $trip,                       // or tripId: 41 if you only have the id
    actor: $user,                      // null for a machine
    detail: ['reading_c' => -18.2],    // yours; nobody else parses it
    occurredAt: $ping->recorded_at,    // WHEN IT HAPPENED, not now
);
```

That is the whole contract. **You name the event; the registry supplies the rest** — you do not
need to know CTD §101's nine categories or §33's ten sources.

Four things worth knowing:

1. **`occurredAt` matters.** `occurred_at` is when it happened; `recorded_at` is when we heard
   (STOS-DB §19). A telemetry batch buffered for an hour has two different times, and the
   timeline is ordered by the first — otherwise an hour of driving renders *after* the delivery
   that followed it. **Shivam: pass the device's timestamp, not `now()`.**
2. **It never throws into you.** A timeline entry is a record *of* work, not part of it. If the
   write fails you get `null` and a log line; your ingest is not rolled back and your dispatch
   does not fail. (Deliberately unlike the audit log, which does throw — losing an audit row
   silently is a compliance defect; losing a timeline entry is a gap in a story.)
3. **Events are immutable.** CTD §34. The model refuses `update` and `delete`. To correct one,
   append: `->correct($original, 'What it should have said', $user)`. Both rows stay readable,
   so the correction is part of the history rather than a quiet overwrite of it.
4. **In-process only.** There is no HTTP write endpoint, because nobody has asked for one and it
   is a separate decision — device authentication, idempotency for a retried batch, rate limiting
   for a chatty sensor. Say the word if you need one and we will design it rather than inherit it.

---

## The types you already own

`event_type` is an **open column**. Both source documents label their type lists *"Example"*, and
a locked enum would mean neither of you could record anything without a migration from me. That
is exactly the bottleneck this three-way split exists to avoid.

**Anyone may append to `TripEventType::REGISTRY`.** No approval, no migration, no ticket —
announce it in the group. Add the category, the source, the owner, a human sentence and the line
it comes from.

Already registered and waiting for you:

| **Shivam (P2)** | | **Zafar (P3)** | |
|---|---|---|---|
| `gps.activated` | CTD §31 "10:32 GPS Active" | `documents.handed_over` | CTD §31 "10:05" |
| `gps.position` | CTD §32 "GPS" | `pod.uploaded` | CTD §31 "17:00" |
| `genset.on` / `genset.off` | CTD §31 "10:35 Genset ON" | `pod.verified` | STT-008 |
| `temperature.reading` | CTD §32 | `billing.ready` | CTD §31 "17:15" |
| `temperature.excursion` | CTD §17 | `invoice.posted` | EVT-010 |
| `port.entry` / `port.exit` | STOS-DB §37 | `collection.recorded` | EVT-011 |
| `gate.in` | STOS-DB §37 "GATE_IN" | `feedback.requested` / `.received` | CTD §31 "16:45/16:48" |
| | | `compliance.checked` | CTD §32 |

**The one rule: do not write a type that is not in the registry.**
`TripEventRegistryTest` reads every distinct `event_type` in the table and fails on the first one
missing. That is the whole price of an open column — without it we get `TemperatureAlert`,
`temp_alert` and `TEMPERATURE_BREACH` describing one thing within a month, and a timeline with
three names for one event is not a timeline.

An unregistered type is still **recorded**, not refused, and still renders as English
(`gate.weighbridge` → "Gate weighbridge"). You are never blocked; you are just visible.

---

## What this unlocks, concretely

**Shivam** — MS-001 §14 step 8 is *"Show GPS/temperature/generator event"*, and my walk of the
fourteen on 18 September recorded it as **NOT REACHABLE**: you shipped telemetry ingestion, and
there was no way to get from a container to a reading. Three `record()` calls in
`TelemetryIngestionService` and that step turns green — the events land on the container's
passport with no change on my side.

**Zafar** — step 10 is *"Record delivery, feedback and POD"* and step 12 is *"Show invoice
linkage"*. Delivery is mine and works. `pod.uploaded` and `billing.ready` from your services put
the rest of that story on the same screen. (Feedback is still unspecified — see
`REQUEST-person3-feedback-is-yours.md`.)

---

## And a gap worth knowing about — D-113

**Step 11's canonical registry has no events table.** DB-001…DB-020 contains no `trip_events`, no
field rows, no API row and no permission row — while STOS-DB §37 names the table outright and CTD
builds the Passport on it across §§31–35, 101 and 133.

So every column in the migration **cites the line it comes from**. That citation is what stands
in for the registry row that does not exist, and it is the only reason building this counts as
implementing the documents rather than inventing a table. Please keep that habit when you add
types: the `cite` field is not decoration.
