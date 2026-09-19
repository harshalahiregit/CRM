# Transport OS — where the project stands

**For sign-off under MS-001 §7.** Written for someone who has not opened the code.
**19 September 2026.** Five minutes to read.

---

## In one paragraph

The spine of the product works end to end. A container number finds the box; the box opens onto
its whole commercial chain; the trip runs from draft through allocation, pre-trip checks, dispatch,
transit and delivery to billing, collection and closure; and every step of it lands on one timeline
that cannot be edited. **Ten of the fourteen steps in the demonstration script work by clicking
today.** What is missing falls into three groups: work that is scheduled and small, work that is
waiting on someone specific, and three things nobody has specified yet — which is the only group
that needs a decision from the business rather than from the team.

---

## 1 · Done, and settled

These are built, tested, and have been walked in a browser rather than assumed.

| | |
|---|---|
| **One search box, any identifier** | Container, trip, order, consignment, customer reference, vehicle, driver — and LR/delivery-order numbers. Exact matches only, deliberately: a half-built fuzzy search that sometimes returns the wrong truck is worse than one that returns nothing. |
| **Container 360** | Every search path lands on the same page: customer, order, consignment, container, trip, vehicle, driver, paperwork, money, and the full history. |
| **The trip lifecycle** | Draft → viability → approved → allocated → pre-trip → dispatched → in transit → delivered → POD verified → billable → billed → collection → closed. All of it reachable by clicking, as of 19 September. |
| **Allocation with eligibility** | A vehicle or driver that fails its checks cannot be put on a trip, and the refusal names the failing check. |
| **Pre-trip checklist** | Nothing leaves the yard until the checks are confirmed. |
| **Exceptions** | Raise → acknowledge → resolve, with an SLA clock. Raising is open to whoever is nearest the problem; resolving is owned. |
| **Closure controls** | A trip cannot be closed silently while proof of delivery, billing, collection or an unresolved critical exception is outstanding. Each control says why it passed or failed. |
| **The timeline** | Every event on one filterable history. Entries cannot be edited or deleted — a correction adds an entry rather than changing one. |
| **Separation between workspaces** | One customer's records are invisible to another, and a request for someone else's record answers "not found" rather than "not allowed" — which would itself confirm the record exists. |

---

## 2 · Deferred on purpose, with the reason

Each of these was specified, understood, and consciously left out. None is an oversight.

**An owner cannot yet override a failed closure check.**
The rule book allows an Owner to waive a blocked closure. It is specified, including who may do
it, and it is not built. The screen says so in those words rather than pretending the option does
not exist. *[BR-P0-017]*

**A trip can be approved that would lose money.**
The margin check that should sit in front of approval depends on a rate card that has no owner and
no specification. Rather than invent a margin rule, approval proceeds and the screen states
plainly that commercial viability has not been checked. *[D-63, D-64]*

**Supplier and driver settlement is not built.**
The closure screen therefore reports that this particular check could not run, and says why. It is
the only closure control that cannot be checked. *[SNG-TRN-017]*

**Allocation shows reasons, not a score.**
The system explains why a vehicle is eligible. Ranking candidates numerically is a separate piece
of work with its own rules. *[MS-001 §14 step 4]*

---

## 3 · Waiting on someone, and how small each one is

This is the short list, and it is genuinely short.

| What is missing | Whose | How big | What it blocks |
|---|---|---|---|
| **A button for Accounts to mark a bill invoiced** | Documents & Billing | **One button and one call.** The route behind it already works. | Nothing in the data — the step can be shown on a completed trip. It means a bill cannot be marked invoiced by clicking. *[D-106]* |
| **Connecting a tracker to a specific trip** | Fleet & Telemetry | **One join, once a shared vehicle identifier is agreed.** | GPS, temperature and generator events reaching the trip screen. This is the only reason the demonstration cannot show live tracking. *[D-116]* |
| **Recording a payment on the timeline** | Documents & Billing | One line of code. | Nothing. The payment is recorded correctly; it just does not appear as a timeline entry. |
| **The management control-room view** | Documents & Billing | A screen. Specified, not started. | A single view of everything needing attention. Today that information is on each trip. |
| **Geofencing — port and gate events** | Fleet & Telemetry | Depends on hardware being in place. | Automatic port entry/exit and gate events on the timeline. |

**One of these needs a decision about order, not effort.** The tracker-to-trip connection and a
planned migration of vehicle records interact: doing the migration first would make a wrong
connection hard to detect afterwards, because it would look correct on screen. The migration is
therefore on hold until the connection is fixed — a deliberate sequencing choice, not a delay.
*[D-116, D-100]*

---

## 4 · Not specified by anyone — these need a business decision

**This is the section that needs you.** Everything above is a matter of time. These three are
waiting on somebody to say what the rule is, and no amount of development effort resolves them.

**The rate card.**
There is no definition of what a trip should cost or what margin is acceptable. Until there is,
trips cannot be checked for commercial viability before approval, and a trip that loses money can
be approved. This is the largest open item in the project and it has **no assigned owner.**
*[SNG-TRN-005, D-63]*

**Customer feedback after delivery.**
The demonstration script asks for it and no document defines what is asked, when, of whom, or what
happens to the answer. Nothing is built, and nothing should be until that exists. *[TM-001 §8]*

**The temperature marker.**
Refrigerated cargo has an acceptable range, and no document says what it is or what should happen
when a load goes outside it. Temperature readings are captured; whether a given reading is a
problem is currently nobody's stated rule. *[CTD §17]*

---

## 5 · How we know this is true

Three things are worth knowing about how the statements above were arrived at, because they are
the reason to trust them.

**Every "works" claim was clicked, not inferred.** The fourteen demonstration steps were walked in
a real browser. Three of the most serious problems found this month were invisible in the code and
only appeared when someone used the screen — including a time-zone fault that silently stored
appointments several hours out.

**A screen being populated does not prove the code works.** One timeline looked complete on every
existing trip while the code that should have been recording four of its events was recording
nothing — the entries were there only because an earlier data migration had reconstructed them. A
trip created the following week would have had four silent holes in it. It was found by asking
where each line on the screen came from.

**Gaps are recorded rather than worked around.** There is a standing rule against inventing a
business rule to fill a hole: where a document does not say what should happen, the team raises it
instead of guessing. The three items in section 4 are that rule working as intended — they are
uncomfortable precisely because nobody quietly filled them in.

---

## The one-line summary

**The product works end to end and can be demonstrated. Two people each owe one small piece. One
business decision — the rate card — is larger than everything else on this page put together, and
it has nobody's name against it.**

---

*Detail for anyone who wants it: `docs/transport/registry-defects.md` holds all **83 recorded
items** with full reasoning, `DEMO-ms001-s14-walk.md` the step-by-step walk, and
`DEMO-presenter-script.md` the script for the demonstration itself.*

*(The reference numbers run as high as D-116, which is not a count. They are banded by who raised
them — one range per developer — so the highest number is always well above the number of entries.
83 is the count.)*
