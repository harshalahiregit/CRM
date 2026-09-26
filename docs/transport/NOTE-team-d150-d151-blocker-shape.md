# Blockers have one shape now, and pre-trip reads Fleet's driver codes

**For Person 2 and Person 3.** From Person 1, 26 September 2026. Short; details are in
`registry-defects.md` under D-150, D-151, D-153 and D-141.

## 1 · Every blocker and warning is `{code, why, owner}` (D-150)

This covers eligibility verdicts (vehicle and driver candidates, the allocation response) **and**
pre-trip readiness (`blockers` / `warnings` on the readiness and revalidation payloads, and on the
Container 360 passport).

```json
{ "code": "fleet", "why": "Licence expired 1 day ago — this driver cannot be dispatched.", "owner": "Fleet compliance desk" }
```

- **Before:** these were plain strings. That's why an ineligible driver showed with an empty reason.
- **`owner`:** the desk that can clear it. It is **`null` for pre-trip checklist items**, because no document names a desk for them.
- **If you read these arrays:** read `why` (and `owner` if you show it), never the item itself. A string
  `implode()` over them now throws "Array to string conversion".

## 2 · Pre-trip reads Fleet's driver reason codes (D-151)

The pre-trip "driver documents" item had been passing every driver since D-134. It now reads Fleet's
codes from `DriverService::eligible()`, and only these five:

| Code | Pre-trip result |
|---|---|
| `driver_license_expired` | block |
| `driver_license_unrecorded` | block |
| `driver_medical_expired` | block |
| `driver_license_expiring` | warning |
| `driver_medical_expiring` | warning |

`driver_unavailable`, `driver_not_onboarded` and `driver_medical_unrecorded` are **not** read. For
example, allocation itself sets a driver `ON_TRIP`.

**Person 2: if you rename one of these codes, pre-trip fails loudly by design.** The driver's check
turns red with *"could not be verified"*, and an error is logged. It will never quietly pass.
Please tell us before renaming, and we'll move with you.

## 3 · Asked of Person 2 — D-141

Please populate `vehicles.registration_normalized` on save. Until then our plate search carries a
workaround (D-153), which we'll remove when you do.

## Also open, for a ruling rather than for code

The D-151 entry lists three things the old Transport check covered that nothing covers now:
- a licence that has not started yet (`licence_valid_from`; Fleet's `licenceVerdict()` ignores it);
- expiry of driver documents other than medical;
- the required-documents policy.
