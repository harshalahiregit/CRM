# Redesign — the trip page and Container 360

**Person 1 · 21 September 2026 · proposal only. Nothing built.**

Built on three rulings: **the trip page is the dispatcher's working screen**, **the tracker is the
only thing that states position**, and **Container 360 leads with what happened**.

Three removals and two reorderings. No rebuild. Each item stands alone and can be reverted alone.

---

## First — the finding you asked me not to decide silently

**The tracker and the stages can be made to agree. The conflict is naming, not structure.**

I expected to find two competing models. I did not. The tracker is internally correct: its
`delivery` step is labelled *"Delivered"*, blurbed *"The load has arrived and proof of delivery is
on file"*, is **current** while the trip is `delivered`, and only goes **done** at `pod_verified`.
That is right — at `delivered` you *are* at the delivery stage, waiting for the POD.

The collision is one drawer. The ladder's fourth drawer is **titled "On the road"** and its closed
line reads **"Delivered 19 Sept, 04:27 pm"** — so a drawer named after tracker pip 5 displays the
outcome of tracker pip 6. Read together they say *"On the road: Delivered"*, and a reader cannot
tell which stage that is.

The drawer spans two positions because it holds two actions: *record departure* and *record
delivery*. So the fix is either to split it, or to rename it so it stops colliding with a pip.

**I propose splitting it.** One drawer per action is the rule everywhere else on the ladder, and
"On the road" is the only drawer that breaks it. Splitting also removes the last place where a
drawer implies position.

---

## The trip page

### 1 · The tracker is the only map, and one pip is renamed

Keep all seven pips. Change nothing about their logic. **Rename `delivery` from "Delivered" to
"Proof of delivery"** — the blurb already says that is what it means, and the label is the only
part that does not.

*Why:* with the drawer split below, "Delivered" would otherwise appear as both a pip and a drawer
outcome. The pip is about evidence; the drawer is about the event. Naming them differently is what
lets them coexist.

### 2 · The stages become drawers, and three of them leave the main column

Eleven drawers is not a ladder, it is a list. Applying the ruling — *does a dispatcher need this
to act right now?* — three do not:

| Drawer | Verdict |
|---|---|
| **Advances** | Money. Not an action in the journey. **Move to the side column.** |
| **Trip costs** | Money. Same. **Move to the side column.** |
| **Exceptions** | Stays — but see below. It is the one thing that can stop a dispatcher, so it is not a drawer at all. |

That takes the main column from eleven to **eight**, each mapping to exactly one tracker pip:

```
1 Vehicle & driver   2 Pre-trip checks   3 Dispatch   4 Departure
5 Delivery           6 Paperwork         7 Billing & payment   8 Close
```

*(4 and 5 are the split of today's "On the road". 7 merges today's `billing` and `paid`, which are
one concern — invoiced then paid — and are read together.)*

### 3 · Exceptions stop being a drawer

An open critical exception **blocks the close**. We proved that last week when a real close was
refused. A blocker that can stop the work must not be a closed drawer a dispatcher has to think to
open.

**Proposal:** when there are no open exceptions, show nothing at all. When there are, show a single
line directly under the next action, in the warning colour, naming the exception and linking to it.
It appears because something is wrong, and disappears when it is not.

*This is a removal, not an addition:* the drawer goes, and one conditional line replaces it.

### 4 · Every closed drawer carries an outcome, or says there is none

The rule: **a closed drawer's line answers "is this done, and what happened"** — never repeats its
own title. Today three do (`exceptions`, `advances`, `costs`); with the first moved out and the
other two in the side column, the rule applies cleanly to the remaining eight.

Where there is genuinely nothing: *"No advances taken"*, not `ADVANCES`.

### What the first screen becomes

Today 253px of chrome, then identity, then a tracker, then eleven drawers and a parallel column.
Proposed order, unchanged chrome:

```
TRP-2026-000035 · Acme Corporation · Mundra → Pune          (one line, labelled)
NEXT: <the one action>                                       (unchanged — this works)
[ ! one exception line, only if there is one ]
tracker — seven pips, YOU ARE HERE
eight drawers
```

