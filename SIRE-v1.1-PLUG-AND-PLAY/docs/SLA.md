# SLA

## Configuration — three settings keys, no new table

The brief was SLA configurable by **tenant, issue type, severity and priority**.
That is a four-dimensional matrix, and the obvious answer — a `sire_sla_policies`
table with CRUD and a settings screen — is more infrastructure than the problem
needs.

Instead, one ordered list read through `SireSlaProvider` (which reads the host's
settings store by default):

```json
sire.sla.policies = [
  { "match": { "type": "bug", "priority": "p1" }, "ack_minutes": 15,  "resolve_minutes": 240 },
  { "match": { "severity": "critical" },          "ack_minutes": 60,  "resolve_minutes": 960 },
  { "match": { "type": "enhancement" },           "ack_minutes": 480, "resolve_minutes": null },
  { "match": {},                                  "ack_minutes": 480, "resolve_minutes": 4800 }
]
sire.sla.warning_threshold = 0.8
sire.sla.pause_states      = ["on_hold", "ready_for_release", "released", "business_review", "approval"]
```

Tenancy is implicit — settings are per tenant — so one key covers all four
dimensions.

**Resolution:** the most **specific** matching policy wins, where specificity is
the number of keys in `match`. An exact tie resolves to the earlier entry. Order is
a tie-break, never the primary rule — a tenant reordering their list must not
silently change which policy applies.

**Fallback:** explicit policy → the severity row's own targets
(`sire_severities.ack_target_minutes` / `resolve_target_minutes`) → no SLA. A
matched policy with a `null` target **disables that clock deliberately** and does
not fall through: *"enhancements have no resolution SLA"* is a real answer.

## Two clocks

| Clock | Starts | Stops |
|---|---|---|
| Acknowledgement | `sla_started_at` | `acknowledged_at` — stamped on first triage or assign |
| Resolution | `sla_started_at` | **any** terminal status, not only `closed` |

Reopening resets `sla_started_at`, clears `acknowledged_at` and zeroes the pause
accumulator.

## Four states

`ON_TRACK` · `WARNING` · `BREACHED` · `PAUSED`

A **stopped** clock reports `ON_TRACK` or `BREACHED` depending on whether it
finished inside its target, and carries `met` so the UI can say *"acknowledged in
12m against a 30m target"* without a fifth word.

**A breach that has already happened stays breached even if the issue is then
paused.** Pausing does not un-breach anything.

## Pause

Two stored columns, `sla_paused_minutes` and `sla_paused_since`, maintained on
every transition into or out of a paused status.

The SLA **state** stays computed. What is stored is elapsed pause — a fact about
the timeline, not a derived verdict. Reconstructing pause intervals from the audit
trail would put an audit query behind every dashboard tile and every list row, on a
database the report says is shared by two deployments.

An open pause is **bounded by the moment the clock stopped**. Discarding it charges
the team for time the issue was parked; ignoring the bound accrues pause forever
after closure.

## Overdue vs breached

Both appear on the dashboard, and they are different questions:

- **Overdue** — still open, past its resolution deadline. A work queue.
- **SLA breached** — either clock breached, **including issues already closed
  late**. A reporting number.

Both read the `sla_*_notified_state` columns rather than a stored deadline. SLA
state is computed from settings a tenant can change at any time, so a denormalised
`sla_due_at` would be wrong the moment a policy was edited. Cost: up to one sweep
interval (15 minutes) of lag on the tile.

## Notification deduplication

SLA notices fire **once per clock per state**, tracked in
`sla_ack_notified_state` and `sla_resolve_notified_state`. Without it the
15-minute sweep would re-send every 15 minutes for as long as an issue stayed
breached.

The dedupe write and the dispatch share a transaction, so a crash between them
cannot produce a duplicate on the next run. The column records *the state the clock
reached* — written whether or not a notification went out — so muting
notifications does not blind the dashboard.

## Executable specification

`tests/fixtures/sla-cases.json` — 15 decision-table cases run by **both**
`tests/sla.test.mjs` and `tests/phpunit/Unit/Sire/SireSlaPolicyTest.php`. If the two
disagree, the fixture says which is wrong.

It caught two real bugs on its first run: the matcher never saw severity (so every
severity-keyed policy silently failed to match), and an open pause was discarded on
a stopped clock. Neither would have surfaced in review.
