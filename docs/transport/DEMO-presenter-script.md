# The 30 September demonstration — presenter's script

**For the person presenting, working alone.** No debugging required, nothing to read beforehand.
Follow it top to bottom. Every click path below was walked in a browser on 19 September 2026 and
the words in *italics* are what actually appears on screen.

**A clean run takes 12–15 minutes**, plus 5 minutes of setup.

---

## Before you start

### 1. Start the two servers

Two terminals, left running for the whole demonstration:

```bash
cd ~/Desktop/sangoe_crm/backend   && php artisan serve
cd ~/Desktop/sangoe_crm/frontend  && npm run dev
```

Wait for the second one to print a `localhost:5173` line before opening the browser.

### 2. Open and sign in

| | |
|---|---|
| **Address** | `http://127.0.0.1:5173/app/transport` |
| **Email** | `admin@mlacrm.com` |
| **Password** | `Admin@12345` |

If the screen is empty or the numbers look wrong, reseed and start again — it takes about a minute:

```bash
cd ~/Desktop/sangoe_crm/backend && php artisan db:seed --class=TransportDemoSeeder
```

### 3. The two things to have written down

| | |
|---|---|
| **The container** | `sgoe-402215-9` — a 40ft reefer, a complete journey from port to delivery, paid and closed |
| **Its trip** | `TRP-2026-000034` — Acme Corporation, JNPT → Bhiwandi |

Everything in the script hangs off that one container, which is what MS-001 §14 asks for: *"one
realistic container/consignment"*.

### 4. One optional setup step, worth 2 minutes — read this

**Step 3 shows the LR and delivery order, and the demo data has neither.** The search and the
screen both handle them; there is simply no paperwork filed on this consignment. You have two
choices and both are fine:

- **Skip it.** Say *"the LR and delivery order are filed against the consignment and searchable by
  number — there is none on this demo record"* and move on.
- **File one first, and get a better demo.** Consignments → open `CNM-2026-000034` → **File
  document** → type **Lorry Receipt**, number `LR-2026-000034` → File document. Repeat with
  **Delivery Order** and `DO-2026-000034`. Step 3 then shows both, and you can search for
  `LR-2026-000034` in step 1 and land on the same consignment — which is the more impressive
  version of the point.

---

## The fourteen steps

### 1 · Search by container number

**Click:** press **⌘K** (Ctrl+K on Windows) anywhere in the app. Type `sgoe-402215-9`.

**You see:** a result headed *Container*, with *matched as SGOE4022159* underneath it.

**Say:** *"One box searches everything. I can type the container number however it appears on the
paperwork — with dashes, without, upper or lower case — and it finds the same box."*

**If it does not work:** the palette also opens from the **Search…** box in the header. If the
result list is empty, check the number for a typo; it is the only exact-match field here.

---

### 2 · Open Container 360

**Click:** press Enter on the result. *(Or: Containers in the left menu → find the row → **Open
360**.)*

**You see:** a page headed **CONTAINER 360**, the container number, a **Closed** badge, and
*On trip TRP-2026-000034, which is finished and closed.*

**Say:** *"This is the container's whole life on one page. Whatever anyone searched for — the
container, the trip, the order, the vehicle — they all arrive here."*

**If it does not work:** the address is `/app/transport/containers/23` if you need to type it.

---

### 3 · Customer, order, LR/DO and trip

**Click:** nothing — it is on the Container 360 page, under **WHERE THIS CONTAINER SITS**.

**You see:** a chain, top to bottom — *Acme Corporation* → *TO-2026-000037 · Container Haulage* →
*CNM-2026-000034* → *sgoe-402215-9 · you are here* → *TRP-2026-000034 · Closed · JNPT → Bhiwandi*.

**Say:** *"Customer, order, consignment, container, trip — the commercial chain, in one place, and
every line is a link."*

**If the LR and DO lines are missing:** they are, unless you did the optional setup above. Say
*"the LR and delivery order file against the consignment and are searchable by number; this demo
record has none filed"* and move on. Do not click anything looking for them.

