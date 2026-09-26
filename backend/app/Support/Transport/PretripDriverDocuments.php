<?php

namespace App\Support\Transport;

/**
 * The pre-trip "driver documents" item, judged from Fleet's answer — D-151.
 *
 * ── WHY THIS EXISTS ──────────────────────────────────────────────────────
 * The item used to borrow the driver verdict's `licence` and `documents`
 * checks. D-134 collapsed those into one `fleet` check, the borrow matched
 * nothing, and an empty borrow graded PASS: a driver whose licence lapsed
 * after allocation was waved through pre-trip and dispatch alike.
 *
 * It now reads Fleet's REASON CODES, and only the ones the old two checks
 * covered. Ruled by the owner, 2026-09-25 (option b):
 *
 *   FAIL          driver_license_expired, driver_license_unrecorded,
 *                 driver_medical_expired, driver_license_not_yet_valid
 *                 (the last added 2026-09-26, when Fleet closed D-151(i))
 *   PASS_WARNING  driver_license_expiring, driver_medical_expiring
 *
 * NOT borrowed, on purpose: `driver_unavailable` (allocation itself sets the
 * driver ON_TRIP, so borrowing it would fail every allocated driver),
 * `driver_not_onboarded`, `driver_medical_unrecorded`.
 *
 * ── AN EMPTY BORROW CAN NEVER PASS ───────────────────────────────────────
 * The codes are derived by Fleet from its own licence and medical verdicts
 * (`DriverService::blockersFor()` / `warningsFor()`). So the row is checked
 * for those verdicts, and every code a verdict implies must be present. If
 * either is missing, Fleet's answer is not the shape this item reads — the
 * exact failure D-151 was — and the item FAILS with a sentence saying so.
 *
 * Pure: no queries, no logging. The caller logs the schema error.
 */
final class PretripDriverDocuments
{
    public const FAILS = [
        'driver_license_expired', 'driver_license_unrecorded', 'driver_medical_expired',
        'driver_license_not_yet_valid',
    ];

    public const WARNS = ['driver_license_expiring', 'driver_medical_expiring'];

    /**
     * Which code Fleet emits for which state of which verdict — read from
     * DriverService::blockersFor() and warningsFor(). Restricted to the five
     * codes above; states that map to excluded codes are not listed.
     */
    private const IMPLIED = [
        'licence' => [
            'expired'       => 'driver_license_expired',
            'unknown'       => 'driver_license_unrecorded',
            'expiring'      => 'driver_license_expiring',
            'not_yet_valid' => 'driver_license_not_yet_valid',
        ],
        'medical' => [
            'expired'  => 'driver_medical_expired',
            'expiring' => 'driver_medical_expiring',
        ],
    ];

    /**
     * Every state Fleet's licenceVerdict() / medicalVerdict() can return, read
     * from DriverService (dateVerdict() and the not-yet-valid branch).
     *
     * FAIL CLOSED. `not_yet_valid` arrived on 26 Sep and, before this list, a
     * licence in that state matched no code and PASSED. A state this check has
     * never heard of means Fleet has moved on and we have not — so the item
     * fails, names the state, and the caller logs it. It never passes.
     */
    private const KNOWN_STATES = [
        'licence' => ['valid', 'expiring', 'expired', 'unknown', 'not_yet_valid'],
        'medical' => ['valid', 'expiring', 'expired', 'unknown'],
    ];

    /**
     * The desk DriverService::warningsFor() names for both expiring codes.
     * Needed only when Fleet did not compute warnings (see below); whenever it
     * did, judge() checks this against Fleet's own value.
     */
    private const WARNING_OWNER = 'Fleet compliance desk';

