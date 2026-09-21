<?php

namespace App\Support\Hr\Approval;

use App\Models\Hr\HrEmployeeLoan;
use App\Models\Hr\HrEmployeeVariableEarning;
use App\Models\Hr\HrLeaveApplication;

/**
 * The HR processes an approval workflow can be configured for.
 *
 * A closed registry, not a discovered one. Each entry says which model the
 * request hangs off, which conditions make sense for it, and — the part that
 * keeps the engine out of the business — which value to read for an amount
 * condition. Nothing here executes anything.
 *
 * Processes are registered one at a time, each with its own reviewed migration,
 * so a mistake costs one flow rather than eleven. Leave, loans and variable
 * earnings are wired; the rest stay on the old shared gate until they are
 * migrated deliberately.
 */
final class ApprovalProcess
{
    public const LEAVE             = 'leave';
    public const LOAN              = 'loan';
    public const VARIABLE_EARNING  = 'variable_earning';

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
