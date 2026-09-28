# Driver app — trip-action endpoints Dispatch (Dev 1) needs to build

> **Status (2026-09-28):** Raza (Ops, SNG-TRN-026) accepted these and is writing
> the **authoritative** contract covering all FIVE driver endpoints — the two
> existing (my-trips + trip progress) plus the three below — with exact
> request/response shapes, to send before he codes. **This file is provisional;
> match the app to his doc when it arrives.** Two open points he'll spell out:
> (a) where the pre-trip inspection record lives, and (b) M12 waits on the
> milestone-list owner (sign-off item 21). **Fleet (me) owns the "hold vehicle on
> critical defect" side** of pre-trip — wire it once his `/pretrip` returns the
> defect signal.

The Sangoé Driver app now has the UI for three trip-journey actions. They POST
to the endpoints below, which are **Dispatch's (Ops) to build** — they write to
the trip / milestone / exception machine, which Fleet does not own. Until they
exist the app catches the 404/405 and tells the driver "not switched on yet"
(nothing breaks), so you can build these on your own schedule.

All are on the existing trip and must be **driver-scoped**: the signed-in driver
may act only on their own active trip (same auth the app already uses — bearer
token). Base: `/api/transport/trips/{trip}`.

---

## 1. Pre-trip inspection (F2) — `POST /pretrip`

The digital pre-trip checklist. A critical defect must log an exception and
block vehicle release (BRS: "reporting a critical defect blocks release").

Request (JSON):
```json
{
  "checks": [
    { "key": "brakes",   "label": "Brakes",   "ok": true,  "note": null,        "critical": true },
    { "key": "tyres",    "label": "Tyres",    "ok": false, "note": "front left", "critical": true },
    { "key": "lights",   "label": "Lights & indicators", "ok": true, "note": null, "critical": false }
    // keys: brakes, tyres, coupling, lights, genset, fluids, documents
  ],
  "has_critical_defect": true
}
```
Expected: 201. On `has_critical_defect: true` → log a Fleet/Ops exception and
hold release. Response body can be your standard `{status,message,data}`.

## 2. Incident / exception (F7) — `POST /incidents`

Fast reporting of a problem, with a photo. Should notify the Control Tower +
Fleet Manager (BRS "one-tap escalation").

Request (**multipart/form-data**):
- `type` — one of `breakdown | accident | delay | deviation | document | other`
- `description` — text (required)
- `photo` — image file (optional; jpg/png)
- *(GPS: the app can add `lat`/`lng` later if you want them — say the word.)*

Expected: 201.

## 3. Handover feedback (F11 / M12) — `POST /handover-feedback`

The 10-second prompt at final handover. **Lock after submit** (audit integrity).

Request (JSON):
```json
{ "rating": "issue", "category": "Temperature", "note": "reefer warm on arrival" }
```
- `rating` — `good | okay | issue`
- `category` — one of `Delay | Damage | Temperature | Documentation | Customer`
  (null when rating is `good`)
- `note` — text or null

Expected: 201; a second submit for the same trip → 409/422 (locked).

---

## Coming next (not built in the app yet — flagging the direction)

- **Assignment accept/reject (F1):** `POST /accept` (records a timestamp),
  `POST /reject` `{reason_code}`.
- **Milestones M01–M12:** `POST /milestone` `{code, at, meta}` once the M-list
  owner is decided (item 21 on the sign-off list).

Tell me your preferred shapes and I'll match the app to them — the app side is a
thin change, the screens are already built.

— Shivam (Fleet, Person 2)
