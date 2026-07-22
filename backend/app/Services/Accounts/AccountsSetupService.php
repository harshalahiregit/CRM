<?php

namespace App\Services\Accounts;

use App\Models\Accounts\AccountGroup;
use App\Models\Accounts\AccountMapping;
use App\Models\Accounts\FinancialYear;
use App\Models\Accounts\GstRate;
use App\Models\Accounts\Ledger;
use App\Models\Accounts\NumberingSeries;
use App\Models\Accounts\TdsSection;
use App\Models\Accounts\VoucherType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Initialises a tenant's books: the standard Tally-aligned Indian chart of
 * accounts (primary + derived groups), the nine voucher types with their
 * numbering series, an open financial year for today, and a starter Cash ledger.
 *
 * Idempotent — running twice does not duplicate anything. Seeding is per-tenant
 * (a company setting up its books), not a global DatabaseSeeder concern.
 */
class AccountsSetupService
{
    /**
     * Standard Tally group hierarchy. Each primary group carries its nature;
     * children inherit it. is_system = true protects them from deletion.
     */
    private const CHART = [
        ['name' => 'Capital Account', 'nature' => 'equity', 'children' => [
            ['name' => 'Reserves & Surplus'],
        ]],
        ['name' => 'Loans (Liability)', 'nature' => 'liability', 'children' => [
            ['name' => 'Bank OD A/c'],
            ['name' => 'Secured Loans'],
            ['name' => 'Unsecured Loans'],
        ]],
        ['name' => 'Current Liabilities', 'nature' => 'liability', 'children' => [
            ['name' => 'Duties & Taxes'],
            ['name' => 'Provisions'],
            ['name' => 'Sundry Creditors'],
        ]],
        ['name' => 'Fixed Assets', 'nature' => 'asset', 'children' => []],
        ['name' => 'Investments', 'nature' => 'asset', 'children' => []],
        ['name' => 'Branch / Divisions', 'nature' => 'asset', 'children' => []],
        ['name' => 'Current Assets', 'nature' => 'asset', 'children' => [
            ['name' => 'Bank Accounts'],
            ['name' => 'Cash-in-Hand'],
            ['name' => 'Deposits (Asset)'],
            ['name' => 'Loans & Advances (Asset)'],
            ['name' => 'Stock-in-Hand'],
            ['name' => 'Sundry Debtors'],
        ]],
        ['name' => 'Misc. Expenses (Asset)', 'nature' => 'asset', 'children' => []],
        ['name' => 'Suspense A/c', 'nature' => 'asset', 'children' => []],
        ['name' => 'Sales Accounts', 'nature' => 'income', 'children' => []],
        ['name' => 'Purchase Accounts', 'nature' => 'expense', 'children' => []],
        ['name' => 'Direct Incomes', 'nature' => 'income', 'children' => []],
        ['name' => 'Indirect Incomes', 'nature' => 'income', 'children' => []],
        ['name' => 'Direct Expenses', 'nature' => 'expense', 'children' => []],
        ['name' => 'Indirect Expenses', 'nature' => 'expense', 'children' => []],
    ];

    /** code => [name, prefix]. Numbering series get a yearly reset, width 4. */
    private const VOUCHER_TYPES = [
        'sales'         => ['Sales', 'SAL-'],
        'purchase'      => ['Purchase', 'PUR-'],
        'payment'       => ['Payment', 'PAY-'],
        'receipt'       => ['Receipt', 'REC-'],
        'contra'        => ['Contra', 'CON-'],
        'journal'       => ['Journal', 'JV-'],
        'debit_note'    => ['Debit Note', 'DN-'],
        'credit_note'   => ['Credit Note', 'CN-'],
        'stock_journal' => ['Stock Journal', 'SJ-'],
    ];

    /** name => [rate, cgst, sgst, igst, cess]. IGST = full rate; CGST/SGST = half. */
    private const GST_RATES = [
        'GST 0%'  => [0, 0, 0, 0, 0],
        'GST 5%'  => [5, 2.5, 2.5, 5, 0],
        'GST 12%' => [12, 6, 6, 12, 0],
        'GST 18%' => [18, 9, 9, 18, 0],
        'GST 28%' => [28, 14, 14, 28, 0],
    ];