    /**
     * @param  array<string,mixed>|null  $row  one driver's row from DriverService::eligible(), or null if absent
     * @return array{0:string,1:string,2:string|null}  [result, detail, schema error or null]
     */
    public static function judge(?array $row, string $subject, bool $isCritical): array
    {
        if ($row === null) {
            return [
                PretripResult::forFailure($isCritical),
                $subject.' — not in the fleet directory, so their licence and medical cannot be verified.',
                null,
            ];
        }

        $unrecognised = self::unrecognisedState($row);
        if ($unrecognised !== null) {
            return [
                PretripResult::forFailure($isCritical),
                $subject.' — licence and medical could not be verified: '.$unrecognised
                .' Treat as not cleared until this check is taught it.',
                $unrecognised,
            ];
        }

        $error = self::schemaError($row);
        if ($error !== null) {
            return [
                PretripResult::forFailure($isCritical),
                $subject.' — licence and medical could not be verified: Fleet\'s answer is not in the shape '
                .'this check reads. Treat as not cleared until it is fixed.',
                $error,
            ];
        }

        $failed = array_values(array_filter(
            $row['blockers'] ?? [],
            fn (array $b) => in_array($b['code'] ?? null, self::FAILS, true),
        ));

        if ($failed !== []) {
            return [PretripResult::forFailure($isCritical), $subject.' — '.self::sentences($failed), null];
        }

        $warnings = self::warnings($row);
        if ($warnings !== []) {
            return [
                PretripResult::PASS_WARNING,
                $subject.' — valid now, but expiring soon: '.self::sentences($warnings)
                .' Renew before this becomes a block.',
                null,
            ];
        }

        return [PretripResult::PASS, $subject.' — licence and medical cleared by Fleet.', null];
    }

    /**
     * Fleet computes `warnings` only for drivers it would still offer. An
     * allocated driver is ON_TRIP, so Fleet excludes them and computes none —
     * which would silence the expiring warning at exactly the moment pre-trip
     * needs it. In that case the warning is read from Fleet's own verdict
     * (`state` + `message`), under the code Fleet would have given it.
     *
     * @param  array<string,mixed>  $row
     * @return array<int,array{code:string,why:string,owner:string|null}>
     */
    private static function warnings(array $row): array
    {
        if (array_key_exists('warnings', $row)) {
            return array_values(array_filter(
                $row['warnings'],
                fn (array $w) => in_array($w['code'] ?? null, self::WARNS, true),
            ));
        }

        $out = [];
        foreach (self::IMPLIED as $verdict => $codes) {
            $code = $codes[$row[$verdict]['state']] ?? null;
            if (in_array($code, self::WARNS, true)) {
                $out[] = ['code' => $code, 'why' => (string) $row[$verdict]['message'], 'owner' => self::WARNING_OWNER];
            }
        }

        return $out;
    }

    /** @param array<string,mixed> $row */
    private static function unrecognisedState(array $row): ?string
    {
        foreach (self::KNOWN_STATES as $verdict => $known) {
            $state = $row[$verdict]['state'] ?? null;
            if ($state !== null && ! in_array($state, $known, true)) {
                return "unrecognised Fleet {$verdict} state '{$state}'.";
            }
        }

        return null;
    }

    /** @param array<string,mixed> $row */
    private static function schemaError(array $row): ?string
    {
        foreach (array_keys(self::IMPLIED) as $verdict) {
            if (! isset($row[$verdict]['state'])) {
                return "Fleet's driver row carries no `{$verdict}.state`; nothing this check reads can match.";
            }
        }

        $blockerCodes = array_column($row['blockers'] ?? [], 'code');
        $warnings = array_key_exists('warnings', $row) ? $row['warnings'] : null;

        foreach (self::IMPLIED as $verdict => $codes) {
            $code = $codes[$row[$verdict]['state']] ?? null;

            if (in_array($code, self::FAILS, true) && ! in_array($code, $blockerCodes, true)) {
                return "Fleet reports {$verdict} `{$row[$verdict]['state']}` but no `{$code}` blocker — its codes have changed.";
            }

            if (in_array($code, self::WARNS, true) && $warnings !== null) {
                $match = collect($warnings)->firstWhere('code', $code);
                if ($match === null) {
                    return "Fleet reports {$verdict} `{$row[$verdict]['state']}` but no `{$code}` warning — its codes have changed.";
                }
                if (($match['owner'] ?? null) !== self::WARNING_OWNER) {
                    return "Fleet names `{$match['owner']}` for `{$code}`; this check assumes `".self::WARNING_OWNER.'`.';
                }
            }
        }

        return null;
    }

    /** @param array<int,array<string,mixed>> $reasons */
    private static function sentences(array $reasons): string
    {
        return implode(' ', array_map(function (array $r) {
            $why = rtrim(trim((string) ($r['why'] ?? '')), '.').'.';

            return ! empty($r['owner']) ? $why.' ('.$r['owner'].')' : $why;
        }, $reasons));
    }
}
