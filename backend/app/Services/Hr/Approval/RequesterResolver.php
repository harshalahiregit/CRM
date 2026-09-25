<?php

namespace App\Services\Hr\Approval;

use App\Models\Hr\HrApprovalRequest;
use App\Models\Hr\HrEmployee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Whose request this is.
 *
 * Deciding that somebody may not approve their own request needs an answer to
 * "whose is it", and hr_approval_requests does not carry one: it has an
 * employee_id for the SUBJECT and no requester column at all. So the answer is
 * read from the domain record the request was opened for, which is where each
 * process already records it.
 *
 * TWO IDENTITIES, AND BOTH COUNT:
 *
 *   created_by       the user who submitted the record. Seven of the nine
 *                    processes carry it. An HR executive who raises a loan on
 *                    somebody's behalf is the person who asked for it, and
 *                    letting them approve it is the thing being prevented.
 *
 *   the employee     the person the request is about, via hr_employees.user_id.
 *                    Reimbursements carry no created_by — the table has
 *                    employee_id and decided_by and nothing else — so without
 *                    this the one process where an employee claims their own
 *                    money back would be unguarded.
 *
 * Neither is inferred from the actor. The actor is compared AGAINST this set;
 * it never contributes to it, because a guard that derived "the requester"
 * from whoever happens to be asking would always find them equal.
 *
 * A process that exposes neither identity yields an empty set, and an empty
 * set blocks nobody. That is the honest failure: refusing everybody on a
 * process whose ownership cannot be established would break approvals to
 * protect against something not known to be true. PAYROLL_RUN is the case in
 * point — it covers everybody, so it has no employee, but it does carry
 * created_by, which is exactly the person who should not sign off their own
 * run.
 */
class RequesterResolver
{
    /** created_by is not universal, so the column is checked before it is read. */
    private const SUBMITTER_COLUMN = 'created_by';

    /** Per-table memo: Schema::hasColumn() is a round trip, and this is asked per decision. */
    private static array $hasSubmitter = [];

    /**
     * Every user id that counts as the owner of this request.
     *
     * @return array<int, int> may be empty
     */
    public function userIdsFor(HrApprovalRequest $request): array
    {
        $ids = [];

        foreach ([$this->submitterId($request), $this->employeeUserId($request)] as $id) {
            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /* ── internals ────────────────────────────────────────────────────── */

    /**
     * The user who submitted the domain record.
     *
     * Loaded through the request's own morph relation rather than by
     * instantiating the class named in ApprovalProcess, so a subject whose row
     * has since been deleted resolves to null instead of throwing inside an
     * authorisation check.
     */
    private function submitterId(HrApprovalRequest $request): ?int
    {
        $subject = $request->subject;

        if (! $subject instanceof Model || ! $this->carriesSubmitter($subject)) {
            return null;
        }

        $id = $subject->getAttribute(self::SUBMITTER_COLUMN);

        return $id ? (int) $id : null;
    }

    private function carriesSubmitter(Model $subject): bool
    {
        $table = $subject->getTable();

        return self::$hasSubmitter[$table] ??= Schema::hasColumn($table, self::SUBMITTER_COLUMN);
    }

    /**
     * The account belonging to the employee the request is about.
     *
     * Tenant-scoped even though employee_id came off the request: an id read
     * from one row and looked up without its tenant is how a cross-workspace
     * match happens without anybody noticing.
     */
    private function employeeUserId(HrApprovalRequest $request): ?int
    {
        if (! $request->employee_id) {
            return null;
        }

        $userId = HrEmployee::where('tenant_id', $request->tenant_id)
            ->whereKey($request->employee_id)
            ->value('user_id');

        return $userId ? (int) $userId : null;
    }

    /** Tests switch tenants and tables inside one process. */
    public static function flush(): void
    {
        self::$hasSubmitter = [];
    }
}
