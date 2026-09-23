<?php

namespace App\Services\Hr\Approval;

use App\Models\Hr\HrEmployee;

/**
 * Does this step apply to this request?
 *
 * A closed vocabulary of comparisons, and nothing else. There is no expression
 * language, no stored PHP, no eval, and no callable anywhere in a condition —
 * an approval ladder is configuration an HR administrator edits, and anything
 * that let them store executable logic would make the Settings screen a remote
 * code execution surface.
 *
 * Every value compared is read HERE, from the database, at evaluation time. The
 * client supplies which request is being decided and nothing else; a department
 * or an amount posted alongside an approval is ignored, because trusting it
 * would let the caller pick which rung applies to them.
 *
 * An unknown key fails closed. A step whose conditions cannot be understood
 * does not quietly become an unconditional step.
 */
class ConditionEvaluator
{
    /** The whole vocabulary. Anything not here is refused. */
    public const KEYS = [
        'department_id', 'branch', 'grade_id',
        'min_amount', 'max_amount',
        'leave_type_id', 'loan_type_id', 'component_id', 'exit_type_id',
    ];

    /**
     * @param  array  $conditions  the step's stored conditions
     * @param  array  $context     authoritative values resolved by contextFor()
     */
    public function matches(?array $conditions, array $context): bool
    {
        if (empty($conditions)) {
            return true;      // an unconditional rung always applies
        }

        foreach ($conditions as $key => $expected) {
            if (! in_array($key, self::KEYS, true)) {
                // Fails closed: a condition nobody can evaluate must not read
                // as "no condition".
                return false;
            }

            if ($expected === null || $expected === '' || $expected === []) {
                continue;     // configured but left blank — not a constraint
            }

            if (! $this->matchesOne($key, $expected, $context)) {
                return false;
            }
        }

        return true;
    }

    private function matchesOne(string $key, $expected, array $context): bool
    {
        if ($key === 'min_amount' || $key === 'max_amount') {
            $amount = $context['amount'] ?? null;

            // A process with no money on it cannot satisfy an amount rule.
            // Leave has no amount; a leave step configured with one simply does
            // not apply, rather than matching everything.
            if ($amount === null) {
                return false;
            }

            return $key === 'min_amount'
                ? (float) $amount >= (float) $expected
                : (float) $amount <= (float) $expected;
        }

        $actual = $context[$key] ?? null;

        if ($actual === null) {
            return false;
        }

        // Everything else is membership. A scalar is treated as a list of one
        // so the stored shape can be either.
        $list = is_array($expected) ? $expected : [$expected];

        foreach ($list as $candidate) {
            if ((string) $candidate === (string) $actual) {
                return true;
            }
        }

        return false;
    }

    /**
     * The authoritative facts about a request, read from the database.
     *
     * The employee is re-read rather than taken from the subject's loaded
     * relation, so a stale eager load cannot decide which approver applies.
     */
    public function contextFor(int $tenantId, ?int $employeeId, $subject, ?float $amount): array
    {
        $context = ['amount' => $amount];

        if ($employeeId) {
            $employee = HrEmployee::where('tenant_id', $tenantId)
                ->where('id', $employeeId)
                ->first(['id', 'department_id', 'branch', 'grade_id']);

            if ($employee) {
                $context['department_id'] = $employee->department_id;
                $context['branch']        = $employee->branch;
                $context['grade_id']      = $employee->grade_id;
            }
        }

        /*
         | Process-specific facts, read off the subject itself.
         |
         | Driven by the vocabulary rather than by a branch per process: any key
         | in KEYS that names a column on the subject is read from it. That is
         | what stops this method growing an `if` every time a process is
         | migrated, and it keeps the closed vocabulary as the single place a
         | new condition has to be declared.
         |
         | The employee facts above are NOT overwritten — those are read from
         | hr_employees on purpose, so a stale denormalised column on a subject
         | cannot decide which approver applies.
         */
        if ($subject) {
            foreach (self::KEYS as $key) {
                if ($key === 'min_amount' || $key === 'max_amount' || array_key_exists($key, $context)) {
                    continue;
                }

                $value = $subject->getAttribute($key);
                if ($value !== null) {
                    $context[$key] = $value;
                }
            }
        }

        return $context;
    }

    /** Keep only keys this process understands, discarding the rest on write. */
    public function sanitise($raw, array $allowedKeys): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $clean = [];

        foreach ($raw as $key => $value) {
            if (! in_array($key, self::KEYS, true)) {
                continue;
            }

            // min/max amount are always spellable; the rest must be declared by
            // the process, so a leave step cannot be given a loan's condition.
            $isAmount = $key === 'min_amount' || $key === 'max_amount';
            if (! $isAmount && ! in_array($key, $allowedKeys, true)) {
                continue;
            }

            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $clean[$key] = $isAmount ? (float) $value : array_values((array) $value);
        }

        return $clean;
    }
}
