# Note to Person 2 — I changed eight of your test files. No assertion or behaviour was altered.

**From:** Person 1 · **Date:** 2026-09-16 · **Authorised by:** the owner, in writing, on D-54
**Commit:** see `test(transport): make fixture identifiers unique by construction (D-54)`

You will see your files in a diff you did not make. This note is so you learn it here rather than
there.

## What was wrong

The Transport suite was intermittently red. It failed roughly **once in five full runs** with no
code change, always surfacing inside `TransportVehicleService::create()` — **your file** — while the
actual cause was in test fixtures.

Fifteen test files built vehicles as:

```php
'registration_number' => 'MH12AB'.random_int(1000, 9999),   // 9,000 possible values
```

against `UNIQUE(tenant_id, registration_normalized)`. Two draws of the same number inside one test
method violate it. A full Transport run makes **585 such draws** (measured, not estimated).

Driver licences had the same shape with a wider range (`random_int(100000, 999999)`), and one
licence fixture used a 9,000-value range like the vehicle ones.

## What changed — one line per identifier, nothing else

```diff
- 'registration_number' => 'MH12AB'.random_int(1000, 9999),
+ 'registration_number' => 'MH12AB'.self::uniqueSeq(4),
```

`uniqueSeq()` is a new static helper on `tests/TestCase.php`: a monotonic counter that cannot repeat
for the life of the process. It was chosen over widening the range because a wider range makes
collision *rarer*, not impossible — and a rare failure is worse than a frequent one, because it gets
re-run until it agrees instead of fixed.

**Every prefix was preserved exactly**, including the two that exist on purpose:

| File | Kept as | Why it matters |
|---|---|---|
| `TransportMasterApiTest` | `'MH 12 AB '` (spaces) | it exercises registration normalisation |
| `TransportMasterApiTest` | `'RJ14 '` (trailing space) | same, for licences |
| `TransportMasterAllocationAuditTest`, `TransportAllocationRefusalAuditTest` | `Str::upper(Str::random(2))` | left untouched; uniqueness now comes from the counter |

`$width` keeps the generated value the same length as the random draw it replaced — 4 digits for a
registration, 6 for a licence — so no test sees an identifier of a different shape than before.

## Your eight files

`TransportMasterApiTest` · `TransportMasterDataTest` · `TransportMasterAuditTest` ·
`TransportMasterAllocationAuditTest` · `TransportAllocationTest` · `TransportAllocationApiTest` ·
`TransportAllocationRefusalAuditTest` · `TransportEligibilityTest`

The other seven are mine (dispatch, pre-trip, trip assignment).

## What was NOT touched — please verify this yourself

- **No assertion changed.** Every test that asserts a literal registration or licence
  (`'MH12AB4455'`, `'MH 12 AB 4455'`, `'RJ1420110012345'`) passes that value in as an **explicit
  override**, so it never used the random default and none of them were edited.
- **No production file touched.** `TransportVehicleService`, `TransportDriverService`, the models and
  the migrations are all untouched.
- **No logic changed.** The diff is 29 insertions and 29 deletions across 15 files, every one of them
  a fixture line.

## Why it was done across the boundary

Standing rule is that I stay in my own section. The owner ruled otherwise here, in writing, for
three reasons: every ruling in the defect register is backed by "the test passes", so an
intermittently red suite devalues the whole register; git shows a single author across all fifteen
files, so there was no concurrent work to collide with; and fixing seven while leaving eight would
have left two contradictory fixture patterns side by side, which is worse than either.

If you disagree with any of it, the change is one `sed` away from being reverted and I will revert it
on request.

## One thing for whoever owns HR / SangoeTrack — not me, not you

The same defect class exists outside Transport and is **worse**:

```php
tests/Feature/SangoeTrack/SangoeTrackLeaveSyncTest.php:62
  'code' => strtoupper(substr($name, 0, 2)).random_int(10, 99),      // 90 values
tests/Feature/SangoeTrack/SangoeTrackLeaveSyncTest.php:71
  'name' => 'Standard '.random_int(100, 999),                        // 900 values
```

against `unique(['tenant_id', 'code'])` and `unique(['tenant_id', 'name'])` on `hr_leave_types` and
`hr_leave_policies` (`2026_08_03_000000_create_hr_leave_tables.php`, lines 37-38 and 60).

Ninety values is a much smaller space than the 9,000 that made Transport flaky. Not fixed here —
different module, no authorisation, and that suite has 32 failures of its own that pre-date this work
(verified by running it on a clean tree). Recorded so it is not discovered the same way I discovered
ours.
