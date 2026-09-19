# STOS — Open questions for the owner

**From:** Person 2 (Fleet, Asset & Telemetry) · **Date:** 2026-09-19
**Why this exists:** each question below is a place where building on a guess would cost real
rework. They are written as prose so they can be pasted into a document assistant and answered in
the same form. Answers get folded into `STOS-PROCESS-FLOW-AND-OWNERSHIP.md`.

---

## 1. Where does a document's expiry date actually live?

Right now the same fact exists in two places. Fleet holds five statutory expiry dates directly on
the vehicle — registration, insurance, fitness, permit and PUC — and derives the vehicle's
compliance verdict from them, which is what stops a non-compliant truck being dispatched. The
document engine separately stores the uploaded certificate with its own `valid_until` date. So a
truck's insurance expiry is recorded twice, in two modules, and nothing keeps them in step.

This is exactly the duplicate-master-data problem the single-owner rule exists to prevent, and it
needs one answer. **Is Fleet's date the master, with the uploaded document being evidence
attached alongside it? Or is the document the master, with Fleet reading the latest valid
document of each type and holding no dates of its own? Or are Fleet's dates a cache kept in step
by an event whenever a document is filed or renewed?**

What hinges on it: if the document is the master, then uploading a renewed certificate
automatically clears the dispatch gate, which is the behaviour an operator would expect — but
Fleet can no longer gate dispatch if the document engine is unavailable, and it is a migration.
If Fleet's date is the master, nothing I have built changes, but a renewal uploaded by the
compliance desk will not move the gate until somebody also edits the date by hand, and that gap
is where a truck goes out on lapsed insurance.

---

## 2. What actually moves a vehicle from ALLOCATED to IN_TRANSIT?

The ruled state machine separates a truck that has been assigned to a trip (ALLOCATED) from one
that has actually departed (IN_TRANSIT). That distinction is useful — an allocated truck can
still be swapped for another, a departed one is a recovery problem — but only one half of it is
currently wired. Operations assigning a truck moves it to ALLOCATED. Nothing yet moves it to
IN_TRANSIT.

**Which event should fire that transition: Operations pressing dispatch, the driver confirming
departure in the field, or Fleet inferring it from telemetry when the vehicle actually leaves the
pickup location under power?**

What hinges on it: if dispatch fires it, "in transit" really means "somebody pressed a button",
and a truck can read as travelling while still sitting in the yard. If the driver app fires it,
it is closest to physical truth and matches the driver-mobile ownership of the in-transit stage,
but a driver who forgets leaves a moving truck showing as ALLOCATED. If telemetry infers it, no
human can forget — but Fleet would be writing an operational state from an inference, and a yard
shunt could be reported as a departure.

---

## 3. Which system holds driver identity?

The ruling says driver identity and personhood belong to Sangoe HR / CRM, and that Fleet holds
only an operational overlay — licence number, licence class, licence expiry and duty availability
— with no name, phone or address duplicated anywhere. Fleet is built that way and that part is
settled.

What is not settled is which table is actually the directory. Fleet currently reads people from
the CRM's worker and contact tables — third-party vendor workers, purchase workers, vendor
contacts and client contacts. It does not read an HR employee master. That was deliberate: the
original requirement was that forty drivers added under a Customer or Vendor in the CRM should
appear in Transport automatically, and those are the tables that hold them.

**Is the HR employee master the correct source instead, or in addition? And if both, which one
wins when the same person appears in both?**

What hinges on it: a fleet that runs its own trucks with employed drivers and also hires attached
vehicles with contractor drivers needs both, and needs a rule for precedence. If the answer is
"HR only", the existing customer/vendor driver requirement stops working and that needs to be an
explicit trade rather than a regression.

---

## 4. Is a trailer a vehicle, or its own asset?

The ruling lists trailers alongside vehicles, gensets and tyres as Fleet assets with Fleet as the
single entry point. Today a trailer would simply be a vehicle record with its type set to
trailer, which means it gets its own registration number, its own documents and compliance dates,
and its own workshop job cards for free.

What that model cannot express is coupling. Nothing records which tractor is pulling which
trailer, so a load cannot be allocated a tractor-and-trailer pair, and the cost of a journey
cannot be split across the two units that performed it.

**Should a trailer stay a vehicle record, or become its own master with a coupling to a tractor —
the way a genset is fitted to a vehicle? Or is this out of scope before 30 September?**

---

## 5. What are lifecycle stages 10 to 13?

The ruling describes thirteen distinct stages and supplies nine, ending at collections and
settlement. Stages ten through thirteen are not recorded anywhere, and I have deliberately left
them blank rather than infer them, because inventing four plausible stages is precisely the
failure this documentation exists to prevent.

**What are the remaining four stages, who owns the screen for each, and which modules only read?**

What hinges on it: anything that sits after settlement — period close, profitability reporting,
reconciliation, archival, customer feedback — may well read Fleet data, and if it does then Fleet
owes it a contract that nobody has specified yet.

---

## 6. Does Transport still need to run standalone?

An earlier requirement was that Transport must work both as a module inside the Sangoe CRM and as
a standalone tenant application, pulling drivers from the CRM directory when it is present and
from its own register when it is not. Fleet is built that way: there is one adapter with two
implementations chosen automatically depending on whether the CRM is present.

That requirement predates the ruling that Transport is one system connected to HR and CRM. If
Transport now always runs inside the CRM, the standalone adapter is dead weight that still has to
be maintained and tested. **Is standalone operation still a requirement, or has it been
superseded?**

---

## 7. Two smaller vocabulary questions

The uppercase ruling covered the vehicle asset state machine. Three vocabularies were not
mentioned and are still lowercase: genset status, tyre status and workshop job-card status. They
are currently internal to Fleet, so nothing breaks either way, but consistency is cheaper to fix
now than later. **Should they be aligned to uppercase too?**

Separately, when Fleet tells Operations why a vehicle or driver cannot be used, the reason codes
are lowercase (`on_another_trip`, `broken_down`, `other_jobs_open`) while the advisory flags on
the same response are uppercase (`DRIVER_LICENSE_EXPIRED`, `SERVICE_OVERDUE`). Both cross the
boundary into Operations' dispatch board. **Should both be uppercase?**

---

## 8. One thing I would like confirmed rather than assumed

The ruling retires Dev 1's vehicle and driver CRUD endpoints outright. Those endpoints currently
carry four things Fleet does not yet have: vehicle document upload and renewal, driver document
upload and renewal, asset status transitions, and status counts for the grid.

My plan is to build those four into Fleet first, and only then hand Person 1 a precise list of
what is safe to delete — so that nothing working is lost in the gap. **Please confirm that
sequence is right, and that Fleet absorbing document upload means Fleet writing into the shared
document store owned by Person 3, rather than Fleet creating a document table of its own.**
