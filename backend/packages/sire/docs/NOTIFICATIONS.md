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
| `sire.report.reopened` | assignee, `role:admin` |
| `sire.report.released` | assignee, reporter |
| `sire.report.production_validated` | reporter |
| `sire.report.closed` | reporter, assignee |
| `sire.report.on_hold` | assignee, reporter |
| `sire.release.approved` / `.released` / `.rolled_back` | `role:lead`, `role:admin` |
| `sire.release.overridden` | `role:lead`, `role:admin`, `role:qa` — deliberately the widest audience in the module |

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
