<?php

namespace App\Services\Transport;

use App\Models\Transport\TransportPolicy;
use App\Models\User;
use App\Support\Transport\DriverComplianceStatus;
use App\Support\Transport\PretripCheckKey;
use App\Support\Transport\TransportDocumentType;
use Illuminate\Support\Facades\Log;

/**
 * Transport control policies — DB-020, SNG-TRN-009 step 5.
 *
 * DEFAULTS IS THE CONTRACT. It declares every key a tenant may set and the value
 * a tenant who has never touched Settings sees. Reading an unset key returns its
 * default, so the module behaves identically before and after anyone configures
 * it, and nothing has to guess what "unset" means. Same discipline as
 * PurchaseSettingService.
 *
 * ── THE MOST IMPORTANT DEFAULT IS THE EMPTY ONE ───────────────────────────
 * vehicle.required_documents and driver.required_documents default to [].
 *
 * That is not an oversight and it is not laziness — it is CMP §10, the NO
 * ASSUMPTION PRINCIPLE, verbatim:
 *
 *   "A requirement must not automatically be marked Applicable unless configured
 *    or established through an approved rule. Similarly: Not Applicable must
 *    have a basis."
 *
 * FLEET §11 says the same from the other direction: "The system must not assume
 * every vehicle requires exactly the same document set." An Indian transporter
 * running national permits needs a different set from one running intra-state,
 * and shipping a guessed list would block legitimate vehicles on day one for
 * documents nobody asked for.
 *
 * The consequence, stated plainly: out of the box, a compliance check verifies
 * that nothing ON FILE has lapsed. It becomes "everything required is present"
 * the moment a tenant configures its required set. The mechanism closes the gap;
 * the default cannot, without violating §10.
 *
 * ── WHY THE `required` FLAGS ARE POLICY ───────────────────────────────────
 * CMP §20: "Certain requirements may block vehicle; driver; trip; facility;
 * customer service; dispatch. The blocking rule must be configurable." So each
 * eligibility check carries a policy-controlled required flag, and moving a check
 * from advisory to blocking is a settings row, not a deploy.
 */
class TransportPolicyService
{
    /** key => default. Grouped by the subject that owns it. */
    public const DEFAULTS = [
        // ── Compliance thresholds ────────────────────────────────────────
        // The one value present in BOTH CMP §18 (90/60/30/15/7) and FLEET §13
        // (60/30/15/7). "Exact configuration belongs to the organization."
        'compliance.expiring_window_days' => DriverComplianceStatus::DEFAULT_EXPIRING_WINDOW_DAYS,

        // ── Required document sets (CMP §24, FLEET §11) ──────────────────
        // EMPTY BY DEFAULT — CMP §10. See the class docblock.
        // Values are ENUM-006 document types; the service validates them.
        'vehicle.required_documents' => [],
        'driver.required_documents'  => [],

        // ── Which checks BLOCK versus merely warn (CMP §20) ──────────────
        // All P0 checks default to blocking, because every one of them traces to
        // a rule the FRS rates Hard/Critical: BR-P0-003 (overlap) and BR-P0-004
        // (unavailable or expired document). A tenant may relax one; none may be
        // relaxed silently, since the verdict records the flag it used.
        'vehicle.check.status.required'     => true,
        'vehicle.check.assignment.required' => true,
        'vehicle.check.documents.required'  => true,
        'vehicle.check.capacity.required'   => true,

        'driver.check.lifecycle.required'    => true,
        'driver.check.availability.required' => true,
        'driver.check.assignment.required'   => true,
        'driver.check.licence.required'      => true,
        'driver.check.documents.required'    => true,

        /* ══ SNG-TRN-010 · pre-trip checks ══════════════════════════════
         *
         * S6-004 is the acceptance line these keys exist to satisfy, and it is
         * worth quoting because it names both halves: "Mandatory checks
         * CONFIGURABLE; failed items block dispatch WHEN POLICY SAYS SO."
         * BRW-047 says the same from the rule side, CMP §20 from the compliance
         * side ("the blocking rule must be configurable").
         */

        // WHICH CHECKS APPLY. Defaults to every check that has data behind it.
        //
        // Validated against PretripCheckKey::GENERATED rather than ::ALL, and
        // that restriction is the point: eighteen of the twenty-three declared
        // keys read a column no migration creates. Letting a tenant enable
        // `vehicle.tyres` would generate a row nothing can ever evaluate, so the
        // trip would sit at IN_PROGRESS forever with no way to explain why.
        // Rejecting it with a real reason is kinder than accepting it.
        'pretrip.checks.enabled' => PretripCheckKey::GENERATED,

        // WHICH CHECKS APPLY TO WHICH VEHICLES / SERVICES.
        //
        // FRS TRP-P0-005: "Mandatory checklist BY VEHICLE/SERVICE TYPE."
        // BRW-053 gives the concrete case: the genset pre-check is "Required for
        // reefer transport" — not for a flatbed.
        //
        // Shape: check_key => [type, ...]. A key that is absent, or maps to an
        // empty list, applies to EVERY trip; that is why the default is empty
        // rather than a guessed matrix. CMP §10's NO ASSUMPTION PRINCIPLE, the
        // same reasoning that keeps required_documents empty above.
        //
        // Matching is case-insensitive against transport_vehicles.vehicle_type
        // and transport_orders.service_type, both of which are free text — no
        // document defines a controlled vocabulary for either (recorded in
        // AllocationScope::EXCLUDED), so the tenant's own spelling is the
        // vocabulary.
        'pretrip.checks.vehicle_types' => [],
        'pretrip.checks.service_types' => [],

        // WHICH FAILURES BLOCK. BRW-052, and the reason PretripResult has both
        // FAIL and CRITICAL_FAIL:
        //   "Critical failure: Dispatch blocked.
        //    Non-critical warning: Continue with warning/approval by policy."
        //
        // All five default to critical, because each traces to a rule the
        // package rates Hard or Critical:
        //   order_approved     BRW-047 "order approval"; SM-TRP's own entry gate.
        //   driver.assigned    BRW-046 — a trip with no crew cannot depart.
        //   vehicle.assigned   BRW-046, same.
        //   driver documents   BR-P0-004, QA-003, CMP §182 (Expiry → Dispatch Block).
        //   vehicle compliance BR-P0-004, QA-003, RTM CMP-006 ("Dispatch control").
        //
        // A tenant may relax any of them; none is relaxed silently, because each
        // row stores the flag it was generated under (see the migration).
        'pretrip.check.commercial.order_approved.critical' => true,
        'pretrip.check.driver.assigned.critical'           => true,
        'pretrip.check.driver.documents_valid.critical'    => true,
        'pretrip.check.vehicle.assigned.critical'          => true,
        'pretrip.check.vehicle.compliance_valid.critical'  => true,
    ];

