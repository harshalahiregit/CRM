<?php

namespace Tests\Feature\Contract;

use App\Models\Contract\Contract;
use App\Models\Customer\Client;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Contract\ContractSigningService;
use App\Support\Contract\ContractParty;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The two signature chips on a contract row.
 *
 * Reported live: a contract showing status Active -- which only happens once
 * both parties have signed -- still had both chips on the pending clock.
 *
 * The cause is that the same field goes out under two names. The admin list
 * serialises the model, where the column is `signer_party`; the sign portal and
 * the party portal each hand-map it to `party`. The list matched on `party`,
 * found nothing, and drew "awaiting signature" for ever. No error, no console
 * warning -- the row just said something untrue.
 *
 * So this asserts the payload, not the pixels: whatever a signature is
 * serialised into must be matchable by the party keys the screens compare
 * against, and a signed one must read as signed.
 */
class ContractSignatureChipsTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'Sangoe OS', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();
    }

    private function admin(): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'a-'.Str::random(6).'@t.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function signedContract(): Contract
    {
        $client = Client::create(['tenant_id' => self::TENANT, 'company' => 'Northwind Traders']);

        $id = $this->postJson('/api/contracts', [
            'title' => 'Annual Management', 'party_type' => 'customer', 'party_id' => $client->id,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
            'pages' => [['title' => 'Scope', 'content' => '<p>As agreed.</p>']],
        ])->assertSuccessful()->json('id');

        $contract = Contract::findOrFail($id);
        $signing = app(ContractSigningService::class);

        foreach ([ContractParty::PARTY, ContractParty::COMPANY] as $party) {
            $signing->sign($contract->fresh(), $party, [
                'name'   => $party === ContractParty::PARTY ? 'Asha Rao' : 'Shivam',
                'method' => 'type',
                'image'  => 'Asha Rao',
            ], null, '127.0.0.1', 'phpunit');
        }

        return $contract->fresh('signatures');
    }

    public function test_a_fully_signed_contract_reads_as_signed_on_the_list(): void
    {
        Sanctum::actingAs($this->admin());
        $this->signedContract();

        $rows = $this->getJson('/api/contracts')->assertOk()->json();
        $rows = $rows['data'] ?? $rows;
        $sigs = $rows[0]['signatures'] ?? [];

        $this->assertCount(2, $sigs, 'both sides signed, so both rows must be there');

        foreach (['party', 'company'] as $key) {
            // This is literally what the screen does:
            //   signatures.find(s => (s.signer_party ?? s.party) === key)
            $match = collect($sigs)->first(fn ($s) => ($s['signer_party'] ?? $s['party'] ?? null) === $key);

            $this->assertNotNull($match,
                "no signature row matches '{$key}' — the chip falls back to the pending clock");
            $this->assertNotNull($match['signed_at'] ?? null,
                "the '{$key}' signature has no signed_at, so the chip stays pending");
        }
    }

    public function test_both_spellings_of_the_party_key_agree(): void
    {
        Sanctum::actingAs($this->admin());
        $this->signedContract();

        $rows = $this->getJson('/api/contracts')->assertOk()->json();
        $sigs = ($rows['data'] ?? $rows)[0]['signatures'];

        foreach ($sigs as $s) {
            // Two names for one field is what caused this; if they ever disagree
            // the bug is back, just on a different screen.
            $this->assertArrayHasKey('party', $s);
            $this->assertArrayHasKey('signer_party', $s);
            $this->assertSame($s['signer_party'], $s['party']);
        }
    }

    public function test_a_half_signed_contract_still_shows_one_side_pending(): void
    {
        Sanctum::actingAs($this->admin());
        $client = Client::create(['tenant_id' => self::TENANT, 'company' => 'Northwind Traders']);

        $id = $this->postJson('/api/contracts', [
            'title' => 'Half done', 'party_type' => 'customer', 'party_id' => $client->id,
            'pages' => [['title' => 'Scope', 'content' => '<p>As agreed.</p>']],
        ])->assertSuccessful()->json('id');

        app(ContractSigningService::class)->sign(
            Contract::findOrFail($id), ContractParty::PARTY,
            ['name' => 'Asha Rao', 'method' => 'type', 'image' => 'Asha Rao'],
            null, '127.0.0.1', 'phpunit',
        );

        $rows = $this->getJson('/api/contracts')->assertOk()->json();
        $sigs = ($rows['data'] ?? $rows)[0]['signatures'];

        $ours = collect($sigs)->first(fn ($s) => ($s['signer_party'] ?? null) === ContractParty::COMPANY);

        // The half-signed state is the one worth chasing, and the pair of chips
        // exists precisely so a single tick cannot hide it.
        $this->assertTrue($ours === null || ($ours['signed_at'] ?? null) === null,
            'our side has not signed, so that chip must still read as pending');
    }
}
