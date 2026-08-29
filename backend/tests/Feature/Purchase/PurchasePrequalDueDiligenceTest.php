<?php

namespace Tests\Feature\Purchase;

use App\Models\Purchase\PurchaseDueDiligence;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Purchase vendor Prequalification + Due-Diligence — the Purchase-side mirror of
 * the TPV scored questionnaire and verification checklist.
 *
 * Pins down the two things that carry the business rules:
 *  1. saving prequalification answers computes a normalised score and bands it —
 *     top answers → Qualified, bottom answers → Not_Qualified;
 *  2. due-diligence checks persist and roll up — all Verified → Cleared, any
 *     Failed → Rejected.
 * Plus tenant isolation and the admin gate on the writes.
 */
class PurchasePrequalDueDiligenceTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::TENANT, 2] as $id) {
            (new Tenant())->forceFill([
                'id' => $id, 'name' => "T{$id}", 'slug' => "t{$id}",
                'subdomain' => "t{$id}", 'status' => 'active',
            ])->save();
        }
    }

    private function user(string $role, int $tenant = self::TENANT): User
    {
        return User::create([
            'tenant_id' => $tenant, 'name' => ucfirst($role), 'role' => $role,
            'email' => $role.'-'.Str::random(8).'@test.local',
            'password' => bcrypt('secret'), 'status' => 'active',
        ]);
    }

    private function pv(string $name, int $tenant = self::TENANT): PurchaseVendor
    {
        return PurchaseVendor::create([
            'tenant_id' => $tenant, 'company_name' => $name,
            'email' => strtolower($name).'-'.Str::random(4).'@pv.local',
            'purchase_vendor_code' => 'PUR-'.Str::random(6), 'status' => 'Active',
        ]);
    }

    private function base(PurchaseVendor $v): string
    {
        return "/api/purchase/vendors/{$v->id}";
    }

    /** Build max-points and min-points answer maps straight from the config. */
    private function extremeAnswers(): array
    {
        $high = [];
        $low  = [];
        foreach (config('purchase_prequalification.sections', []) as $section) {
            foreach ($section['questions'] ?? [] as $qKey => $q) {
                $maxKey = null; $maxPts = -1;
                $minKey = null; $minPts = PHP_INT_MAX;
                foreach ($q['options'] ?? [] as $val => $o) {
                    if ($o['points'] > $maxPts) { $maxPts = $o['points']; $maxKey = $val; }
                    if ($o['points'] < $minPts) { $minPts = $o['points']; $minKey = $val; }
                }
                $high[$qKey] = $maxKey;
                $low[$qKey]  = $minKey;
            }
        }

        return [$high, $low];
    }

    /* ── Prequalification ─────────────────────────────────────────────── */

    public function test_top_answers_qualify_and_bottom_answers_do_not(): void
    {
        $v = $this->pv('QualCo');
        [$high, $low] = $this->extremeAnswers();
        $admin = $this->user('admin');
        Sanctum::actingAs($admin);

        // Highest option on every question → 100/100 → Qualified.
        $this->putJson($this->base($v).'/prequalification', ['answers' => $high, 'notes' => 'All strong'])
            ->assertOk()
            ->assertJsonPath('status', 'Qualified')
            ->assertJsonPath('assessed', true);

        $v->refresh();
        $this->assertSame('Qualified', $v->qualification_status);
        $this->assertGreaterThanOrEqual(config('purchase_prequalification.outcomes.Qualified'), $v->qualification_score);
        $this->assertSame($admin->id, (int) $v->qualified_by);
        $this->assertNotNull($v->qualified_at);

        // Lowest option on every question → 0/100 → Not_Qualified.
        $this->putJson($this->base($v).'/prequalification', ['answers' => $low])
            ->assertOk()
            ->assertJsonPath('status', 'Not_Qualified')
            ->assertJsonPath('score', 0);
    }

    public function test_show_returns_the_questionnaire_catalogue(): void
    {
        $v = $this->pv('CatCo');
        Sanctum::actingAs($this->user('staff'));

        $this->getJson($this->base($v).'/prequalification')
            ->assertOk()
            ->assertJsonPath('assessed', false)
            ->assertJsonStructure(['catalogue', 'outcomes', 'sections']);
    }

    public function test_prequalification_write_is_admin_only(): void
    {
        $v = $this->pv('GateCo');
        [$high] = $this->extremeAnswers();
        Sanctum::actingAs($this->user('staff'));

        // Reading is fine for staff; scoring is an admin authority decision.
        $this->getJson($this->base($v).'/prequalification')->assertOk();
        $this->putJson($this->base($v).'/prequalification', ['answers' => $high])->assertStatus(403);
    }

    public function test_a_foreign_tenant_vendor_is_not_found(): void
    {
        $foreign = $this->pv('ForeignCo', 2);
        Sanctum::actingAs($this->user('admin'));

        $this->getJson($this->base($foreign).'/prequalification')->assertStatus(404);
        $this->getJson($this->base($foreign).'/due-diligence')->assertStatus(404);
    }

    /* ── Due Diligence ────────────────────────────────────────────────── */

    public function test_all_verified_checks_clear_and_a_failed_check_rejects(): void
    {
        $v = $this->pv('DiligenceCo');
        $admin = $this->user('admin');
        Sanctum::actingAs($admin);

        $allVerified = [];
        foreach (PurchaseDueDiligence::CHECKS as $c) {
            $allVerified[$c] = 'Verified';
        }

        // Every actionable check Verified → Cleared.
        $this->putJson($this->base($v).'/due-diligence', $allVerified + ['notes' => 'Docs on file'])
            ->assertOk()
            ->assertJsonPath('status', 'Cleared')
            ->assertJsonPath('purchase_vendor_id', $v->id);

        $this->assertDatabaseHas('purchase_due_diligences', [
            'tenant_id' => self::TENANT, 'purchase_vendor_id' => $v->id, 'status' => 'Cleared',
        ]);

        // One Failed check → Rejected (upsert, same record).
        $withFailure = $allVerified;
        $withFailure['background_check'] = 'Failed';
        $this->putJson($this->base($v).'/due-diligence', $withFailure)
            ->assertOk()
            ->assertJsonPath('status', 'Rejected');

        $this->assertSame(1, PurchaseDueDiligence::where('purchase_vendor_id', $v->id)->count());
    }

    public function test_show_returns_the_check_catalogue(): void
    {
        $v = $this->pv('DdCatCo');
        Sanctum::actingAs($this->user('staff'));

        $this->getJson($this->base($v).'/due-diligence')
            ->assertOk()
            ->assertJsonPath('record', null)
            ->assertJsonStructure(['checks', 'states', 'statuses']);
    }

    public function test_due_diligence_write_is_admin_only(): void
    {
        $v = $this->pv('DdGateCo');
        Sanctum::actingAs($this->user('staff'));

        $this->putJson($this->base($v).'/due-diligence', ['company_verification' => 'Verified'])
            ->assertStatus(403);
    }
}
