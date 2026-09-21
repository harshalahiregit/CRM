<?php

namespace App\Support\Hr\Approval;

use App\Models\Hr\HrEmployeeLoan;
use App\Models\Hr\HrEmployeeVariableEarning;
use App\Models\Hr\HrExitRequest;
use App\Models\Hr\HrInvestmentDeclaration;
use App\Models\Hr\HrLeaveApplication;
use App\Models\Hr\HrProbationConfirmation;
use App\Models\Hr\HrReimbursement;

/**
 * The HR processes an approval workflow can be configured for.
 *
 * A closed registry, not a discovered one. Each entry says which model the
 * request hangs off, which conditions make sense for it, and — the part that
 * keeps the engine out of the business — which value to read for an amount
 * condition. Nothing here executes anything.
 *
 * Processes are registered one at a time, each reviewed on its own, so a mistake
 * costs one flow rather than eleven. Whatever is listed below is wired; every
 * other HR approval stays on the old shared gate until it is migrated
 * deliberately. The list is the record — this sentence does not repeat it,
 * because a docblock enumerating the entries goes stale on the next phase.
 */
final class ApprovalProcess
{
    public const LEAVE                   = 'leave';
    public const LOAN                    = 'loan';
    public const VARIABLE_EARNING        = 'variable_earning';
    public const INVESTMENT_DECLARATION  = 'investment_declaration';
    public const REIMBURSEMENT           = 'reimbursement';
    public const EXIT_REQUEST            = 'exit_request';
    public const PROBATION_CONFIRMATION  = 'probation_confirmation';

    /**
     * Everything the engine needs to know about a process, per process.
     *
     * `amount_field` is null for leave: a leave application has no money on it.
     * The amount condition is still offered by the vocabulary because loans and
     * advances will need it, and a process that declares no amount field simply
     * fails any amount condition rather than inventing a number.
     */
    public const DEFINITIONS = [
        self::LEAVE => [
            'label'        => 'Leave',
            'model'        => HrLeaveApplication::class,
            'amount_field' => null,
            'conditions'   => ['department_id', 'branch', 'grade_id', 'leave_type_id'],
        ],

        /*
         | The first process that carries money, so the first where an amount
         | condition means anything.
         |
         | `principal` rather than `total_payable`: the ladder should be decided
         | by what the company is lending, not by what interest turns it into.
         | Two loans of the same principal on different rates would otherwise
         | route differently, which is not a rule anybody asked for.
         */
        self::LOAN => [
            'label'        => 'Loans & advances',
            'model'        => HrEmployeeLoan::class,
            'amount_field' => 'principal',
            'conditions'   => ['department_id', 'branch', 'grade_id', 'loan_type_id'],
        ],

        /*
         | Commissions and incentives.
         |
         | `amount` is the figure being added to somebody's pay, so it is the
         | right thing to route on — a ₹2,000 incentive and a ₹2,00,000 one are
         | not the same decision.
         |
         | component_id is offered as a condition because which COMPONENT an
         | earning is paid against is the closest thing this process has to a
         | type, and companies distinguish a sales commission from a retention
         | bonus that way.
         */
        self::VARIABLE_EARNING => [
            'label'        => 'Variable earnings',
            'model'        => HrEmployeeVariableEarning::class,
            'amount_field' => 'amount',
            'conditions'   => ['department_id', 'branch', 'grade_id', 'component_id'],
        ],

        /*
         | Investment declarations — VERIFICATION, not approval.
         |
         | The domain word is deliberate and kept: a declaration is Verified,
         | never Approved, because somebody has checked the proofs behind a
         | claim rather than granted a request. Only a Verified declaration
         | reduces tax (HrInvestmentDeclaration::countsForTax()).
         |
         | `declared_total` is the amount to route on — what the employee is
         | claiming, which is what decides whether a second pair of eyes is
         | wanted. verified_total cannot be used: it is the OUTPUT of the
         | decision the ladder is about to make, and on a submitted declaration
         | it is still zero.
         */
        self::INVESTMENT_DECLARATION => [
            'label'        => 'Investment declarations',
            'model'        => HrInvestmentDeclaration::class,
            'amount_field' => 'declared_total',
            'conditions'   => ['department_id', 'branch', 'grade_id'],
        ],

        /*
         | Expense claims.
         |
         | `amount_claimed` is what the employee is asking for, and it is what
         | decides whether a second signature is wanted. amount_approved is the
         | OUTPUT of the decision — null until somebody approves — so routing on
         | it would mean the ladder could not be chosen until after it had run.
         */
        self::REIMBURSEMENT => [
            'label'        => 'Expense claims',
            'model'        => HrReimbursement::class,
            'amount_field' => 'amount_claimed',
            'conditions'   => ['department_id', 'branch', 'grade_id'],
        ],

        /*
         | Exit APPROVAL — deciding whether somebody leaves, and nothing after.
         |
         | Exit is three domains, not one, and only this first is wired here.
         | Clearance (handing back the laptop, item by item) and settlement (the
         | final figure) each have their own lifecycle, their own queue and — in
         | the settlement's case — its own review/approve/settle chain. They are
         | separate processes and stay on the old gate.
         |
         | No amount. An exit request carries no money; the money is the
         | settlement's business, and routing an exit decision on a figure that
         | does not exist yet would be inventing one.
         */
        self::EXIT_REQUEST => [
            'label'        => 'Exit approval',
            'model'        => HrExitRequest::class,
            'amount_field' => null,
            'conditions'   => ['department_id', 'branch', 'grade_id', 'exit_type_id'],
        ],

        /*
         | Probation confirmation — the APPROVAL, not the confirmation itself.
         |
         | The lifecycle is two-stage: Pending --approve--> Approved
         | --confirm--> Confirmed. Only the first is a decision. confirm() is
         | the execution of a decision already taken, in the same way
         | disburse() is for a loan — it writes the effective date and closes
         | the probation, and it already refuses anything that is not Approved.
         |
         | That refusal is what makes the ladder safe here: an intermediate
         | rung leaves the confirmation Pending, so confirm() still says "the
         | confirmation must be approved before the employee can be confirmed".
         | A half-approved probation cannot be closed, and closing one is
         | irreversible — a confirmed employee cannot return to probation.
         |
         | No amount. Confirming somebody carries no figure of its own.
         */
        self::PROBATION_CONFIRMATION => [
            'label'        => 'Probation confirmation',
            'model'        => HrProbationConfirmation::class,
            'amount_field' => null,
            'conditions'   => ['department_id', 'branch', 'grade_id'],
        ],
    ];

    public static function all(): array
    {
        return array_keys(self::DEFINITIONS);
    }

    public static function exists(?string $process): bool
    {
        return $process !== null && isset(self::DEFINITIONS[$process]);
    }

    public static function definition(string $process): ?array
    {
        return self::DEFINITIONS[$process] ?? null;
    }

    public static function label(string $process): string
    {
        return self::DEFINITIONS[$process]['label'] ?? $process;
    }

    public static function modelFor(string $process): ?string
    {
        return self::DEFINITIONS[$process]['model'] ?? null;
    }

    /** Which condition keys this process understands. */
    public static function conditionsFor(string $process): array
    {
        return self::DEFINITIONS[$process]['conditions'] ?? [];
    }

    public static function amountFieldFor(string $process): ?string
    {
        return self::DEFINITIONS[$process]['amount_field'] ?? null;
    }
}
