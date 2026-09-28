# CLOSED — no action needed. "Drivers and Vehicles cannot be added"

> ## ✅ CLOSED 2026-09-17 — COULD NOT REPRODUCE, AND THE OWNER CONFIRMS IT WORKS
>
> **Shivam — there is no bug here. Please do not spend time on this.**
>
> The owner has since confirmed that adding a vehicle and a driver works on local.
> I could not reproduce the reported failure either, at any layer: the API accepts
> each form's exact payload, and neither form over-validates.
>
> Nothing in your module was changed, and nothing is being asked of you.
>
> The detail below is kept only as the record of what was checked, in case the
> symptom ever comes back. **The one genuine observation in it — the
> duplicate-registration error being keyed to `registration_normalized_probe`,
> a field that does not exist on the form — is a small polish item, not a bug,
> and entirely your call whether to touch it.**

---

## Original report (kept for the record — CLOSED, no action)

**From:** Person 1 · **Date:** 2026-09-16 · **Owner-reported, not reproduced as described**
**Your files. I have changed none of them.**

## The report

The owner reports that Drivers and Vehicles cannot be added at all — the forms show many validation
errors. It was passed to me with a diagnosis attached: that the frontend forms mark fields required
which the API does not.

**I could not reproduce that, and the evidence says the diagnosis is wrong.** Rather than write it up
as fact and send you after something that is not there, here is what I actually found.

## What I verified

**1. The API requires almost nothing, as expected.**

```
StoreTransportVehicleRequest  'registration_number' => ['required', 'string', 'max:32']
StoreTransportDriverRequest   'name'                => ['required', 'string', 'max:120']
```

Everything else is `nullable`.

**2. The forms do not over-validate.** `validateVehicle()` checks only `registration_number` (plus
range checks on year and capacity); `validateDriver()` checks only `name`. The `required` prop on
`<Field>` renders a red asterisk and nothing else — there is no HTML `required` attribute and no
per-field gate.

**3. The exact payload each form sends is ACCEPTED.** I posted `emptyVehicle()` and `emptyDriver()`
verbatim — every optional field an empty string, which is the obvious suspect, since a `nullable|
integer` rule rejects `""` when `ConvertEmptyStringsToNull` is not active:

```
>>> VEHICLE status=201
>>> DRIVER  status=201
```

Both created. The middleware is active and the empty strings become nulls. So that theory is dead
too.

## What I did find — and it is worth your time

**The duplicate-registration error is keyed to a field that does not exist on the form.**

```
POST /api/transport/vehicles  {"registration_number": "mh-12-ab-4455"}   // already exists
422
{"registration_normalized_probe": ["A vehicle with that registration number already exists in this workspace."]}
```

`registration_normalized_probe` is a synthetic key used to hang the uniqueness rule on. The
`attributes()` override makes the *message* read correctly, but the **error key** is not
`registration_number`, so any field-level error display has nothing to bind it to. Depending on how
the page renders `errors`, that surfaces as an error against an unknown field — or as a failure with
no visible cause next to the input the user must actually fix.

This is a strong candidate for the report, because it only appears **after** the first successful
save: the owner adds a vehicle, adds it again (or adds one whose registration normalises the same),
and gets a refusal that does not point at anything. `MH 12 AB 4455` is present in the dev database
and is the example registration in the form's own placeholder text, which makes hitting it likely.

Same shape applies to the driver licence uniqueness rule — worth checking while you are there.

**Suggested fix, entirely yours to accept or reject:** key the uniqueness error to
`registration_number` so it lands on the field, or map the synthetic key in the form's error handler.

## The other possibility I could not rule out

A portal identity gets a clean 403, not a validation error:

```
role=client  ->  403  "Unauthorized. Required role: admin or staff"
```

If the owner was signed in as anything other than admin/staff, that is what they would have hit, and
it would not look like a validation problem at all.

## What I needed — ANSWERED, and the report is closed

I asked for the exact error text or a screenshot. **The answer came back as "it works now":** the
owner confirms adding a vehicle and a driver succeeds on local. Combined with my failure to
reproduce it at any layer, there is nothing to fix and nothing outstanding against you.

Closed 2026-09-17. No open request stands against your module.

## Scope

I have changed nothing in `VehicleForm.jsx`, `DriverForm.jsx`, `MasterFormFields.jsx`,
`TransportVehicles.jsx`, `TransportDrivers.jsx`, `StoreTransportVehicleRequest`,
`StoreTransportDriverRequest`, or either Fleet service. This document is the whole of my action.

Separately, and authorised by the owner, a demo seeder now **creates** two vehicles and two drivers
through your services — see `TransportDemoSeeder` and the note in
`docs/transport/NOTE-person2-test-fixtures.md`. It writes rows; it changes no Fleet code.
