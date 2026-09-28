# Notifications

## SIRE builds no notification platform

Everything goes through `SireNotificationProvider`. SIRE adds **no** mailer,
template store, notification table, channel system or preference system.

`Services\Sire\SireNotifier` is a thin layer answering only the question a
generic engine cannot: **who, if anyone, should hear about this.**

```php
// SireNotifier, after it has decided the audience
$this->notifications->notify(
    (int) $report->tenant_id,   // tenant travels as data, never ambient
    $recipients,                // already de-duplicated, actor removed, collapsed
    SireEvents::QA_FAILED,
    ['report' => $report, 'actor' => $actor, 'roles' => $roles],
);
```

**Integration:** implement two methods — `notify()` and `accepts()` — and register
SIRE's event keys in your template catalogue. Channels, per-tenant templates, user
preferences, escalation and your own dedupe all stay where they are.

**What not to reimplement.** By the time the adapter is called, SIRE has already
removed the actor, de-duplicated recipients, collapsed low-value events into a
one-hour window and suppressed repeat SLA alarms. Doing any of that again
double-sends. See [HOST-INTEGRATION](HOST-INTEGRATION.md) section 4.

## Events

| Event | Default recipients |
|---|---|
| `sire.report.created` | `role:admin` |
| `sire.report.assigned` | assignee |
| `sire.report.reassigned` | new assignee **and the previous one** |
| `sire.report.assignment_accepted` | reporter |
| `sire.report.development_started` | reporter |
| `sire.report.ready_for_qa` | qa assignee, `role:qa` |
| `sire.qa.started` | assignee |
| `sire.qa.passed` | assignee, reporter |
| `sire.qa.failed` | **assignee** — a failed QA run nobody is told about is a stalled issue |
| `sire.sla.warning` | assignee, `role:lead` |
| `sire.sla.breached` | assignee, `role:lead`, `role:admin` |
| `sire.report.reopened` | assignee, `role:admin`, **watchers** |
| `sire.report.released` | assignee, reporter, **watchers** |
| `sire.report.production_validated` | reporter, **watchers** |
| `sire.report.closed` | reporter, assignee, **watchers** |
| `sire.report.on_hold` | assignee, reporter |
| `sire.release.approved` / `.released` / `.rolled_back` | `role:lead`, `role:admin` |
| `sire.release.overridden` | `role:lead`, `role:admin`, `role:qa` — deliberately the widest audience in the module |

## Watchers

The reporter and the assignee are told because of the ROLE they hold on the
issue, and that role moves — so neither is stored as a watcher. Reassigning would
otherwise leave a stale row behind and keep mailing somebody about an issue that
is no longer theirs.

A watcher is everybody else with a reason to care and no role that says so: the
lead of the module it broke in, the account manager whose customer filed it, the
developer who wrote that code last quarter.

```
POST   /api/sire/reports/{report}/watchers            watch it yourself
POST   /api/sire/reports/{report}/watchers {user_id}  add somebody else
DELETE /api/sire/reports/{report}/watchers/{user}     stop
GET    /api/sire/reports/{report}/watchers            who is watching
```

**Subscription, never permission.** Every one of those routes runs the same
ownership check as the rest of SIRE, so adding a watcher can never widen what
that person may read. If they could not open the issue before, they still cannot
— they will simply be told about one they cannot open. That is the right failure;
the alternative is a subscribe endpoint that quietly grants access.

Adding **somebody else** needs `sire.report.triage`, because it puts mail in
another person's inbox. Removing **yourself** needs nothing: being unable to stop
a notification you did not ask for is how people build an inbox rule and stop
reading any of it.

Watchers ride on the **outcome** events only — closed, released, validated,
reopened. Somebody who subscribed wants to know how it ended, not that a
developer pressed start this morning.

## Four anti-spam rules

1. **The actor never hears about their own action.** By a wide margin the largest
   source of pointless notifications — a developer does not need an email saying
   they started development.
2. **Recipients are de-duplicated.** Reporter and assignee are frequently the same
   person.
3. **Low-value events collapse** to one per recipient per issue per hour. Nothing
   meaning *you now have work* is ever collapsible.
4. **SLA notices fire once per clock per state.** See [`SLA.md`](SLA.md).

A tenant can silence an event with `sire.notifications.disabled` — a mute list in
the existing settings store, not a new preferences table. The release-override
event ignores it: that one is everyone's business.

## No queue

SIRE dispatches **nothing** to a queue. The report records no worker in production.
Whether the engine itself queues internally is the engine's business and unchanged
either way.