    /** section => [rate_percent, threshold, description]. Illustrative defaults — CA must validate. */
    private const TDS_SECTIONS = [
        '194C' => [1, 30000, 'Payments to contractors'],
        '194H' => [5, 15000, 'Commission or brokerage'],
        '194I' => [10, 240000, 'Rent'],
        '194J' => [10, 30000, 'Professional / technical fees'],
        '194Q' => [0.1, 5000000, 'Purchase of goods'],
    ];

    public function setup(int $tenantId): array
    {
        return DB::transaction(function () use ($tenantId) {
            $this->seedGroups($tenantId);
            $this->seedVoucherTypes($tenantId);
            $fy = $this->ensureFinancialYear($tenantId);
            $this->seedStarterLedgers($tenantId);
            $this->seedGstRates($tenantId);
            $this->seedTdsSections($tenantId);
            $this->seedTaxLedgers($tenantId);
            $this->seedIntegrationLedgers($tenantId);
            $this->seedAccountMappings($tenantId);

            Log::channel('accounts')->info('Accounts module set up for tenant', ['tenant_id' => $tenantId]);

            return $this->status($tenantId, $fy);
        });
    }

    /** Whether a tenant's books have been initialised. */
    public function status(int $tenantId, ?FinancialYear $fy = null): array
    {
        return [
            'groups'         => AccountGroup::forTenant($tenantId)->count(),
            'ledgers'        => Ledger::forTenant($tenantId)->count(),
            'voucher_types'  => VoucherType::forTenant($tenantId)->count(),
            'financial_year' => ($fy ?? FinancialYear::forTenant($tenantId)->latest('start_date')->first()),
            'is_setup'       => AccountGroup::forTenant($tenantId)->exists()
                && VoucherType::forTenant($tenantId)->exists()
                && FinancialYear::forTenant($tenantId)->exists(),
        ];
    }

    private function seedGroups(int $tenantId): void
    {
        foreach (self::CHART as $primary) {
            $parent = AccountGroup::firstOrCreate(
                ['tenant_id' => $tenantId, 'name' => $primary['name']],
                ['nature' => $primary['nature'], 'is_primary' => true, 'is_system' => true],
            );

            foreach ($primary['children'] ?? [] as $child) {
                AccountGroup::firstOrCreate(
                    ['tenant_id' => $tenantId, 'name' => $child['name']],
                    ['nature' => $primary['nature'], 'parent_id' => $parent->id, 'is_primary' => false, 'is_system' => true],
                );
            }
        }
    }

    private function seedVoucherTypes(int $tenantId): void
    {
        foreach (self::VOUCHER_TYPES as $code => [$name, $prefix]) {
            $series = NumberingSeries::firstOrCreate(
                ['tenant_id' => $tenantId, 'voucher_type_code' => $code],
                ['prefix' => $prefix, 'suffix' => '', 'next_number' => 1, 'reset_frequency' => 'yearly', 'width' => 4],
            );

            VoucherType::firstOrCreate(
                ['tenant_id' => $tenantId, 'code' => $code],
                [
                    'name' => $name,
                    'numbering_series_id' => $series->id,
                    'affects_stock' => in_array($code, ['stock_journal'], true),
                    'affects_gst'   => in_array($code, ['sales', 'purchase', 'debit_note', 'credit_note'], true),
                ],
            );
        }
    }

    /** Open FY for the current Indian financial year (Apr 1 – Mar 31). */
    private function ensureFinancialYear(int $tenantId): FinancialYear
    {
        $today = Carbon::today();
        $startYear = $today->month >= 4 ? $today->year : $today->year - 1;
        $start = Carbon::create($startYear, 4, 1);
        $end   = Carbon::create($startYear + 1, 3, 31);

        return FinancialYear::firstOrCreate(
            ['tenant_id' => $tenantId, 'start_date' => $start->toDateString()],
            ['end_date' => $end->toDateString(), 'is_closed' => false],
        );
    }

    /** A ready-to-use Cash ledger so the user can post immediately after setup. */
    private function seedStarterLedgers(int $tenantId): void
    {
        $cashGroup = AccountGroup::forTenant($tenantId)->where('name', 'Cash-in-Hand')->first();
        if ($cashGroup) {
            Ledger::firstOrCreate(
                ['tenant_id' => $tenantId, 'name' => 'Cash'],
                ['group_id' => $cashGroup->id, 'is_cash' => true, 'opening_balance_type' => 'dr'],
            );
        }
    }