    /** Read one policy for a tenant, falling back to its declared default. */
    public function get(int $tenantId, string $key): mixed
    {
        if (! array_key_exists($key, self::DEFAULTS)) {
            // An undeclared key is a bug in the caller, not a reason to return
            // null and let a check silently become advisory.
            throw new \InvalidArgumentException("Unknown transport policy key: {$key}");
        }

        $row = TransportPolicy::forTenant($tenantId)->where('key', $key)->first();

        return $row ? $row->value : self::DEFAULTS[$key];
    }

    public function bool(int $tenantId, string $key): bool
    {
        return (bool) $this->get($tenantId, $key);
    }

    public function int(int $tenantId, string $key): int
    {
        return (int) $this->get($tenantId, $key);
    }

    /** @return string[] */
    public function list(int $tenantId, string $key): array
    {
        $value = $this->get($tenantId, $key);

        return is_array($value) ? array_values($value) : [];
    }

    /**
     * Every policy for a tenant, defaults merged with stored overrides.
     *
     * One query, not one per key — a candidate listing evaluates many vehicles
     * and must not issue a policy read per check per row.
     *
     * @return array<string,mixed>
     */
    public function all(int $tenantId): array
    {
        $stored = TransportPolicy::forTenant($tenantId)
            ->pluck('value', 'key')
            ->all();

        return array_merge(self::DEFAULTS, array_intersect_key($stored, self::DEFAULTS));
    }

    /** @param array<string,mixed> $values */
    public function setMany(int $tenantId, array $values, ?User $actor = null): void
    {
        foreach ($values as $key => $value) {
            $this->set($tenantId, $key, $value, $actor);
        }
    }

    public function set(int $tenantId, string $key, mixed $value, ?User $actor = null): TransportPolicy
    {
        if (! array_key_exists($key, self::DEFAULTS)) {
            throw new \InvalidArgumentException("Unknown transport policy key: {$key}");
        }

        $value = $this->validate($key, $value);

        $policy = TransportPolicy::updateOrCreate(
            ['tenant_id' => $tenantId, 'key' => $key],
            ['value' => $value, 'updated_by' => $actor?->id],
        );

        Log::channel('transport')->info('Transport policy changed', [
            'key' => $key, 'tenant_id' => $tenantId, 'user_id' => $actor?->id,
        ]);

        return $policy;
    }

    /** Restore a key to its declared default by removing the override. */
    public function reset(int $tenantId, string $key): void
    {
        TransportPolicy::forTenant($tenantId)->where('key', $key)->delete();
    }