The side column keeps reference (customer, order, price, passport link) and gains advances and
costs.

---

## Container 360

### 5 · The header answers "where is it" in one line, and gains the crew

CTD §5's own search example is identity and status first, and short. Today the top is
`CONTAINER 360` / number / `Closed` / `matched as SGOE7710402 · 20ft Standard` / a sentence.

**Proposed:**

```
sgoe-771040-2 · 20ft Standard
Closed — finished on trip TRP-2026-000035
Acme Corporation · MH 14 DEMO 02 · Suresh Patil
```

The vehicle and driver move up here, per the ruling. *"matched as"* goes (see below).

### 6 · The timeline comes second, and stops repeating itself

Move it from **1245px to directly under the header**. It is what the screen is for.

And delete the per-row category label. Seven chips above already name the six categories; printing
those same six words **23 times** down the page buries the sentences underneath them. Category is
carried by the chip filter and, if it needs to be visible per row, by a colour or a small icon —
not a word.

*This is the single biggest improvement available and it is entirely deletion.*

### 7 · The chain becomes navigation, not the headline

Customer → order → consignment → container → trip is a five-row block of identifiers occupying the
most prominent position on the page. **Nobody opens a passport to read five ids.**

Proposal: a compact strip below the timeline — one line, labelled, links inline:
*Order TO-2026-000038 · Consignment CNM-2026-000035 · Trip TRP-2026-000035*

Readiness and paperwork/money cards follow. *"Open TRP-… to work on these →"* stays, at the bottom,
where leaving is the last option rather than the first suggestion.

---

## The nine phrases

Every one goes, including mine.

| Today | Proposed |
|---|---|
| `matched as SGOE7710402` | **Delete.** Shown only when it differs from what was typed, as *"also written SGOE7710402"* |
| `from TO-2026-000038` | `Order TO-2026-000038` |
| `TO-` / `CNM-` / `TRP-` / `sgoe-` unlabelled | Each prefixed with its noun — *Order*, *Consignment*, *Trip*, *Container* |
| `1 collection(s)` | `1 payment recorded` |
| `Handed to Accounts` / `Settled` / `Closed` | Given an order on screen: *invoiced → paid → closed* |
| **`released`** *(mine, added today)* | *"Vehicle and driver freed for other trips"* |
| `5 of 5 confirmed` | `5 of 5 checks confirmed` |
| `Billable` / `Collection pending` | `Ready to invoice` / `Waiting for payment` |
| `ADVANCES` / `TRIP COSTS` as summaries | The figure, or *"none recorded"* |

**One of these is a signal rather than a wording problem.** `Billable` and `Collection pending` are
state-machine names that leak onto the screen in several places. They already have plain
equivalents in the tracker's blurbs. If plain words cannot be found for a state in a particular
place, that is the concept being wrong for that screen — I will raise it rather than invent a
label.

---

## What I am not proposing

- **Not another rebuild.** Five of the seven items are deletions or moves.
- **Not touching P3's panel internals.** Layout is ours; their contents are theirs.
- **Not hiding identifiers.** CTD §4 makes them the entry point. They need nouns, not concealment.
- **Not a manager view.** Per the ruling, if something is there only because a manager might want
  it, it moves or goes. That is what sends advances and costs to the side column.

## Order I would do it in

1. Delete the timeline's repeated category labels *(pure deletion, biggest single gain)*
2. Move the timeline above the chain; compact the chain
3. Rename the `delivery` pip; split the "On the road" drawer
4. Move advances and costs out; make exceptions a conditional line
5. The nine phrases

Each is independently revertible and independently testable.

## The acceptance test

Not a checklist. **A person who has not seen these screens is asked two questions — *what should
happen to this trip next*, and *what has happened to this container* — and answers both unaided.**
If they ask what a word means, that word is in the table above and the work is not done.

**Nothing built. Waiting for the owner.**
