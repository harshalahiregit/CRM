<?php

namespace Tests\Feature\Shared;

use App\Models\Purchase\PurchaseKickoffMeeting;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Shared\KickoffMeeting;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Support\Purchase\PurchaseKickoffStatus;
use App\Support\Purchase\PurchaseMomApprovalStatus;
use App\Support\Shared\KickoffStatus;
use App\Support\Shared\MomApprovalStatus;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * What the vendor actually receives when minutes are "distributed".
 *
 * The whole approve-then-distribute workflow exists to put the minutes of a
 * meeting in the vendor's hands. Several things stood between it and that:
 *
 *  1. The minutes DOCUMENT had no vendor-facing route at all. The only endpoint
 *     serving it sat behind role:admin,staff, so a vendor was told their minutes
 *     had been issued and given no way to open them.
 *  2. The two engines named the same content differently — the shared one sends
 *     `mom_items` and `decisions`, Purchase sends `action_items` and
 *     `mom_decisions` — and the single portal screen reading them knew only the
 *     shared engine's words. A Purchase vendor opened their minutes and saw the
 *     agenda and nothing else; the actions and decisions were in the response
 *     under names nobody was looking for.
 *  3. Issues were dropped for both, and so was the minutes text itself.
 *  4. `mom_viewed_at` — shown to administrators as "Viewed by vendor" — was
 *     stamped when an ADMINISTRATOR opened the PDF on the Purchase side, and
 *     never stamped at all on the shared side.
 *
 * Both portals now answer in one agreed shape (VendorMomView), so a claim proven
 * on one engine holds for the other.
 */
class VendorMomVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private Vendor $vendor;

    private User $vendorUser;

    private PurchaseVendor $pVendor;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();

        $this->admin = $this->user('admin');
        $this->vendorUser = $this->user('third_party_vendor');

        $this->vendor = Vendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Acme', 'status' => VendorStatus::ACTIVE,
        ]);
        $this->vendor->forceFill(['user_id' => $this->vendorUser->id])->save();

        $this->pVendor = PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Southgate',
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => 'sg-'.Str::random(4).'@t.local',
            'status' => 'Active', 'portal_status' => 'active',
        ]);
    }

    private function user(string $role): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => ucfirst($role), 'role' => $role,
            'email' => $role.'-'.Str::random(6).'@t.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    /* ── fixtures: a fully minuted meeting on each engine ────────────────── */

    /** A shared-engine meeting whose minutes carry one of everything. */
    private function sharedMeeting(string $momStatus = MomApprovalStatus::DISTRIBUTED): KickoffMeeting
    {
        $m = KickoffMeeting::create([
            'tenant_id' => self::TENANT, 'kickoffable_type' => Vendor::class,
            'kickoffable_id' => $this->vendor->id, 'title' => 'Shared kickoff',
            'meeting_type' => 'kickoff', 'status' => KickoffStatus::COMPLETED,
            'scheduled_at' => now()->subDay()->format('Y-m-d H:i:s'),
            'duration_minutes' => 60, 'mom_status' => $momStatus,
            'minutes' => 'The site walk was completed and access agreed.',
        ]);

        $m->agendaItems()->create([
            'tenant_id' => self::TENANT, 'item' => 'Site access',
            'discussion' => 'Gate 3 will be used.', 'decision' => 'Gate 3 confirmed.',
        ]);
        $m->momItems()->create([
            'tenant_id' => self::TENANT, 'action_ref' => 'ACT-01',
            'description' => 'Submit the revised manpower plan',
            'responsible_names' => 'Acme', 'status' => 'Open',
        ]);
        $m->decisions()->create([
            'tenant_id' => self::TENANT, 'decision_ref' => 'DEC-01',
            'decision' => 'Mobilisation starts Monday', 'decided_by_names' => 'Chair',
        ]);
        $m->issues()->create([
            'tenant_id' => self::TENANT, 'issue_ref' => 'ISS-01',
            'title' => 'Two welders lack valid tickets', 'severity' => 'High', 'status' => 'Open',
        ]);

        return $m->fresh();
    }

    /** The Purchase equivalent — the same content, that engine's own words. */
    private function purchaseMeeting(string $momStatus = PurchaseMomApprovalStatus::DISTRIBUTED): PurchaseKickoffMeeting
    {
        $m = PurchaseKickoffMeeting::create([
            'tenant_id' => self::TENANT, 'purchase_vendor_id' => $this->pVendor->id,
            'title' => 'Purchase kickoff', 'meeting_type' => 'kickoff',
            'status' => PurchaseKickoffStatus::COMPLETED,
            'scheduled_at' => now()->subDay()->format('Y-m-d H:i:s'),
            'duration_minutes' => 60, 'mom_status' => $momStatus,
            'minutes' => 'Rates were agreed and the delivery window fixed.',
        ]);

        $m->agendaItems()->create([
            'tenant_id' => self::TENANT, 'item' => 'Rates',
            'discussion' => 'Held at last year.', 'decision' => 'No change.',
        ]);
        $m->actionItems()->create([
            'tenant_id' => self::TENANT, 'action_ref' => 'ACT-01',
            'description' => 'Send the signed rate card',
            'responsible_names' => 'Southgate', 'status' => 'Open',
        ]);
        $m->momDecisions()->create([
            'tenant_id' => self::TENANT, 'decision_ref' => 'DEC-01',
            'decision' => 'Delivery every Tuesday', 'decided_by_names' => 'Chair',
        ]);
        $m->momIssues()->create([
            'tenant_id' => self::TENANT, 'issue_ref' => 'ISS-01',
            'title' => 'Late delivery in August', 'severity' => 'Medium', 'status' => 'Open',
        ]);

        return $m->fresh();
    }

    /* ── the content the vendor sees ─────────────────────────────────────── */

    /**
     * The headline bug: a Purchase vendor's minutes arrived with the agenda and
     * nothing else, because the screen was reading the other engine's words.
     */
    public function test_a_purchase_vendor_sees_the_actions_decisions_and_issues(): void
    {
        $m = $this->purchaseMeeting();
        Sanctum::actingAs($this->pVendor);

        $res = $this->getJson("/api/portal/purchase/meetings/{$m->id}/mom")->assertOk();

        $this->assertCount(1, $res->json('agenda'));
        $this->assertCount(1, $res->json('actions'), 'the action items were being dropped entirely');
        $this->assertCount(1, $res->json('decisions'), 'and so were the decisions');
        $this->assertCount(1, $res->json('issues'), 'issues were never rendered on either portal');

        $this->assertSame('ACT-01', $res->json('actions.0.ref'));
        $this->assertSame('Send the signed rate card', $res->json('actions.0.description'));
        $this->assertSame('Delivery every Tuesday', $res->json('decisions.0.decision'));
        $this->assertSame('Late delivery in August', $res->json('issues.0.title'));
    }

    public function test_a_tpv_vendor_sees_the_actions_decisions_and_issues(): void
    {
        $m = $this->sharedMeeting();
        Sanctum::actingAs($this->vendorUser);

        $res = $this->getJson("/api/portal/meetings/{$m->id}/mom")->assertOk();

        $this->assertCount(1, $res->json('agenda'));
        $this->assertCount(1, $res->json('actions'));
        $this->assertCount(1, $res->json('decisions'));
        $this->assertCount(1, $res->json('issues'));
        $this->assertSame('Two welders lack valid tickets', $res->json('issues.0.title'));
    }

    /** The minutes themselves — written in the meeting, never shown to anyone. */
    public function test_the_minutes_text_reaches_the_vendor_on_both_engines(): void
    {
        $shared = $this->sharedMeeting();
        Sanctum::actingAs($this->vendorUser);
        $this->getJson("/api/portal/meetings/{$shared->id}/mom")
            ->assertOk()
            ->assertJsonPath('minutes', 'The site walk was completed and access agreed.');

        $purchase = $this->purchaseMeeting();
        Sanctum::actingAs($this->pVendor);
        $this->getJson("/api/portal/purchase/meetings/{$purchase->id}/mom")
            ->assertOk()
            ->assertJsonPath('minutes', 'Rates were agreed and the delivery window fixed.');
    }

    /**
     * One shape, both engines.
     *
     * This is the property that stops the bug returning: the next thing added to
     * the minutes cannot go missing from one portal only.
     */
    public function test_both_portals_answer_in_the_same_shape(): void
    {
        $shared = $this->sharedMeeting();
        Sanctum::actingAs($this->vendorUser);
        $a = $this->getJson("/api/portal/meetings/{$shared->id}/mom")->assertOk()->json();

        $purchase = $this->purchaseMeeting();
        Sanctum::actingAs($this->pVendor);
        $b = $this->getJson("/api/portal/purchase/meetings/{$purchase->id}/mom")->assertOk()->json();

        $this->assertSame(array_keys($a), array_keys($b),
            'two portals showing one screen must not describe the same thing differently');
        $this->assertSame(array_keys($a['actions'][0]), array_keys($b['actions'][0]));
    }

    /* ── the document ────────────────────────────────────────────────────── */

    /**
     * The point of distributing minutes is that the recipient can read them.
     * There was no route by which they could.
     */
    public function test_a_tpv_vendor_can_open_the_minutes_document(): void
    {
        Storage::fake('kickoff_docs');
        $m = $this->sharedMeeting();
        Storage::disk('kickoff_docs')->put('mom/m.pdf', '%PDF-1.4 minutes');
        $m->forceFill(['mom_path' => 'mom/m.pdf'])->save();

        Sanctum::actingAs($this->vendorUser);

        $this->getJson("/api/portal/meetings/{$m->id}/mom")
            ->assertOk()
            ->assertJsonPath('mom_document_available', true);

        $this->get("/api/portal/meetings/{$m->id}/mom/file")->assertOk();
    }

    public function test_a_purchase_vendor_can_open_the_minutes_document(): void
    {
        $m = $this->purchaseMeeting();
        $disk = 'purchase_kickoff_docs';
        Storage::fake($disk);
        Storage::disk($disk)->put('mom/p.pdf', '%PDF-1.4 minutes');
        $m->momDocuments()->create([
            'tenant_id' => self::TENANT, 'file_path' => 'mom/p.pdf',
            'source' => 'generated', 'is_current' => true, 'generated_by' => $this->admin->id,
        ]);

        Sanctum::actingAs($this->pVendor);

        $this->getJson("/api/portal/purchase/meetings/{$m->id}/mom")
            ->assertOk()
            ->assertJsonPath('mom_document_available', true);

        $this->get("/api/portal/purchase/meetings/{$m->id}/mom/file")->assertOk();
    }

    /* ── the gate holds ──────────────────────────────────────────────────── */

    /** Minutes still in draft are nobody's to read but the people writing them. */
    public function test_undistributed_minutes_are_refused_on_both_engines(): void
    {
        $shared = $this->sharedMeeting(MomApprovalStatus::PENDING);
        Sanctum::actingAs($this->vendorUser);
        $this->getJson("/api/portal/meetings/{$shared->id}/mom")->assertForbidden();
        $this->getJson("/api/portal/meetings/{$shared->id}/mom/file")->assertForbidden();

        $purchase = $this->purchaseMeeting(PurchaseMomApprovalStatus::PENDING);
        Sanctum::actingAs($this->pVendor);
        $this->getJson("/api/portal/purchase/meetings/{$purchase->id}/mom")->assertForbidden();
        $this->getJson("/api/portal/purchase/meetings/{$purchase->id}/mom/file")->assertForbidden();
    }

    /** And another vendor's minutes are not theirs to read at all. */
    public function test_one_vendor_cannot_read_anothers_minutes(): void
    {
        $m = $this->sharedMeeting();

        $other = Vendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Rival', 'status' => VendorStatus::ACTIVE,
        ]);
        $otherUser = $this->user('third_party_vendor');
        $other->forceFill(['user_id' => $otherUser->id])->save();

        Sanctum::actingAs($otherUser);

        $this->getJson("/api/portal/meetings/{$m->id}/mom")->assertNotFound();
        $this->getJson("/api/portal/meetings/{$m->id}/mom/file")->assertNotFound();
    }

    /* ── the admin's own view of the document ────────────────────────────── */

    /**
     * "Is there a minutes document?", asked once and answered by both engines.
     *
     * The single admin meeting screen drives both, and it asked `mom_path` — a
     * column only the shared engine has. On Purchase that read as undefined
     * however many documents existed, so View PDF and Download PDF never
     * appeared and the generate button never changed its label. People pressed
     * it again: production data carries three generated copies of the same
     * minutes on one Purchase meeting, which is what that looks like from the
     * outside.
     */
    public function test_both_engines_report_whether_a_minutes_document_exists(): void
    {
        $shared = $this->sharedMeeting();
        $purchase = $this->purchaseMeeting();

        $this->assertFalse($shared->has_mom_document, 'nothing generated yet');
        $this->assertFalse($purchase->has_mom_document, 'nothing generated yet');

        $shared->forceFill(['mom_path' => 'mom/m.pdf'])->save();
        $purchase->momDocuments()->create([
            'tenant_id' => self::TENANT, 'file_path' => 'mom/p.pdf',
            'source' => 'generated', 'is_current' => true, 'generated_by' => $this->admin->id,
        ]);

        $this->assertTrue($shared->fresh()->has_mom_document);
        $this->assertTrue($purchase->fresh()->has_mom_document,
            'Purchase keeps its document in another table — the screen still has to be able to ask');
    }

    /** And it rides along on the payload the admin screen actually reads. */
    public function test_the_admin_payload_carries_the_document_flag_on_both_engines(): void
    {
        $shared = $this->sharedMeeting();
        $shared->forceFill(['mom_path' => 'mom/m.pdf'])->save();

        $purchase = $this->purchaseMeeting();
        $purchase->momDocuments()->create([
            'tenant_id' => self::TENANT, 'file_path' => 'mom/p.pdf',
            'source' => 'generated', 'is_current' => true, 'generated_by' => $this->admin->id,
        ]);

        Sanctum::actingAs($this->admin);

        $this->getJson("/api/kickoff/meetings/{$shared->id}")
            ->assertOk()->assertJsonPath('has_mom_document', true);

        $this->getJson("/api/purchase/kickoff/{$purchase->id}")
            ->assertOk()->assertJsonPath('has_mom_document', true);
    }

    /* ── "Viewed by vendor" means the vendor viewed it ───────────────────── */

    public function test_the_vendor_reading_the_minutes_is_what_stamps_them_viewed(): void
    {
        $shared = $this->sharedMeeting();
        $this->assertNull($shared->mom_viewed_at, 'nothing has been read yet');

        Sanctum::actingAs($this->vendorUser);
        $this->getJson("/api/portal/meetings/{$shared->id}/mom")->assertOk();

        $this->assertNotNull($shared->fresh()->mom_viewed_at,
            'the shared engine never stamped this at all — the tracker could not advance past Sent');
    }

    /**
     * And the administrator opening their own copy does NOT.
     *
     * mom_viewed_at is shown on the meeting as "Viewed by vendor". Stamping it
     * from the admin's own download made the record assert that the recipient
     * had read minutes only the sender had opened.
     */
    public function test_an_admin_opening_the_document_does_not_mark_it_read_by_the_vendor(): void
    {
        $m = $this->purchaseMeeting();
        $disk = 'purchase_kickoff_docs';
        Storage::fake($disk);
        Storage::disk($disk)->put('mom/p.pdf', '%PDF-1.4 minutes');
        $m->momDocuments()->create([
            'tenant_id' => self::TENANT, 'file_path' => 'mom/p.pdf',
            'source' => 'generated', 'is_current' => true, 'generated_by' => $this->admin->id,
        ]);

        Sanctum::actingAs($this->admin);
        $this->get("/api/purchase/kickoff/{$m->id}/mom")->assertOk();

        $this->assertNull($m->fresh()->mom_viewed_at,
            'the sender opening a document says nothing about whether the recipient read it');
    }
}
