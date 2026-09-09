<?php

namespace App\Services\Hr;

use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeDetail;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Reads and writes the extended employee record, and carries an onboarding into it.
 *
 * The carry is the reason this is a service rather than a couple of lines in a
 * controller. Onboarding collects a bank account, a UAN and an emergency contact
 * from the person themselves, then the onboarding converts to an employee and
 * all of it was left behind on the onboarding row — so HR re-typed from a
 * document the candidate had already filled in. Same field names on both tables
 * makes that a copy of shared keys; this is the one place that knows it.
 */
class EmployeeDetailService
{
    /** Numbers people write in lower case or with spaces, stored one way. */
    private const UPPERCASED = [
        'pan_number', 'bank_ifsc', 'passport_number', 'driving_licence_number',
    ];

    /** Digits only, so "1234 5678 9012" and "123456789012" are the same number. */
    private const DIGITS_ONLY = ['aadhaar_number'];

    /**
     * NOT NULL columns with a default of false.
     *
     * The form posts EVERY field, so an untouched checkbox arrives as null. The
     * rules accept that (`nullable|boolean`) and the insert then dies on the NOT
     * NULL constraint — a 422 reading "Is international worker is required" on a
     * box nobody had touched, which blocked saving the whole tab. Unanswered
     * means false here, which is what the column default already says.
     */
    private const BOOLEANS = ['is_international_worker', 'has_previous_pf', 'is_disabled'];

    /**
     * Booleans whose column default is TRUE.
     *
     * These must NOT be coerced the way the ones above are. The form posts every
     * field, so an untouched flag arrives as null — and turning null into false
     * here would switch off PF, ESIC, PT, LWF and gratuity for everybody the
     * moment anyone saved the tab. Null means "unchanged": dropped from the
     * write, so a create takes the column default and an update keeps what is
     * there.
     */
    private const BOOLEANS_DEFAULT_TRUE = [
        'pf_applicable', 'eps_applicable', 'esic_applicable',
        'pt_applicable', 'lwf_applicable', 'gratuity_applicable',
    ];

    /**
     * Three-state, and deliberately not a boolean.
     *
     * null  = follow the statutory rule
     * true  = cap this person's PF wages at the ceiling
     * false = contribute on their full salary
     *
     * Casting null to false would take every employee off the ceiling.
     */
    private const TRISTATE = ['restrict_pf_to_ceiling'];

    public function get(HrEmployee $employee): array
    {
        $detail = $employee->detail;

        // A shape, always — a screen that receives null for a person with no
        // detail yet has to special-case every field to render an empty form.
        return collect((new HrEmployeeDetail)->getFillable())
            ->reject(fn ($f) => in_array($f, ['tenant_id', 'employee_id'], true))
            ->mapWithKeys(fn ($f) => [$f => $this->present($detail?->{$f})])
            ->all();
    }

    public function save(HrEmployee $employee, array $data, ?User $actor = null): HrEmployeeDetail
    {
        $data = $this->normalise($data);

        $detail = DB::transaction(fn () => HrEmployeeDetail::updateOrCreate(
            ['employee_id' => $employee->id],
            $data + ['tenant_id' => $employee->tenant_id],
        ));

        $employee->recordAudit('Employee Details Updated', $actor, null, [
            // The values themselves are deliberately NOT written to the audit
            // trail: it would put bank accounts and Aadhaar numbers into a log
            // that is read far more widely than the record they came from.
            'fields' => array_keys($data),
        ]);

        return $detail;
    }

    /**
     * Copy what the person already gave during onboarding onto their employee record.
     *
     * Only fills blanks. Re-running a conversion, or an HR correction made after
     * the fact, must not be overwritten by the older onboarding answer.
     */
    public function carryFromOnboarding(HrEmployee $employee, ?object $profile, ?User $actor = null): ?HrEmployeeDetail
    {
        if (! $profile) {
            return null;
        }

        $shared = collect((new HrEmployeeDetail)->getFillable())
            ->reject(fn ($f) => in_array($f, ['tenant_id', 'employee_id'], true))
            ->filter(fn ($f) => isset($profile->{$f}) && $profile->{$f} !== '' && $profile->{$f} !== null)
            ->mapWithKeys(fn ($f) => [$f => $profile->{$f}])
            ->all();

        if ($shared === []) {
            return null;
        }

        $existing = $employee->detail;

        if ($existing) {
            $shared = collect($shared)
                ->reject(fn ($v, $f) => $existing->{$f} !== null && $existing->{$f} !== '')
                ->all();

            if ($shared === []) {
                return $existing;
            }
        }

        return $this->save($employee, $shared, $actor);
    }

    /** @param array<string, mixed> $data */
    private function normalise(array $data): array
    {
        foreach ($data as $field => $value) {
            if (in_array($field, self::BOOLEANS_DEFAULT_TRUE, true)) {
                if ($value === null) {
                    unset($data[$field]);
                } else {
                    $data[$field] = (bool) $value;
                }

                continue;
            }

            if (in_array($field, self::TRISTATE, true)) {
                $data[$field] = $value === null ? null : (bool) $value;

                continue;
            }

            if (in_array($field, self::BOOLEANS, true)) {
                $data[$field] = (bool) $value;

                continue;
            }

            if ($value === null) {
                continue;
            }

            if (in_array($field, self::UPPERCASED, true)) {
                $data[$field] = strtoupper(trim((string) $value));
            } elseif (in_array($field, self::DIGITS_ONLY, true)) {
                $data[$field] = preg_replace('/\D/', '', (string) $value);
            } elseif (is_string($value)) {
                $data[$field] = trim($value);
            }

            // An emptied field is cleared, not stored as ''. Everything reading
            // these treats null as "not provided" and '' would read as provided.
            if ($data[$field] === '') {
                $data[$field] = null;
            }
        }

        return $data;
    }

    /** Booleans stay booleans; everything else is a string or null, never ''. */
    private function present(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return ($value === '' || $value === null) ? null : $value;
    }
}
