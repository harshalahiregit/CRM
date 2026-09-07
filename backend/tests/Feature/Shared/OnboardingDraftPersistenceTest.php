<?php

namespace Tests\Feature\Shared;

use App\Models\Purchase\PurchaseOnboarding;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Tenant;
use App\Models\Tpv\TpvOnboarding;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A half-filled form survives moving to the next step.
 *
 * The onboarding wizard used to change step by swapping the panel and telling
 * the server which step was open — nothing else. A vendor who filled in half
 * the company profile and pressed the next step in the tracker lost every word
 * of it: no warning, no draft, nothing to come back to. Only the explicit
 * "Save & Continue" button ever wrote anything, and only when the whole form
 * passed its completeness check.
 *
 * The wizard now flushes whatever has been entered on the way past. That is
 * only safe because a partial profile is a legitimate thing to store, which is
 * what this proves on both engines: the fields are nullable, the save MERGES
 * rather than replaces, and a draft never advances the onboarding's status or
 * counts as a completed step.
 */
class OnboardingDraftPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private User $vendorUser;

    private PurchaseVendor $pVendor;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();

        $this->vendorUser = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Vendor', 'role' => 'third_party_vendor',
            'email' => 'v-'.Str::random(6).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);

        $this->pVendor = PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Southgate',
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => 'sg-'.Str::random(4).'@t.local',
            'status' => 'Active', 'portal_status' => 'active',
        ]);
    }

    private function tpvOnboarding(): TpvOnboarding
    {
        $vendor = Vendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Acme',
            'status' => VendorStatus::DRAFT, 'user_id' => $this->vendorUser->id,
        ]);

        return TpvOnboarding::create([
            'tenant_id' => self::TENANT, 'vendor_id' => $vendor->id,
            'status' => 'In_Progress', 'current_step' => 2,
        ]);
    }

    private function purchaseOnboarding(): PurchaseOnboarding
    {
        return PurchaseOnboarding::create([
            'tenant_id' => self::TENANT, 'purchase_vendor_id' => $this->pVendor->id,
            'status' => 'In_Progress', 'current_step' => 2,
        ]);
    }

    /* ── a partial form is a legitimate thing to store ───────────────────── */

    /**
     * The behaviour the wizard now relies on: send what has been typed so far,
     * and the server keeps it rather than refusing it for being incomplete.
     */
    public function test_a_half_filled_tpv_profile_is_accepted_and_kept(): void
    {
        $ob = $this->tpvOnboarding();
        Sanctum::actingAs($this->vendorUser);

        $this->postJson("/api/portal/onboarding/{$ob->id}/profile", [
            'profile' => ['company_name' => 'Acme Contractors', 'contact_person' => 'Rita Bose'],
        ])->assertOk();

        $saved = $ob->fresh()->profile;

        $this->assertSame('Acme Contractors', $saved['company_name']);
        $this->assertSame('Rita Bose', $saved['contact_person']);
    }

    public function test_a_half_filled_purchase_profile_is_accepted_and_kept(): void
    {
        $ob = $this->purchaseOnboarding();
        Sanctum::actingAs($this->pVendor);

        $this->postJson("/api/portal/purchase/onboarding/{$ob->id}/profile", [
            'profile' => ['company_name' => 'Southgate Supplies', 'contact_person' => 'Ali Raza'],
        ])->assertOk();

        $saved = $ob->fresh()->profile;

        $this->assertSame('Southgate Supplies', $saved['company_name']);
        $this->assertSame('Ali Raza', $saved['contact_person']);
    }

    /**
     * The second half of the form does not wipe the first.
     *
     * Flushing on every step change means the same profile is written several
     * times, each with only the part that was on screen. A save that replaced
     * rather than merged would erase the earlier steps' answers on the way
     * forward — worse than the bug being fixed.
     */
    public function test_a_later_draft_does_not_erase_an_earlier_one(): void
    {
        $ob = $this->tpvOnboarding();
        Sanctum::actingAs($this->vendorUser);

        $this->postJson("/api/portal/onboarding/{$ob->id}/profile", [
            'profile' => ['company_name' => 'Acme Contractors'],
        ])->assertOk();

        $this->postJson("/api/portal/onboarding/{$ob->id}/profile", [
            'profile' => ['bank_name' => 'State Bank'],
        ])->assertOk();

        $saved = $ob->fresh()->profile;

        $this->assertSame('Acme Contractors', $saved['company_name'], 'the first draft must survive the second');
        $this->assertSame('State Bank', $saved['bank_name']);
    }

    public function test_a_later_purchase_draft_does_not_erase_an_earlier_one(): void
    {
        $ob = $this->purchaseOnboarding();
        Sanctum::actingAs($this->pVendor);

        $this->postJson("/api/portal/purchase/onboarding/{$ob->id}/profile", [
            'profile' => ['company_name' => 'Southgate Supplies'],
        ])->assertOk();
        $this->postJson("/api/portal/purchase/onboarding/{$ob->id}/profile", [
            'profile' => ['bank_name' => 'State Bank'],
        ])->assertOk();

        $saved = $ob->fresh()->profile;

        $this->assertSame('Southgate Supplies', $saved['company_name']);
        $this->assertSame('State Bank', $saved['bank_name']);
    }

    /* ── a draft is only a draft ─────────────────────────────────────────── */

    /**
     * Storing what was typed must not be mistaken for finishing the step.
     *
     * The vendor left half a form behind; the onboarding is still In Progress
     * and still theirs to finish. If a draft advanced the status, autosaving on
     * the way past would quietly submit incomplete onboardings.
     */
    public function test_a_draft_does_not_advance_the_onboarding_status(): void
    {
        $ob = $this->tpvOnboarding();
        Sanctum::actingAs($this->vendorUser);

        $this->postJson("/api/portal/onboarding/{$ob->id}/profile", [
            'profile' => ['company_name' => 'Acme Contractors'],
        ])->assertOk();

        $this->assertSame('In_Progress', $ob->fresh()->status,
            'a saved draft is not a submitted onboarding');
    }

    /** And an onboarding that is no longer editable refuses the draft outright. */
    public function test_a_closed_onboarding_will_not_take_a_draft(): void
    {
        $ob = $this->tpvOnboarding();
        $ob->forceFill(['status' => 'Approved'])->save();

        Sanctum::actingAs($this->vendorUser);

        $this->postJson("/api/portal/onboarding/{$ob->id}/profile", [
            'profile' => ['company_name' => 'Sneaky Rename Ltd'],
        ])->assertStatus(422);

        $this->assertNull($ob->fresh()->profile['company_name'] ?? null,
            'an approved onboarding is not editable, by autosave or otherwise');
    }
}
