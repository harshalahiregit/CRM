<?php

namespace App\Support\Numbering;

/**
 * The catalogue of every document type the numbering engine can number.
 *
 * This is the ONLY place a new document type is declared — the engine, the
 * service, the API and the UI all read from here, so adding a type is a one-line
 * change with no engine modification. Modules may also register types at runtime
 * via register() (e.g. from a service provider) without touching this file.
 *
 * Defaults deliberately mirror the formats those modules already emit today (see
 * the recon in the increment notes), so switching a type ON does not renumber
 * anything: the engine is OPT-IN per type — until a tenant enables a type, the
 * owning module keeps generating its own numbers exactly as before.
 */
final class DocumentTypeRegistry
{
    /** Runtime-registered types (module service providers may add to these). */
    private static array $extra = [];

    /**
     * key => [label, module, format, prefix, minimum_digits, reset_rule]
     */
    private const TYPES = [
        // ── Finance ──────────────────────────────────────────────────────
        'invoice'            => ['Invoice',            'Finance',     '{PREFIX}-{YYYY}-{NEXT}', 'INV',   3, 'yearly'],
        'quotation'          => ['Quotation',          'Finance',     '{PREFIX}-{YYYY}-{NEXT}', 'QTN',   3, 'yearly'],
        'estimate'           => ['Estimate',           'Finance',     '{PREFIX}-{YYYY}-{NEXT}', 'EST',   3, 'yearly'],
        'credit_note'        => ['Credit Note',        'Finance',     '{PREFIX}-{YYYY}-{NEXT}', 'CN',    3, 'yearly'],
        'debit_note'         => ['Debit Note',         'Finance',     '{PREFIX}-{YYYY}-{NEXT}', 'PDN',   3, 'yearly'],
        'receipt'            => ['Receipt',            'Finance',     '{PREFIX}-{YYYY}-{NEXT}', 'RCPT',  4, 'yearly'],
        'payment'            => ['Payment',            'Finance',     '{PREFIX}-{YYYY}-{NEXT}', 'PAY',   4, 'yearly'],
        'expense'            => ['Expense',            'Finance',     '{PREFIX}-{YYYY}-{NEXT}', 'EXP',   4, 'yearly'],

        // ── Purchase ─────────────────────────────────────────────────────
        'purchase_order'     => ['Purchase Order',     'Purchase',    '{PREFIX}-{YYYY}-{NEXT}', 'PO',    3, 'yearly'],
        'purchase_request'   => ['Purchase Request',   'Purchase',    '{PREFIX}-{YYYY}-{NEXT}', 'PR',    3, 'yearly'],
        'vendor_bill'        => ['Vendor Bill',        'Purchase',    '{PREFIX}-{YYYY}-{NEXT}', 'PINV',  3, 'yearly'],

        // ── Inventory ────────────────────────────────────────────────────
        'grn'                => ['GRN',                'Inventory',   '{PREFIX}-{YYYY}-{NEXT}', 'GRN',   3, 'yearly'],
        'stock_transfer'     => ['Stock Transfer',     'Inventory',   '{PREFIX}-{NEXT}',        'TRF',   4, 'never'],
        'adjustment'         => ['Stock Adjustment',   'Inventory',   '{PREFIX}-{NEXT}',        'ADJ',   4, 'never'],

        // ── HR ───────────────────────────────────────────────────────────
        'employee'           => ['Employee Code',      'HR',          '{PREFIX}-{YYYY}-{NEXT}', 'SNE',   3, 'never'],
        'offer_letter'       => ['Offer Letter',       'HR',          '{PREFIX}-{YYYY}-{NEXT}', 'OFR',   4, 'yearly'],
        'appointment_letter' => ['Appointment Letter', 'HR',          '{PREFIX}-{YYYY}-{NEXT}', 'APL',   4, 'yearly'],
        'salary_slip'        => ['Salary Slip',        'HR',          '{PREFIX}-{YYYY}-{MM}-{NEXT}', 'PS', 5, 'monthly'],

        // ── Payroll ──────────────────────────────────────────────────────
        'payroll_run'        => ['Payroll Run',        'Payroll',     '{PREFIX}-{YYYY}-{MM}-{NEXT}', 'PRUN', 3, 'monthly'],
        'payroll_batch'      => ['Payroll Batch',      'Payroll',     '{PREFIX}-{YYYY}-{NEXT}', 'PBAT',  4, 'yearly'],

        // ── Projects ─────────────────────────────────────────────────────
        'project'            => ['Project',            'Projects',    '{PREFIX}-{NEXT}',        'PRJ',   4, 'never'],
        'task'               => ['Task',               'Projects',    '{PREFIX}-{NEXT}',        'TSK',   5, 'never'],

        // ── Helpdesk ─────────────────────────────────────────────────────
        'ticket'             => ['Ticket',             'Helpdesk',    '{PREFIX}-{NEXT}',        'TKT',   6, 'never'],

        // ── Recruitment ──────────────────────────────────────────────────
        'candidate'          => ['Candidate',          'Recruitment', '{PREFIX}-{NEXT}',        'CAND',  4, 'never'],
        'job_requisition'    => ['Job Requisition',    'Recruitment', '{PREFIX}-{YYYY}-{NEXT}', 'JR',    4, 'yearly'],

        // ── TPV ──────────────────────────────────────────────────────────
        'tpv_vendor'         => ['TPV Vendor',         'TPV',         '{PREFIX}-{YYYY}-{NEXT}', 'TPV',   5, 'yearly'],
        'tpv_worker'         => ['TPV Worker',         'TPV',         '{PREFIX}-{NEXT}',        'WRK',   5, 'never'],
        'tpv_kickoff'        => ['TPV Kickoff',        'TPV',         '{PREFIX}-{YYYY}-{NEXT}', 'KO',    4, 'yearly'],
        'tpv_workforce'      => ['TPV Workforce',      'TPV',         '{PREFIX}-{NEXT}',        'WF',    5, 'never'],
        'tpv_ppe'            => ['TPV PPE',            'TPV',         '{PREFIX}-{NEXT}',        'PPE',   5, 'never'],
    ];

