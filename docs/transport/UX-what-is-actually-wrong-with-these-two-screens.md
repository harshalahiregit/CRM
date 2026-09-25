# The trip page and Container 360 — what is actually wrong

**Person 1 · 21 September 2026.** Looked at before redesigning, because the last rebuild fixed
something that was not the problem. Measured in a browser at 1440×900, not recalled.

**Nothing has been built. This stops at the proposal.**

---

## Part 1 — What I found by looking

### The trip page

| | |
|---|---|
| Page height | 1509px — **1.7 screens** |
| Before the trip's own identity | **253px** of logo and navigation |
| Navigational items before any content | **18** — a 7-step tracker *and* an 11-step ladder |
| Distinct identifier formats on screen | 5 |

**There are two progress bars, and they disagree.** Across the top: *Trip set up · Vehicle &
driver · Pre-trip checks · Dispatch · On the road · Delivered · Paid & closed* — seven. Below it, a
numbered ladder of **eleven**: crew, checks, dispatch, road, exceptions, paperwork, billing, paid,
advances, costs, close.

They are the same journey at two granularities with two vocabularies. The tracker says *Delivered*;
the ladder calls the same thing *On the road*. A reader who tries to line them up cannot, and
trying is the first thing anyone does with two progress bars.

**Two of the eleven rows say nothing.** Every step summarises its outcome — *"5 of 5 confirmed"*,
*"Dispatched 19 Sept, 04:27 pm"*, *"Settled"*. Except:

```
 5. exceptions   “WHAT HAS GONE WRONG”
 9. advances     “ADVANCES”
10. costs        “TRIP COSTS”
```

Those three repeat their own title where the others give an answer. So a third of the ladder is
furniture.

**The header is two ids, one of them unexplained:** `TRP-2026-000035 · from TO-2026-000038`. The
second is a transport order number, and nothing says so.

### Container 360

| | |
|---|---|
| Page height | 2113px — **2.3 screens** |
| Where the timeline starts | **1245px — 59% down, below the fold** |
| Repeated category labels in the timeline | **23** |
| Filter chips above it | 7 |
| Distinct identifier formats | 5 |

**The eye lands on a chain of five identifiers.** Customer → `TO-2026-000038` → `CNM-2026-000035`
→ `sgoe-771040-2` → `TRP-2026-000035`, each with an *Open* link. It is the most visually prominent
block on the page and it is structure, not story.

**"Where is my container?" is answered well and early** — at 329px: *"On trip TRP-2026-000035,
which is finished and closed."* That sentence is the best thing on either screen.

**"What happened to it?" is answered last.** The timeline is at 1245px, behind the chain, the
readiness card and the paperwork/money cards. So the two halves of the one question a person came
with are separated by a screen and a half.

**The timeline shouts its filing system.** Twenty-three rows each carry a category label —
OPERATIONS, MONEY, INCIDENTS, PAPERWORK, COMPLIANCE, COMMERCIAL — so the same six words repeat 23
times down the page. Above them sit seven filter chips naming those same six categories. **The
categorisation is stated twice and the events themselves come third.**

**And it sends you away.** *"Open TRP-2026-000035 to work on these →"* — the page you just arrived
at tells you to leave it.

### Every phrase that only makes sense if you built it

1. **"matched as SGOE7710402"** — matched against what, and why is it shown?
2. **"from TO-2026-000038"** — an order number with no noun
3. **`TO-` / `CNM-` / `TRP-` / `sgoe-`** — four prefixes, no legend
4. **"1 collection(s)"** — a developer's plural
5. **"Handed to Accounts"** then **"Settled"** then **"Closed"** — three finishes, no stated order
6. **"released"** — my own word, added today, and it needs a sentence
7. **"5 of 5 confirmed"** — five of five what
8. **"Billable"**, **"Collection pending"** — state names from the machine
9. **"ADVANCES" / "TRIP COSTS"** as summaries — the reader must open them to learn whether they matter

**Nine sentences I would have to say out loud.** That is nine design failures written down.

### The honest diagnosis

Last time I fixed *"too much on one screen"* by collapsing it into steps. The screen is now tidy
and still unreadable, which means the problem was never density.

**The problem is that both screens are organised the way the data is stored, not the way the
question is asked.** The trip page is a list of the system's stages; Container 360 is a tour of the
entity graph. Both are complete, and neither leads with the answer. A person arrives asking one of
two things — *what do I do now* or *what happened* — and gets a filing cabinet with the drawers
labelled correctly.

---

## Part 2 — What I propose

Three changes, in order of how much they would help. Each stands alone.

### 1. One progress bar, not two — and it is the ladder that goes

Delete the seven-step tracker. Keep the numbered ladder and let its step summaries carry the
state. Two representations of one journey cannot both be authoritative, and the tracker is the one
that adds no detail.

*Why the tracker and not the ladder:* the ladder is where the work happens — it opens, it holds
the buttons, it says what was done and when. The tracker is decoration that has to be kept in sync
with it, and today it is not.

### 2. Container 360 leads with the story, not the graph

Move the timeline above the chain. Order the page:

```
what it is  →  where it is now  →  what happened to it  →  what it is attached to
```

The chain becomes a compact reference block lower down, not the headline. And inside the timeline,
**drop the per-row category label** — the filter chips already name the categories, and printing
the same six words 23 times buries the sentences a reader came for. Colour or a small icon can
carry the category without a word.

### 3. Say the nine things out loud, on the screen

Every phrase in the list above gets a plain-English replacement or a one-line explanation:

- *"matched as SGOE7710402"* → shown only when it differs from what was typed, worded as
  *"also written SGOE7710402"*
- *"from TO-2026-000038"* → *"Order TO-2026-000038"*
- *"1 collection(s)"* → *"1 payment recorded"*
- *"ADVANCES"* / *"TRIP COSTS"* summaries → the figure, or *"none recorded"*
- the three finishes given an order on screen: *invoiced → paid → closed*

### What I am deliberately not proposing

- **Not another rebuild.** The last one moved everything and fixed the wrong thing. These three are
  removals and reorderings, each testable on its own.
- **Not hiding the identifiers.** They are how people search; CTD §4 makes them the entry point.
  They need labels, not concealment.
- **Not touching P3's panel internals.** The layout is ours; their contents are theirs.

### How we would know it worked

Not a checklist. **A person who has not seen the screen before is asked two questions — *what
should happen to this trip next* and *what has happened to this container* — and answers both
without being told anything.** If they ask what a word means, that word is on the list above and it
is not done.

---

## What I would want from the owner before building

1. **Is the ladder the one to keep, or the tracker?** I argue for the ladder above, but the owner
   uses these screens and I do not.
2. **Which question does Container 360 exist to answer first** — *where is it now*, or *what
   happened to it*? I have assumed the second is what "360" means, and ordered the page around it.
   If the answer is the first, the proposal changes.
3. **Is the trip page for the dispatcher doing the work, or the manager checking on it?** It is
   currently trying to be both, which is part of why it has eleven rows and a tracker.

Nothing built. Waiting.
