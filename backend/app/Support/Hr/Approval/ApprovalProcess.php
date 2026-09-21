<?php

namespace App\Support\Hr\Approval;

use App\Models\Hr\HrEmployeeLoan;
use App\Models\Hr\HrLeaveApplication;

/**
 * The HR processes an approval workflow can be configured for.
 *
 * A closed registry, not a discovered one. Each entry says which model the
 * request hangs off, which conditions make sense for it, and — the part that
 * keeps the engine out of the business — which value to read for an amount
 * condition. Nothing here executes anything.
 *
 * Phase 1 registers LEAVE only. The other ten processes are listed in the audit
 * and are deliberately not wired yet: the engine is proven on one flow before
 * the rest follow, so a mistake costs one migration rather than eleven.
 */
final class ApprovalProcess
{
    public const LEAVE = 'leave';
    public const LOAN  = 'loan';

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