---

### 4 · Recommended vehicle and driver, with the reason

**Click:** still on Container 360 — **VEHICLE** and **DRIVER** in the same chain. For the full
version: open the trip (step 7) and expand **1 · Who is driving**.

**You see:** *MH 12 DEMO 01 · Trailer 40ft* and *SANGOE DEMO Ramesh Kumar · HMV*. In the trip's
allocation panel, each candidate carries the checks it passed.

**Say:** *"Allocation is not a free-text box. A vehicle and a driver have to pass their eligibility
checks before they can be put on a trip, and the screen shows which ones."*

**Expect one gap:** there is **no numeric suitability score** — the system shows the reasons a
vehicle is eligible, not a ranking. If someone asks, that is honest and planned, not missing.

---

### 5 · Compliance and dispatch eligibility

**Click:** on Container 360, the **READY TO LEAVE?** card.

**You see:** *Ready* and *5 of 5 checks confirmed*.

**Say:** *"Nothing leaves the yard until the pre-trip checklist is confirmed. The screen will not
let a dispatcher past it, and it names the check that is failing."*

---

### 6 · Document status and handover chain

**Click:** on Container 360, the **PAPERWORK AND MONEY** card.

**You see:** *1 document · 1 verified · POD on file*.

**Say:** *"Proof of delivery is on file and someone has verified it — an uploaded file and a
verified document are deliberately not the same thing."*

---

### 7 · Dispatch and trip progression

**Click:** on Container 360, **Open TRP-2026-000034 →**.

**You see:** the trip page. A **NEXT STEP** banner at the top, then numbered, collapsed steps: *1
Who is driving · 2 Ready to leave · 3 Sending it out · 4 On the road · 5 What has gone wrong · 6
Paperwork · 7 Getting paid · 8 Close the trip*, plus advances and costs.

**Say:** *"One trip, one page, in the order the work actually happens. It always tells you the one
thing to do next — here it says the trip is closed and there is nothing left."*

**If it does not work:** the address is `/app/transport/trips/43`.

---

### 8 · GPS, temperature and generator — **this one will not work**

**Do not click anything looking for it.**

**Say:** *"Live tracking, temperature and generator state come from the vehicle hardware. The
telemetry side is built and feeding the system; the last connection between a tracker and a
specific trip is being finished this week, so there is nothing to show on this screen today."*

That is the whole truth and it is enough. **Do not open the Fleet screens hoping for a chart** —
there is no trip-side or container-side telemetry display, and clicking around for one is what a
demonstration should never look like.

---

### 9 · Exceptions

**Click:** on the trip page, expand **5 · What has gone wrong**.

**You see:** on this trip, a resolved exception. On `TRP-2026-000035` there is a fuller example:
*EXC-2026-000002 · Low · Resource · Resolved*, with the original complaint and the resolution note
both kept.

**Say:** *"Anything that goes wrong is raised against the trip by whoever is nearest to it, then
owned, then resolved. Raising is deliberately wider than resolving — the driver can report a
problem, closing it is someone's job."*

**Want to show it live?** This is the best live moment in the demonstration and it takes 40
seconds. Open trip `TRP-2026-000035` → **5 · What has gone wrong** → **Raise an exception** →
pick a type, type a sentence → **Raise it**. It appears as *Open*. Click **Acknowledge & own it**,
then **Resolve**, type what was done → the counters go to *Open 0*. Walked and verified.

---

### 10 · Delivery, feedback and POD

**Click:** on the trip page, expand **6 · Paperwork**.

**You see:** the POD on file and verified, and the delivery recorded.

**Say:** *"Delivery is recorded against the trip, the POD is filed, and someone has to verify it
before the trip can be billed."*

**Expect one gap: customer feedback is not built.** If asked, say *"customer feedback after
delivery is specified and is scheduled work — it is not in this build."* Do not go looking for it.

