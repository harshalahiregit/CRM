# Coverage — the trip page and Container 360 redesign

**Person 1.** Built 2026-09-21 against `PROPOSAL-trip-page-and-container-360-redesign.md`,
approved the same day. Checked three ways.

**Result up front:** all seven items shipped. The trip page went from **1509px to 1399px** with
eleven drawers reduced to eight; Container 360's timeline moved from **1245px to 472px** and its
**23 repeated category words to zero**. Two defects were found while building — one of them a
regression I had shipped the day before.

---

## 1 · Against the rulings

| Ruling | What shipped |
|---|---|
| The trip page is the **dispatcher's** working screen | Advances and trip costs moved to the side column — money, not actions. Nothing was added for a manager. |
| The **tracker** is the only thing that states position | The `delivery` pip renamed *"Delivered" → "Proof of delivery"*; drawers now carry outcomes only. |
| Same vocabulary in both | The colliding drawer was split (below). No drawer names a tracker pip. |
| Rows repeating their own title | All three gone — exceptions moved out, advances and costs to the side column. |
| Container 360 leads with **what happened** | Timeline directly under the header. Chain demoted to a chip strip. |
| Per-row category labels | **Deleted.** A coloured dot with the name on hover. |
| The nine phrases | All replaced, including `released`, which I had added the day before. |

### The finding, resolved as reported

The tracker and the stages **could** be made to agree. The tracker was internally correct; one
drawer titled *"On the road"* displayed the outcome of the *next* pip, so the page read
*"On the road: Delivered 19 Sept"*.

It spanned two pips because it held two actions. **Split into `Departure` and `Delivery`**, one
action each, which is the rule the other seven follow. `JourneyPanel` gained a `phase` prop so each
drawer shows only its own milestone and only its own action — a reader is never offered *record
departure* under a heading about delivery.

---

## 2 · Against the condition — where a dispatcher raises an exception

**Checked before removing anything, and the answer changed the design.**

`ExceptionsPanel` is rendered in **exactly one place** in the product. `"Raise an exception"` exists
nowhere else. **There is no exception register screen and no route to one.** And:

```
EXCEPTION_CREATE  (5) -> OWNER, OPERATIONS, DISPATCHER, ACCOUNTS, ADMIN
EXCEPTION_MANAGE  (5) -> OWNER, OPERATIONS, ACCOUNTS, APPROVER, ADMIN
```

The **Dispatcher can create and cannot manage** — exactly the split described as
*"whoever is nearest the problem can record it"*. Removing the drawer would have left that role
with no way to report anything, anywhere.

**So the panel was not reduced to a warning line.** It moved **above the tracker, out of a
collapsed drawer**, in a new `compact` mode: open exceptions visible without a click, *Raise an
exception* still present, resolved history folded behind one line (*"2 resolved — show"*).

---

## 3 · Against the tests

`5546 passed`, same nine long-standing failures by name. Frontend builds.

Two new tests pin the regression found while building (below), and the existing six for D-119
still pass — including the three-way break that proved them.

---

## 4 · Against the screen — walked at 1440×900

| | Before | After |
|---|---|---|
| Trip page height | 1509px | **1399px** |
| Drawers in the main column | 11 | **8** |
| Progress indicators | 2, disagreeing | **1** |
| Drawers repeating their own title | 3 | **0** |
| Container 360 height | 2113px | 1921px |
| Timeline starts at | 1245px *(59% down)* | **472px** *(first screen)* |
| Repeated category words | **23** | **0** |

The trip page now reads: identity → *next action* → open problems → tracker → eight drawers, with
money and reference beside them. Container 360 reads: *container, type, customer, vehicle, driver*
→ status sentence → **the whole story** → a chip strip of links → readiness → paperwork and money.

Screenshots: `screenshots/trip-page-21sep.png`, `screenshots/container-360-21sep.png`.

---

## 5 · Two defects found while building

### A regression I shipped the day before — caught by looking at the screen

Container 360's new header line was supposed to carry the vehicle and driver. It rendered without
them, and the cause was **D-119, shipped the previous day**.

`TripAssignmentService::release()` nulls the trip's `vehicle_id` and `driver_id`. That is right for
an **abandoned** allocation — the trip returns to `approved` and must not claim a vehicle it no
longer holds. `releaseOnDelivery()` reused it, so **a finished trip forgot which truck ran it.**

Measured rather than assumed:

- Container 360 lost its vehicle and driver on every delivered trip.
- A plate search answered *"No trip has run on this vehicle yet, so there is nothing to trace"* —
  **about a truck that had just delivered one.** CTD §4's follow-through, silently wrong.
- The repoint dry run fell from **2 rows to move to 0**. It would have run, moved nothing, and
  looked finished.

`release()` gained `clearTripPointers`, false from the delivery path: a finished trip's
`vehicle_id` is history, not a claim, and what the vehicle is doing *now* is carried by its own
status. Two tests pin both directions. The damaged rows were repaired from their own assignments —
including one trip that had been damaged earlier by the same behaviour, before D-119 existed.

### A palette that referenced tokens the project does not define

The category dots used `var(--danger)`, `var(--info)`, `var(--warning)`, `var(--success)`. **None
of those exists** — the project defines `--color-danger-500` and friends. The exception rows
rendered with no dot at all. Caught by reading the screenshot, not the code.

---

## 6 · What I did not do

- **No manager view.** Per the ruling.
- **No new state.** `Departure`/`Delivery` are drawers over existing statuses.
- **Nothing added to P3's panel internals** — only where they sit and whether they are open.
- **No invented label.** Where a state had no plain word, I used the tracker's own blurb wording
  rather than making one up.

## 7 · The acceptance test is still outstanding

It is not a checklist: **a person who has not seen these screens answers *what should happen to
this trip next* and *what has happened to this container* unaided.** That needs a person. The
screenshots are so the owner can look before using it.
