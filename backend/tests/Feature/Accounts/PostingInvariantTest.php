<?php

namespace Tests\Feature\Accounts;

use App\Exceptions\BusinessException;
use App\Models\Accounts\Ledger;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Accounts\AccountsSetupService;
use App\Services\Accounts\PostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The double-entry invariant: every voucher must balance to zero in exact paise.
 *
 * This is the single rule the whole Accounts module rests on — if an unbalanced
 * voucher can ever be written, every downstream report is wrong and there is no
 * way to tell from the reports themselves. PostingService is the only gate, so
 * it is tested directly rather than through a controller.
 */
class PostingInvariantTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private PostingService $posting;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();

        User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'email' => 'acct'.uniqid().'@test.com',
            'password' => bcrypt('secret'), 'role' => 'admin', 'status' => 'active',
        ]);

        // Chart of accounts, voucher types and the financial year.
        app(AccountsSetupService::class)->setup(self::TENANT);
        $this->posting = app(PostingService::class);
    }

    private function anyTwoLedgers(): array
    {
        $ledgers = Ledger::where('tenant_id', self::TENANT)->take(2)->get();
        $this->assertCount(2, $ledgers, 'accounts setup should create ledgers');

        return [$ledgers[0]->id, $ledgers[1]->id];
    }

    private function voucher(array $lines, string $date = '2026-08-01'): array
    {
        return [
            'voucher_type_code' => 'journal',
            'date' => $date,
            'narration' => 'test',
            'lines' => $lines,
        ];
    }

    public function test_a_balanced_voucher_posts(): void
    {
        [$a, $b] = $this->anyTwoLedgers();

        $voucher = $this->posting->post($this->voucher([
            ['ledger_id' => $a, 'debit' => 1500.50, 'credit' => 0],
            ['ledger_id' => $b, 'debit' => 0, 'credit' => 1500.50],
        ]), self::TENANT, null);

        $this->assertNotNull($voucher->id);
        $this->assertNotEmpty($voucher->number);
    }

    public function test_an_unbalanced_voucher_is_refused(): void
    {
        [$a, $b] = $this->anyTwoLedgers();

        $this->expectException(BusinessException::class);
        $this->posting->post($this->voucher([
            ['ledger_id' => $a, 'debit' => 1000, 'credit' => 0],
            ['ledger_id' => $b, 'debit' => 0, 'credit' => 999],
        ]), self::TENANT, null);
    }

    public function test_a_sub_paise_imbalance_is_still_refused(): void
    {
        [$a, $b] = $this->anyTwoLedgers();

        // Off by one paise — the case floating-point comparison would wave through.
        $this->expectException(BusinessException::class);
        $this->posting->post($this->voucher([
            ['ledger_id' => $a, 'debit' => 100.01, 'credit' => 0],
            ['ledger_id' => $b, 'debit' => 0, 'credit' => 100.00],
        ]), self::TENANT, null);
    }

    public function test_a_zero_value_voucher_is_refused(): void
    {
        [$a, $b] = $this->anyTwoLedgers();

        // Balances, but records nothing — it would only add noise to the ledger.
        $this->expectException(BusinessException::class);
        $this->posting->post($this->voucher([
            ['ledger_id' => $a, 'debit' => 0, 'credit' => 0],
            ['ledger_id' => $b, 'debit' => 0, 'credit' => 0],
        ]), self::TENANT, null);
    }

    public function test_posting_to_another_tenants_ledger_is_refused(): void
    {
        (new Tenant())->forceFill([
            'id' => 999, 'name' => 'T2', 'slug' => 't2', 'subdomain' => 't2', 'status' => 'active',
        ])->save();
        app(AccountsSetupService::class)->setup(999);

        $mine = Ledger::where('tenant_id', self::TENANT)->first();
        $theirs = Ledger::where('tenant_id', 999)->first();

        $this->expectException(BusinessException::class);
        $this->posting->post($this->voucher([
            ['ledger_id' => $mine->id,   'debit' => 500, 'credit' => 0],
            ['ledger_id' => $theirs->id, 'debit' => 0,   'credit' => 500],
        ]), self::TENANT, null);
    }
}