    private function seedGstRates(int $tenantId): void
    {
        foreach (self::GST_RATES as $name => [$rate, $cgst, $sgst, $igst, $cess]) {
            GstRate::firstOrCreate(
                ['tenant_id' => $tenantId, 'name' => $name],
                ['rate_percent' => $rate, 'cgst' => $cgst, 'sgst' => $sgst, 'igst' => $igst, 'cess' => $cess, 'is_active' => true],
            );
        }
    }

    private function seedTdsSections(int $tenantId): void
    {
        foreach (self::TDS_SECTIONS as $code => [$rate, $threshold, $desc]) {
            TdsSection::firstOrCreate(
                ['tenant_id' => $tenantId, 'section_code' => $code],
                ['rate_percent' => $rate, 'threshold' => $threshold, 'description' => $desc, 'is_active' => true],
            );
        }
    }

    /**
     * GST/TDS control ledgers. Output GST + TDS Payable are liabilities (Duties &
     * Taxes); Input GST is recoverable → an asset (Loans & Advances).
     */
    private function seedTaxLedgers(int $tenantId): void
    {
        $duties = AccountGroup::forTenant($tenantId)->where('name', 'Duties & Taxes')->first();
        $advances = AccountGroup::forTenant($tenantId)->where('name', 'Loans & Advances (Asset)')->first();

        $mk = function (?AccountGroup $group, string $name) use ($tenantId) {
            if ($group) {
                Ledger::firstOrCreate(['tenant_id' => $tenantId, 'name' => $name], ['group_id' => $group->id]);
            }
        };

        foreach (['Output CGST', 'Output SGST', 'Output IGST', 'Output Cess', 'TDS Payable'] as $n) {
            $mk($duties, $n);
        }
        foreach (['Input CGST', 'Input SGST', 'Input IGST', 'Input Cess'] as $n) {
            $mk($advances, $n);
        }
    }

    /** Default posting-target ledgers used by the Sales/Customer integration. */
    private function seedIntegrationLedgers(int $tenantId): void
    {
        $byGroup = function (string $groupName, string $ledgerName, array $extra = []) use ($tenantId) {
            $group = AccountGroup::forTenant($tenantId)->where('name', $groupName)->first();
            if ($group) {
                Ledger::firstOrCreate(['tenant_id' => $tenantId, 'name' => $ledgerName], ['group_id' => $group->id, ...$extra]);
            }
        };
        $byGroup('Sales Accounts', 'Sales');
        $byGroup('Indirect Expenses', 'General Expenses');
        $byGroup('Loans & Advances (Asset)', 'TDS Receivable');
        $byGroup('Indirect Expenses', 'Discount Allowed');
        $byGroup('Indirect Incomes', 'Rounding Off');
    }

    /**
     * Bind semantic posting roles → ledgers (spec v2 §7). The Sales/Customer
     * posting bridge resolves ledgers through these, so a company can re-point any
     * role without code changes. Party (debtor) ledgers are resolved per client.
     */
    private function seedAccountMappings(int $tenantId): void
    {
        $roles = [
            'sales_income'    => 'Sales',
            'output_cgst'     => 'Output CGST',
            'output_sgst'     => 'Output SGST',
            'output_igst'     => 'Output IGST',
            'output_cess'     => 'Output Cess',
            'input_cgst'      => 'Input CGST',
            'input_sgst'      => 'Input SGST',
            'input_igst'      => 'Input IGST',
            'input_cess'      => 'Input Cess',
            'cash'            => 'Cash',
            'bank_default'    => 'Cash',       // until a real bank account exists
            'tds_payable'     => 'TDS Payable',
            'tds_receivable'  => 'TDS Receivable',
            'expense_default' => 'General Expenses',
            'discount_allowed' => 'Discount Allowed',
            'round_off'       => 'Rounding Off',
        ];

        $ledgerIds = Ledger::forTenant($tenantId)->pluck('id', 'name');

        foreach ($roles as $role => $ledgerName) {
            if ($ledgerIds->has($ledgerName)) {
                AccountMapping::firstOrCreate(
                    ['tenant_id' => $tenantId, 'role_key' => $role],
                    ['ledger_id' => $ledgerIds->get($ledgerName)],
                );
            }
        }
    }
}
