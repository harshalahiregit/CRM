# SIRE — issue brief, sent back (23 Sep)

**Upload this file on the issue register** (Export issue brief → Send it back).
Only the ticked issues close. You are shown exactly what will close before
anything does, and re-uploading is safe.

Written against the 17-issue export of 23 Sep 07:52 (scope: `open`). Audited
against the code, not against commit messages — two commits in this repo mention
a SIR number without fixing anything (one as test data, one as a screenshot
caption).

**A correction to the first pass.** It listed SIR-000004, SIR-000005, SIR-000006
and SIR-000015 as "another owner's module — nothing done". All four are built
and on master; two of them carry the SIR number in a code comment. They were
checked against one developer's uncommitted work rather than against the branch,
which is the wrong question to ask. They are ticked below.

Of the 17 open issues: **six close on this upload**, one needs ten seconds on the
live site first, ten are written and tested but not yet committed, and one is
sitting with QA rather than with engineering.

---

## Ticked — verified in the code and on master

### SIR-000004 — Line item

- [x] **Done** — The catalog box on a purchase request accepts typing whatever the
  catalog holds. It used to be `disabled` whenever the catalog was empty, so on a
  fresh workspace it took no characters at all, and the only other way in was an
  Add Item button that a flex-shrink bug had collapsed to nothing — between them
  there was no way to put a line on a request. A catalog match brings its SKU,
  unit, rate and any contract rate; anything else becomes a free-text line with
  the typed words already in it.

### SIR-000005 — Ref. No.

- [x] **Done** — Reporting an issue now ends on a receipt that stays until it is
  dismissed, instead of a toast that showed the SIR- number for a few seconds and
  closed itself. It carries the reference number with a Copy button, says in
  words where to watch the issue (Issues & Quality → My Work), and offers **View
  my issues** and **Open this issue**. The same bug was being reported twice
  because nobody could write the number down in time.

### SIR-000006 — Onboarding Vendor

- [x] **Done** — The vendor's onboarding panel showed "Step 1 of 6", which says
  where a vendor is but not what the steps are or which one is next. All six are
  now listed in order, each with the server's own one-line detail ("3/7
  uploaded", "2 rejected"), the completed ones ticked, and the first incomplete
  one marked **Next step** rather than left for the reader to work out.

### SIR-000015 — Bulk Upload

- [x] **Done** — Both halves are there: workforce via `POST
  /purchase/workforce/workers/upload` and medical certificates via `POST
  /purchase/medical/bulk`, each reachable from Purchase → Workers. The medical
  import takes the certificate files alongside the sheet and reports how many
  were imported and how many were rejected.

### SIR-000036 — Vendor/TPV

- [x] **Done** — The project's Vendors/TPV tab assigns named people inside a
  client, a purchase vendor or a TPV vendor, several at a time, rather than only
  the company (25bacfcf), covered by ProjectPartyAssignmentTest.

---

## Not ticked — check the deploy first, then tick

**SIR-000011 — Port Issue.** Fixed and on master, but a fix only counts once it is
live. Open Report Issue on any Transport page (`/app/transport/trips/...`); if
MODULE and SECTION are filled in rather than blank, tick it and re-upload.

The fix affects **newly filed** issues. Reports already in the register keep the
context captured when they were filed, so the older Purchase issues in this brief
will still show no section or screen. That is history, not a bug.

---

## Not ticked — fixed today, not on master yet

Ten issues, all written and all covered: 54 new tests across five files, and the
Purchase, Project and Shared suites are green. None of it is committed.

They are left unticked deliberately. A tick closes the issue on the register, and
an issue closed against a fix nobody can use yet comes straight back — the same
reason SIR-000011 is sitting above waiting on a deploy. Tick this section in one
pass once the branch is merged and live.

### Projects

- [ ] **SIR-000037 — Expenses Form.** The form asked for four fields; the expense
  register needs a category, a reference number, a payment mode, a currency, tax
  and the receipt itself. The columns are there now, the receipt uploads and
  downloads, and the CSV export carries the new columns. The expense_categories
  master had been sitting in the database with nothing reading it — this is what
  reads it. Covered by ProjectExpenseFormTest (11 tests).

- [ ] **SIR-000039 — Member Add.** A Members card on the project page adds and
  removes people in place — staff, and named people inside a vendor or TPV
  company — instead of reopening the whole edit drawer to change one field.

- [ ] **SIR-000040 — Upload Milestone.** An **Import sheet** button on the
  Milestones tab, reading csv/txt/xls/xlsx/zip through the same reader the
  workforce import uses. Each row reports its own error rather than the sheet
  failing whole, and a row with no due date is named in the result — that column
  is NOT NULL, so the alternative is a silent half-import. Covered by
  MilestoneImportTest (10 tests).

- [ ] **SIR-000042 — Assign Members.** Assign on the row, in the project's task
  table, several people at once.

### Purchase and TPV