---

### 11 · Billing ready, or the exact blocker

**Click:** on the trip page, expand **7 · Getting paid**.

**You see:** *INVOICED ₹68,500.00 · RECEIVED ₹68,500.00 · OUTSTANDING ₹0.00 · STATUS Settled*.

**Say:** *"When a trip is not ready to bill, this panel names the exact reason rather than just
refusing. Here everything has been invoiced and collected."*

---

### 12 · Invoice linkage

**Click:** same panel — the invoice reference beside the billed amount.

**You see:** the trip showing as billed, with the invoice linked, and the receipt recorded against
it.

**Say:** *"The trip carries its invoice, so a container number answers 'has this been billed, and
has it been paid' without anybody opening the accounts system."*

**Important — do not try to bill a different trip live.** Marking a bill as invoiced is done by
Accounts and **the button for it is not in this build yet**. On this closed trip everything is
already linked and shows correctly. Starting a fresh trip and trying to reach *Billed* by clicking
will stop at *Billable* and leave you stuck in front of an audience.

---

### 13 · Control room / management exception view — **this one will not work**

**Do not look for it in the menu.**

**Say:** *"The management control-room view — the single screen of everything currently needing
attention — is specified and is not in this build. Today that information is on each trip."*

---

### 14 · The complete timeline

**Click:** back to Container 360 (browser back twice, or search the container again) →
**EVERYTHING THAT HAS HAPPENED** at the bottom.

**You see:** the full history, newest first, with filter tabs — *Everything · Commercial ·
Compliance · Money · Operations · Incidents* — and each entry in plain English with its date and
time.

**Say:** *"Every event, from the order being created to the trip being closed, on one timeline you
can filter. This is the audit trail — nothing here can be edited or deleted, only corrected with a
new entry."*

**Finish on this screen.** It is the strongest one.

---

## The honest list — read before you present

**Six things will visibly not work or fall short.** Each has one sentence above; they are collected
here so nothing surprises you. Two of the six (steps 3 and 12) are only partly gaps — step 3 works
if you do the optional setup, and step 12 shows correctly on this trip and simply cannot be
performed live on another.

| Step | What happens | Your one sentence |
|---|---|---|
| **3 · LR / DO** | The lines are absent unless you file them in setup | *"The LR and delivery order file against the consignment and are searchable by number; none is filed on this demo record."* |
| **4 · Allocation score** | Reasons shown, no numeric ranking | *"The system shows why a vehicle is eligible rather than scoring it — ranking is planned."* |
| **8 · GPS / temperature** | Nothing on screen at all | *"Telemetry is built and the last link to a specific trip is being finished this week."* |
| **10 · Feedback** | Not present | *"Customer feedback after delivery is specified and scheduled, not in this build."* |
| **12 · Marking invoiced** | Works on this trip; cannot be done live on another | *"Accounts marks a bill invoiced; that screen is being added."* |
| **13 · Control room** | Not in the menu | *"The management control-room view is specified and not in this build."* |

**The rule for all six: say the sentence, then move on.** A screen going quiet while somebody
clicks again does more damage than a gap named confidently.

---

## If something goes wrong mid-demonstration

| Symptom | What to do |
|---|---|
| A page is blank or spinning | Refresh once. If still blank, go back to Container 360 and continue from there — every step after 2 is reachable from it. |
| *"Validation failed"* or a red message | Read it out. The refusals are written in English on purpose and usually name the missing thing. Do not retry the same click. |
| You are logged out | Sign in again with the details above; you return to the same place. |
| A number looks wrong | Do not fix it live. Note it and carry on. |
| Something is genuinely broken | Say *"that is one for the team"*, move to the next step. Steps 1, 2, 7, 9, 11 and 14 are the strong ones — end on 14. |

---

## If you only have five minutes

**1 → 2 → 7 → 14.** Search a container number, open Container 360, open the trip, show the
timeline. That is the whole argument: one identifier, one page, one history.