    /** Register (or override) a document type at runtime. */
    public static function register(string $key, array $definition): void
    {
        self::$extra[$key] = $definition;
    }

    /** Drop runtime registrations — static state, so tests must reset it. */
    public static function flush(): void
    {
        self::$extra = [];
    }

    /** All types: key => ['label','module','format','prefix','minimum_digits','reset_rule']. */
    public static function all(): array
    {
        $out = [];
        foreach (self::TYPES + self::$extra as $key => $d) {
            $out[$key] = self::normalise($key, $d);
        }
        // Runtime registrations win over built-ins with the same key.
        foreach (self::$extra as $key => $d) {
            $out[$key] = self::normalise($key, $d);
        }

        return $out;
    }

    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    /** Definition for one type, or null. */
    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /** Registry defaults shaped like a config row (used when no config exists yet). */
    public static function defaults(string $key): array
    {
        $d = self::get($key) ?? self::normalise($key, ['Unknown', 'Other', '{PREFIX}{NEXT}', 'DOC', 4, 'never']);

        return [
            'document_type'       => $key,
            'format'              => $d['format'],
            'prefix'              => $d['prefix'],
            'suffix'              => '',
            'minimum_digits'      => $d['minimum_digits'],
            'padding'             => '0',
            'starting_number'     => 1,
            'reset_rule'          => $d['reset_rule'],
            'epoch'               => 0,
            'enabled'             => false,   // OPT-IN: modules keep their own numbering until switched on
            'locked'              => false,
            'manual_override'     => false,
            'decrement_on_delete' => false,
        ];
    }

    /** Types grouped by module, for the settings UI selector. */
    public static function grouped(): array
    {
        $grouped = [];
        foreach (self::all() as $key => $d) {
            $grouped[$d['module']][] = ['key' => $key] + $d;
        }

        return $grouped;
    }

    private static function normalise(string $key, array $d): array
    {
        return [
            'label'          => $d[0] ?? $d['label'] ?? $key,
            'module'         => $d[1] ?? $d['module'] ?? 'Other',
            'format'         => $d[2] ?? $d['format'] ?? '{PREFIX}{NEXT}',
            'prefix'         => $d[3] ?? $d['prefix'] ?? '',
            'minimum_digits' => (int) ($d[4] ?? $d['minimum_digits'] ?? 4),
            'reset_rule'     => $d[5] ?? $d['reset_rule'] ?? 'never',
        ];
    }
}