- [ ] **SIR-000013 — PPE issues.** The PPE step could only ever *return* kit and
  show what was already held — there was no list to issue from, so "PPE list is
  not showing to select" was literally true on the screen the issue names. The
  catalogue renders there now: out-of-stock items are shown disabled rather than
  hidden, because "the helmet is not on the list" and "the helmet has run out"
  are different problems, and issuing more than is held warns first.

  Two things to know before ticking. The picker built for this last session went
  into PurchaseWorkerDetail, which `/app/purchase/workers/:id` does not render —
  it is reachable from Workforce, just not from the route the issue was filed
  from, which is why it looked untouched. And the **422 on induction** is not
  patched directly: it was the step after the one that could never be completed.
  Issue a kit on the running site and then run the induction before ticking.

- [ ] **SIR-000027 — Vendor Code.** `purchase_vendor` has been a configurable type
  in Settings → Numbering the whole time — prefix, width, date parts, reset rule.
  The generator hard-coded PV-0001 and read none of it, so the honest answer to
  "how do I set the vendor code?" was: you can, the screen saves it, and nothing
  reads it. It reads it now. Workspaces that never turned numbering on keep
  PV-0001 — that type throws when it is disabled, and left unhandled that turns
  "create a vendor" into an error for everyone who never visited the screen.
  Covered by VendorCodeFollowsNumberingTest (8 tests).

- [ ] **SIR-000028 — Vendor Prequalification**
- [ ] **SIR-000029 — Prequalification**

  One fix, because they are one issue. Every drop-down on the prequalification
  form *is* a question in config/purchase_prequalification.php — 280 lines of
  PHP, identical for every workspace and changeable only by a developer with a
  deploy. Purchase Settings → Prequalification now edits the whole thing:
  sections, questions, answers, and the score each answer carries.

  The result is a sum over the maximum available, so the save refuses what would
  break it — a section with no questions, a question with one answer, answers
  that all score the same, a non-numeric score — and names the question at fault.
  The shipped questionnaire stays the default and nothing is migrated, so a
  workspace that never opens the screen sees no change at all, and **Back to
  standard** returns it. Covered by PrequalificationCatalogueTest (14 tests).

- [ ] **SIR-000030 — Meeting.** Five numbered points; here is each one.

  **1. Recheck the meeting module documents.** Meeting.docx is covered by its own
  compliance tests (MeetingDocComplianceTest, KickoffMinutesMatchThePdfTest and
  the extended-flow suite). Nothing new was found against it in this pass.

  **2. Why is Meetings inside Purchase vendor?** It is not any more. Meetings is
  its own module at `/app/meetings`, with its own sidebar entry, and the Purchase
  and TPV screens now render the same shared meeting rather than a Purchase-only
  copy. It reads across every module.

  **3. Organiser, chair and coordinator from the contact list; Customer & Client
  name not required.** All three are type-or-pick against the real contact list
  rather than free text typed against a list you cannot see, and the duplicate
  free-text "Client name" box is gone.

  *One judgement call worth a second opinion:* the point reads as though both
  customer fields should go, and the **Customer picker was kept**, optional. It
  feeds the §13 distribution list, so removing it costs behaviour rather than
  clutter. If the intent was to remove both, say so — it is a one-line change.

  **4. Schedule & Location above Participants.** Moved. You agree when a meeting
  is before working out who is in it.

  **5. How the DECISION and ISSUES RAISED sections work.** Walked end to end with
  dummy data and left behind as a test (MeetingDecisionAndIssueFlowTest, 11
  tests, all passing). The flow is sound; no defect was found. In short:

  - A **decision** is content. It is what the meeting decided, it is edited by
    whoever edits the meeting, and its only lifecycle is Active → Superseded /
    Rescinded when a later meeting overrules it. It is filed as DEC-####.
  - An **issue** is a record with a life of its own. It is filed as ISS-####,
    opens on the day it is raised, and moves Open → In Progress → Resolved →
    Closed, with Reopened and Cancelled. Moves off that map are refused: an issue
    that was never resolved cannot be closed in one click.
  - Both read across every meeting from Meetings → Registers, with the issues
    still needing work sorted above the ones already closed.
  - Re-saving the meeting form can correct the **wording** of either without
    touching its **status** — so a chair fixing a typo a fortnight later does not
    drag an in-progress issue back to Open, or a superseded decision back to
    Active. That is the one thing in this flow that would have quietly lost
    somebody's work, and it is now held down by a test.

### Helpdesk

- [ ] **SIR-000002 — Mismatch.** Five numbered points; all five are now done. The
  popup click (1) and multiple image attachment (4) were already on master. New
  today: **Due by** on the New Ticket form (2) — the column and the request had
  accepted `due_date` all along and only the form never asked for it — and
  **Public link & embed** in the ticket queue header (3), going to the widget
  page, so the embed code is reachable from where tickets are worked rather than
  only from settings. The reference screenshots (5) were the basis for both.

---

## Not ticked — with QA, not with engineering

**SIR-000017 — Assign Developer.** The picker is built and the issue sits at
`ready_for_qa`. A developer is assigned from the transition bar on the case: one
owner plus any number of co-assignees, because every workflow guard is written
against a single owner. It needs a QA verdict, not a code change — closing it
from here would skip the pass it is waiting for.

---

## Still wrong on the live server

Every "full size" evidence link in this brief still points at
`http://localhost:5173`, so they are broken for anyone who opens the document.
The embedded screenshots are fine — only the links are dead.

```
FRONTEND_URL=https://<your live frontend host>
```

then `php artisan config:cache`. This is an .env change, not a code change, so
no deploy will fix it on its own.
