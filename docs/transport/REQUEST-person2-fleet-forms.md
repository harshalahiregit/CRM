# Request to Person 2 — "Drivers and Vehicles cannot be added"

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

## What I need

**The exact error text, or a screenshot.** Everything above is me failing to reproduce a report at
second hand. One screenshot would settle in ten seconds which of these it is — or show a fourth thing
none of us has thought of.

## Scope

I have changed nothing in `VehicleForm.jsx`, `DriverForm.jsx`, `MasterFormFields.jsx`,
`TransportVehicles.jsx`, `TransportDrivers.jsx`, `StoreTransportVehicleRequest`,
`StoreTransportDriverRequest`, or either Fleet service. This document is the whole of my action.

Separately, and authorised by the owner, a demo seeder now **creates** two vehicles and two drivers
through your services — see `TransportDemoSeeder` and the note in
`docs/transport/NOTE-person2-test-fixtures.md`. It writes rows; it changes no Fleet code.
