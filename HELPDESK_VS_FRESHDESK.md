# Sangoe Helpdesk vs Freshdesk — Parity Analysis

_Generated 2026-07-13. Compares our current Helpdesk build against the Freshdesk
feature reference. Percentages are estimates weighted by feature surface, verified
against our actual schema/code (not marketing)._

---

## TL;DR — two honest numbers

| Metric | Score | What it means |
|---|---:|---|
| **Full Freshdesk breadth** | **~30%** | Against *everything* Freshdesk ships (marketplace, multi-brand, forums, mobile SDK, enterprise governance, Freddy AI). We will never win here — nor should we try. |
| **Core day-to-day agent workflow** | **~62%** | The stuff agents actually touch every day: ticketing, inbox, workspace, collaboration, KB, canned responses. This is where we're genuinely competitive. |

**The gap that matters most:** the reference itself says the three most-used
daily features are **SLA policies, canned responses, and CSAT**. We have **canned
responses ✅**, **CSAT capture (partial) ✅**, and **no real SLA engine ❌**. So the
single highest-leverage thing we can build is a **real SLA engine**.

---

## Section-by-section match

| # | Freshdesk area | Match | Status |
|---|---|---:|---|
| 1 | Ticket object & lifecycle | **72%** | Strong — missing Type field, custom-status SLA toggle, contact↔company |
| 2 | Automation (Dispatch'r/Observer/Supervisor) | **8%** | Only 2 hardcoded transition rules; no rules engine |
| 3 | SLA management | **12%** | Visual badge only; no timers/pause/escalation/business hours |
| 4 | Collaboration | **68%** | Notes, canned responses, related, merge, convert-to-task ✅; no threads/collision |
| 5 | Self-service (KB, widget, portal) | **58%** | KB + portal + widget ✅; no forums/multilingual/multi-brand |
| 6 | AI (Freddy suite) | **10%** | Summary is a labelled stub (no LLM key); suggestions are rule-based |
| 7 | Reporting & analytics | **28%** | Manager dashboard + CSAT capture; no custom reports/exports/team dash |
| 8 | Multi-brand / multilingual | **8%** | Single English portal (multi-tenant ≠ multi-brand) |
| 9 | Mobile | **15%** | Responsive web only; no native apps / SDK |
| 10 | Marketplace & integrations | **18%** | REST API ✅ + native Projects/Tasks link ✅; no marketplace/webhooks |
| 11 | Security & governance | **25%** | Multi-tenant isolation + auth/roles; no audit log/SSO/sandbox/field-ACL |

---

## ✅ What we match (and where we're actually *better*)

**Ticket lifecycle — closer than expected**
- Custom statuses / priorities / departments (data-driven, tenant-scoped).
- **Auto-reopen**: a customer reply to a `closed` ticket flips it back to `open`
  — Freshdesk's signature "don't lose track of it" safety net. ✅
- Staff reply to an `open` ticket auto-moves it to in-progress. ✅
- Merge duplicates (surviving ticket + redirect). ✅
- Sources tracked (widget/email/etc.), tags, CC, attachments. ✅

**Collaboration — our strongest area**
- Private notes (internal-only, amber). ✅
- **Canned responses** — full CRUD + category grouping + shortcuts + a searchable
  composer picker. ✅ (one of Freshdesk's top-3 daily features)
- Related tickets, merge, **convert ticket → tasks**, link to a project. ✅
- Reply **/** Note toggle, **Send & Resolve**, keyboard shortcuts, optimistic UI. ✅

**Self-service**
- KB: categories → sub-categories → articles, draft/publish, article feedback
  (helpful/not), **starter templates**, a professional public article page with
  auto table-of-contents + reading time + copy/print. ✅
- Embeddable widget with public key + public KB browse/search + submit ticket. ✅

**CSAT (partial but real)**
- One-click 1–5 rating from the closure email → thank-you page. ✅

**Where we already beat Freshdesk**
- **Native Business-OS integration** — a ticket converts into real tasks/projects
  *inside the same app*. Freshdesk needs a paid Jira/Asana connector for this.
- **Modern agent workspace** — Universal Data Grid (density, saved views, bulk,
  inline edit, CSV), optimistic UI, keyboard-first, command palette (⌘K).
- **No per-agent AI/seat metering** — Freddy Copilot is a paid per-agent add-on.
- **Self-hostable + tenant-isolated** by design.

---

## ❌ What's missing (prioritized)

### Tier 1 — closes the "serious support tool" gap (build these)
1. **Real SLA engine** — first-response + resolution targets per priority,
   business-hours calendars, live countdown, pause-per-status, breach escalation,
   and a "breaching soon" view. _(We only have a `due_date`-derived badge.)_
2. **Automation rules engine** — a light Dispatch'r/Observer/Supervisor: on-create
   routing, on-update reactions, time-based escalation. Plus **macros** (one-click
   status + tag + assignee + canned reply).
3. **Full CSAT** — survey management, results sliced by agent/group/channel in
   analytics (we capture ratings but don't report on them).

### Tier 2 — high-value, medium effort
4. **Round-robin / load-balanced assignment** + agent workload view.
5. **Agent collision detection** (who else is viewing/replying).
6. **Real AI** — wire an LLM key so the summary is real; add reply suggestions,
   sentiment, and a ticket-field suggestor (Copilot-style).
7. **Reporting** — custom reports, scheduled exports, team/availability dashboard.
8. **Contact ↔ Company** — real customer/account entity (currently mocked) for
   account-level history and SLA.

### Tier 3 — enterprise breadth (only if the market demands it)
9. Threads (separate internal discussion layer), community forums, multilingual
   KB, multi-brand portals, native mobile apps/SDK, marketplace, webhooks,
   audit log, SSO/SAML, sandbox, field-level access control, time tracking.
10. Ticket **Type** field, numeric status codes, customer-facing status labels.

---

## My opinion — would this make us better than Freshdesk?

**Not by chasing parity — but yes, for the right audience, by a different route.**

Freshdesk wins on **breadth**: 1,200+ marketplace apps, multi-brand, forums,
native mobile, a mature AI suite, and enterprise governance. Trying to match all
of that is a multi-year treadmill we'd always be behind on. Our ~30% breadth
score reflects that, and it's fine.

Where we can genuinely be **better** is the thing Freshdesk structurally *can't*
do well: **being one module of an integrated Business OS.** In Sangoe, a support
ticket lives next to the customer's projects, tasks, invoices, and HR records,
in one app, with one login, one data model, and no connector tax. A support agent
turning a ticket into a tracked project task — natively, instantly — is a workflow
Freshdesk sells as a paid integration and still does clumsily. That integration
story, plus a faster/cleaner modern UI and no per-agent AI metering, is a real
wedge for **Indian MSMEs scaling up** — exactly the Sangoe thesis.

**But** — and this is the honest part — to be *taken seriously* as a support desk
at all, three gaps are non-negotiable: **a real SLA engine, a basic automation/
macro engine, and CSAT reporting.** Those are table stakes; without them we're a
beautiful ticket viewer, not a helpdesk teams trust for SLAs. Ship Tier 1, wire a
real LLM key for the AI, and the integrated-OS advantage starts to *outweigh*
Freshdesk's breadth for our target customer.

**Verdict:** We're at ~62% of the daily-driver experience and already ahead on
integration and UX. Close Tier 1 (SLA + automation + CSAT reporting) and this
becomes a genuinely better choice than Freshdesk **for an integrated-suite MSME**
— while never trying to out-breadth them on the enterprise/marketplace front.

---

_Recommended next build: **the SLA engine** — highest impact, and the clearest
"we're a real helpdesk now" signal._
