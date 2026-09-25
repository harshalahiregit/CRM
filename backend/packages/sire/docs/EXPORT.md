# The brief — taking the backlog out, and sending the answers back

**For developers.**

Fixing a defect is the small half of the job. The expensive half is the round
trip: open the issue, read it, find the code, fix it, come back, move it on.
Forty issues is eighty page loads, and people stop reporting things long before
the developer stops paying for that.

The brief removes the round trip at both ends.

---

## Out: one file, grouped by screen

`GET /api/sire/export` returns the whole filtered backlog as one markdown
document. It takes the register's own filters, so what you export is what you
were looking at.

**Grouped by the screen it happened on, not by ticket.** This is the whole idea.
One code change usually closes several issues, because several people hit the
same broken screen. Ordering by screen turns "forty tickets" into "six files to
open"; ordering by date or severity — which is what every register does — keeps
them forty separate errands. Within a screen the order is severity then age.

Each issue carries what you would otherwise open six pages to collect: what
broke, what was expected, the route and record, the API call that failed, and
the browser. **Screenshots travel inside the file** as data URIs, so they survive
being pasted into an editor or a chat. `?images=0` gives links only, for a small
file to skim rather than work from.

It carries reproduction detail and internal screen names. Treat it the way you
would treat the codebase, not like a status report.

Requires `sire.export`.

---

## Back: tick the boxes

Every issue in the brief has a line under its heading:

```markdown
### SIR-000013 — Saving the lead returns an error

- [ ] **Done** — say what you changed:
```

Tick it, write one line, upload the file on the issue register. Every ticked
issue closes with your line as the reason.

```
POST /api/sire/reports/import     (multipart: file=… or text=…)
```

**It previews first.** `apply` must be sent explicitly. The file has been
outside the system — an editor, a chat, a coding assistant — and closing thirty
defect records is not something anybody should discover the result of
afterwards. The preview runs the same capability and guard checks as the real
call, so what it lists is what will happen.

Requires `sire.report.close`.

### What it does and does not do

- **Nothing guesses.** A ticked box is a person saying so. Asking a model "which
  of these look fixed?" gives an answer that is usually right, which is the worst
  possible property for something that closes defect records in bulk.
- **Tolerant about everything else.** The parser needs the issue number in a
  heading and whether a box is ticked. Headings may be reworded, notes rewritten,
  whole sections added or deleted by whatever the file passed through.
- **Safe to send twice.** Issues already closed are reported as skipped, not as
  errors — re-sending after fixing the last few is the normal way to use it.
- **Not a way across a tenant.** An issue number from another workspace reads as
  not found, exactly as it does everywhere else in SIRE.
- **It does not check your work.** Ticking an issue you have not fixed closes it
  just the same.

A tick with nothing written still closes, and the note records that no detail was
given rather than quietly keeping the template's own sentence.

---

## Or: never touch the file at all

`sire:close-from-commits` closes an issue when the commit that fixes it reaches
production. Nobody ticks anything, nobody opens a page — the developer writes the
commit message they were writing anyway:

```
fix: guard the null customer on the invoice builder (fixes SIR-000013)
```

A **closing verb** is required. `see SIR-000013` closes nothing.

The commit message becomes the resolution note, which is the best one available:
written by the person who made the change, at the moment they made it, rather
than typed into a box three days later.

Setting it up on a live deploy: **DEPLOYMENT.md §6b**.

---

## Many issues in one call

If you would rather drive it from a script than a file:

```
POST /api/sire/reports/transitions
{ "transitions": [
    { "report_id": 41, "action": "close_directly",
      "resolution_note": "what you changed" }
] }
```

Each entry gets its own transaction and its own result, so one failure cannot
roll back nineteen good ones. Capped at 100.

Every route on this page runs the same capability check, the same guard and the
same required fields as the buttons, and each issue is audited on its own. These
remove page loads, not rules.