    /**
     * Keep a policy value inside what the folder actually permits.
     *
     * A required-document list may only contain ENUM-006 values, and only ones
     * applicable to that entity kind — a tenant cannot require an `invoice` on a
     * driver. Silently accepting it would produce a compliance check that can
     * never pass.
     */
    private function validate(string $key, mixed $value): mixed
    {
        if ($key === 'compliance.expiring_window_days') {
            $days = (int) $value;
            if ($days < 0 || $days > 365) {
                throw new \InvalidArgumentException('The expiry warning window must be between 0 and 365 days.');
            }

            return $days;
        }

        if (str_ends_with($key, 'required_documents')) {
            $value = is_array($value) ? array_values(array_unique($value)) : [];
            $entity = str_starts_with($key, 'driver') ? 'driver' : 'vehicle';
            $allowed = TransportDocumentType::forEntity($entity);

            foreach ($value as $type) {
                if (! in_array($type, $allowed, true)) {
                    throw new \InvalidArgumentException(
                        TransportDocumentType::label((string) $type)." cannot be required of a {$entity}."
                    );
                }
            }

            return $value;
        }

        if (str_ends_with($key, '.required') || str_ends_with($key, '.critical')) {
            return (bool) $value;
        }

        if ($key === 'pretrip.checks.enabled') {
            $value = is_array($value) ? array_values(array_unique($value)) : [];

            foreach ($value as $checkKey) {
                $this->assertCheckIsGeneratable((string) $checkKey);
            }

            return $value;
        }

        if ($key === 'pretrip.checks.vehicle_types' || $key === 'pretrip.checks.service_types') {
            if (! is_array($value)) {
                return [];
            }

            $clean = [];
            foreach ($value as $checkKey => $types) {
                $this->assertCheckIsGeneratable((string) $checkKey);

                // Free text on both sides, so the only normalisation that is
                // safe is trimming and casing — never a controlled vocabulary
                // this module would be inventing.
                $clean[(string) $checkKey] = array_values(array_unique(array_filter(
                    array_map(fn ($t) => mb_strtolower(trim((string) $t)), (array) $types),
                    fn (string $t) => $t !== '',
                )));
            }

            return $clean;
        }

        return $value;
    }

    /**
     * A tenant may only configure a check the system can actually evaluate.
     *
     * Eighteen of the twenty-three declared keys read something no column
     * stores. Accepting one here would produce a checklist row that can never
     * leave `pending`, stranding the trip at IN_PROGRESS with nothing on screen
     * to explain it. BRWM §70's tone rule applies to configuration errors too:
     * say what is wrong and what to do about it.
     */
    private function assertCheckIsGeneratable(string $checkKey): void
    {
        if (PretripCheckKey::isGenerated($checkKey)) {
            return;
        }

        if (PretripCheckKey::isValid($checkKey)) {
            throw new \InvalidArgumentException(
                PretripCheckKey::label($checkKey).' is declared but cannot be evaluated yet — '
                .'nothing in Transport records it. Remove it from the pre-trip configuration.'
            );
        }

        throw new \InvalidArgumentException("Unknown pre-trip check: {$checkKey}");
    }

    /**
     * FRS TRP-P0-005 — "Mandatory checklist by vehicle/service type."
     *
     * A check applies unless the tenant has narrowed it to a set of types that
     * this trip's vehicle or service is not in. An unset or empty list means
     * "every trip", so a tenant who has never opened Settings gets the full
     * checklist — the same DEFAULTS-is-the-contract behaviour as everything else
     * in this class.
     *
     * A trip with NO vehicle yet cannot match a vehicle-type restriction. It is
     * treated as still applicable rather than skipped, because the alternative
     * silently drops checks from exactly the trips that are least ready — a
     * crewless trip would generate a shorter checklist than a crewed one.
     *
     * @param  array<string,mixed>  $policy  a pre-loaded all() result
     */
    public function pretripCheckApplies(
        array $policy,
        string $checkKey,
        ?string $vehicleType = null,
        ?string $serviceType = null,
    ): bool {
        if (! in_array($checkKey, (array) ($policy['pretrip.checks.enabled'] ?? []), true)) {
            return false;
        }

        return $this->matchesTypeRestriction((array) ($policy['pretrip.checks.vehicle_types'] ?? []), $checkKey, $vehicleType)
            && $this->matchesTypeRestriction((array) ($policy['pretrip.checks.service_types'] ?? []), $checkKey, $serviceType);
    }

    /** @param array<string,array<int,string>> $restrictions */
    private function matchesTypeRestriction(array $restrictions, string $checkKey, ?string $actual): bool
    {
        $allowed = $restrictions[$checkKey] ?? [];

        if ($allowed === []) {
            return true;   // unrestricted
        }

        if ($actual === null || trim($actual) === '') {
            return true;   // see the docblock — never shorten a crewless checklist
        }

        return in_array(mb_strtolower(trim($actual)), $allowed, true);
    }

    /** BRW-052 — is a failure of this check a block, or only a warning? */
    public function pretripCheckIsCritical(array $policy, string $checkKey): bool
    {
        return (bool) ($policy['pretrip.check.'.$checkKey.'.critical'] ?? false);
    }
}
