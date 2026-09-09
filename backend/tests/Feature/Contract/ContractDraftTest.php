<?php

namespace Tests\Feature\Contract;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Contract\ContractStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Parking a half-written contract.
 *
 * The New Contract form has two ways out: "Create contract", which insists the
 * record is complete enough to send, and "Save as draft", which keeps whatever
 * has been typed. The second one is only worth having if the API really will
 * accept a contract with a name and nothing else -- if any of the other fields
 * turns out to be required, the button appears to work and then 422s on the
 * one form state it exists to rescue.
 */
class ContractDraftTest extends TestCase
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

    /** Exactly what the "Save as draft" button posts from an all-but-empty form. */
    private function bareDraft(): array
    {
        return [
            'title' => 'Annual Maintenance Agreement',
            'status' => ContractStatus::DRAFT,
            'description' => '',
            'contract_category_id' => null,
            'party_type' => null, 'party_id' => null,
            'party_name' => '', 'party_email' => '',
            'value' => null, 'currency' => 'INR',
            'start_date' => null, 'end_date' => null,
            'renewal_notice_days' => 30,
            'pages' => [],
        ];
    }

    public function test_a_draft_saves_with_nothing_but_a_name(): void
    {
        Sanctum::actingAs($this->admin());

        $res = $this->postJson('/api/contracts', $this->bareDraft());

        if ($res->getStatusCode() >= 400) {
            $this->fail('the draft was refused: '.$res->getStatusCode().' — '
                .json_encode($res->json('errors') ?? $res->json('message')));
        }

        $this->assertSame(ContractStatus::DRAFT, $res->json('status'));
        $this->assertDatabaseHas('contracts', [
            'title' => 'Annual Maintenance Agreement',
            'status' => ContractStatus::DRAFT,
        ]);
    }

    public function test_the_draft_is_counted_as_one_on_the_dashboard(): void
    {
        Sanctum::actingAs($this->admin());
        $this->postJson('/api/contracts', $this->bareDraft())->assertSuccessful();

        // A draft nobody can find again is the same as one that never saved.
        $stats = $this->getJson('/api/contracts/stats')->assertOk()->json();
        $this->assertSame(1, $stats['draft'] ?? 0);

        $rows = $this->getJson('/api/contracts?status='.ContractStatus::DRAFT)->assertOk()->json();
        $this->assertCount(1, $rows['data'] ?? $rows);
    }

    public function test_finishing_a_draft_later_keeps_it_one_until_it_is_sent(): void
    {
        Sanctum::actingAs($this->admin());
        $id = $this->postJson('/api/contracts', $this->bareDraft())->json('id');

        // Come back and fill in the parts that were missing.
        $res = $this->putJson("/api/contracts/{$id}", [
            'title' => 'Annual Maintenance Agreement',
            'status' => ContractStatus::DRAFT,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
            'pages' => [['title' => 'Scope of Work', 'content' => '<p>As agreed.</p>']],
        ]);

        $res->assertSuccessful();
        $this->assertSame(ContractStatus::DRAFT, $res->json('status'),
            'a saved draft must stay a draft — sending it is what moves it on');
        $this->assertCount(1, $res->json('pages'));
    }

    /**
     * The form fills the page.
     *
     * It was capped at 980px, which on any normal monitor left a third of the
     * screen empty while the clause editor -- the one thing on the screen that
     * wants width -- was the narrowest box on it. This is a layout bug no HTTP
     * test can see, so it is pinned by reading the source: nothing about a
     * broken width shows up in a request, a console error or a screenshot diff.
     */
    public function test_the_contract_form_is_not_width_capped(): void
    {
        $path = base_path('../frontend/src/modules/contract/pages/ContractForm.jsx');
        $this->assertFileExists($path);

        $jsx = file_get_contents($path);

        $this->assertStringNotContainsString('maxWidth: 980', $jsx,
            'the contract form is capped again — the right of the page goes empty');

        // The two-panel layout is what actually uses the recovered width.
        $this->assertStringContainsString('cfm-cols', $jsx);
        $this->assertStringContainsString('Save as draft', $jsx,
            'the draft button is the other half of this screen');
    }
}
