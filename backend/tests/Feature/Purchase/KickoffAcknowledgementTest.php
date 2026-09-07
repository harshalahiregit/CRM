<?php

namespace Tests\Feature\Purchase;

use App\Models\Purchase\PurchaseKickoffMeeting;
use App\Models\Purchase\PurchaseOnboarding;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Purchase\PurchaseOnboardingService;
use App\Support\Purchase\PurchaseKickoffStatus;
use App\Support\Purchase\PurchaseMomApprovalStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Onboarding Step 1 — acknowledging the kickoff minutes.
 *
 * The step showed a completed meeting's minutes, said "The MOM document is not
 * available yet" underneath them, and let the vendor tick "I have read and
 * understood the Minutes of Meeting" and move on anyway. Three separate faults
 * produced that:
 *
 *  1. Two different resolvers decided "the vendor's kickoff meeting" by
 *     different rules. The card came from one, the document from the other, and
 *     they disagreed — the screen showed meeting A while every download asked
 *     for meeting B.
 *  2. The onboarding's pin was honoured even when it pointed at a CANCELLED
 *     meeting with draft minutes and no document, which is what the second
 *     resolver kept returning.
 *  3. Nothing checked that there were any minutes before accepting the
 *     acknowledgement. The record then said the vendor had read something that
 *     had never been issued to them.
 *
 * A fourth would have hidden the first three: the fallback that generated the
 * document on demand passed a PurchaseVendor where a User was required, so it
 * always threw and was reported as "not available yet".
 */
class KickoffAcknowledgementTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private const DISK = 'purchase_kickoff_docs';

    private PurchaseVendor $vendor;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(self::DISK);

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();

        $this->admin = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'a-'.Str::random(6).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);

        $this->vendor = PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Southgate',
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => 'sg-'.Str::random(4).'@t.local',
            'status' => 'Active', 'portal_status' => 'active',
        ]);
    }

    private function onboarding(): PurchaseOnboarding
    {
        return PurchaseOnboarding::create([
            'tenant_id' => self::TENANT, 'purchase_vendor_id' => $this->vendor->id,
            'status' => 'In_Progress', 'current_step' => 1,
        ]);
    }

    private function meeting(string $status, string $momStatus, string $title = 'Kickoff'): PurchaseKickoffMeeting
    {
        return PurchaseKickoffMeeting::create([
            'tenant_id' => self::TENANT, 'purchase_vendor_id' => $this->vendor->id,
            'created_by' => $this->admin->id, 'title' => $title, 'meeting_type' => 'kickoff',
            'status' => $status, 'mom_status' => $momStatus,
            'scheduled_at' => now()->subDay()->format('Y-m-d H:i:s'), 'duration_minutes' => 60,
        ]);
    }

    /** Give a meeting a real, readable minutes document. */
    private function withDocument(PurchaseKickoffMeeting $m): PurchaseKickoffMeeting
    {
        $path = "mom/m{$m->id}.pdf";
        Storage::disk(self::DISK)->put($path, '%PDF-1.4 minutes');
        $m->momDocuments()->create([
            'tenant_id' => self::TENANT, 'file_path' => $path,
            'source' => 'generated', 'is_current' => true, 'generated_by' => $this->admin->id,
        ]);

        return $m->fresh();
    }

    private function resolve(PurchaseOnboarding $ob): ?PurchaseKickoffMeeting
    {
        return app(PurchaseOnboardingService::class)->resolveKickoffMeeting($ob);
    }

    /* ── which meeting Step 1 is about ───────────────────────────────────── */

    /**
     * The state the reported screen was in: a cancelled first attempt still
     * pinned to the onboarding, and the meeting that was actually held beside it.
     */
    public function test_a_cancelled_meeting_is_never_the_one_to_acknowledge(): void
    {
        $ob = $this->onboarding();
        $cancelled = $this->meeting(PurchaseKickoffStatus::CANCELLED, PurchaseMomApprovalStatus::DRAFT);
        $held = $this->withDocument(
            $this->meeting(PurchaseKickoffStatus::COMPLETED, PurchaseMomApprovalStatus::DISTRIBUTED)
        );

        // The pin the onboarding was left holding.
        $ob->forceFill(['kickoff_meeting_id' => $cancelled->id])->save();

        $this->assertSame($held->id, $this->resolve($ob->fresh())->id,
            'a cancelled meeting has no minutes to acknowledge, pinned or not');
    }

    /** Issued minutes outrank a newer draft — a draft has nothing to acknowledge. */
    public function test_the_meeting_with_issued_minutes_wins_over_a_newer_draft(): void
    {
        $ob = $this->onboarding();
        $held = $this->withDocument(
            $this->meeting(PurchaseKickoffStatus::COMPLETED, PurchaseMomApprovalStatus::DISTRIBUTED, 'Held')
        );
        $this->meeting(PurchaseKickoffStatus::SCHEDULED, PurchaseMomApprovalStatus::DRAFT, 'Next one');

        $this->assertSame($held->id, $this->resolve($ob)->id);
    }

    /**
     * The card and the buttons must be about the same meeting.
     *
     * This is the fault the screenshot showed: minutes from one meeting on
     * screen, "not available yet" from another underneath them.
     */
    public function test_the_step_card_and_the_document_agree_on_one_meeting(): void
    {
        $ob = $this->onboarding();
        $cancelled = $this->meeting(PurchaseKickoffStatus::CANCELLED, PurchaseMomApprovalStatus::DRAFT);
        $held = $this->withDocument(
            $this->meeting(PurchaseKickoffStatus::COMPLETED, PurchaseMomApprovalStatus::DISTRIBUTED)
        );
        $ob->forceFill(['kickoff_meeting_id' => $cancelled->id])->save();

        Sanctum::actingAs($this->vendor);

        $card = $this->getJson('/api/portal/purchase/kickoff')->assertOk();

        $this->assertSame($held->id, $card->json('meeting.id'),
            'the card must show the meeting whose minutes were issued');
        $this->assertTrue($card->json('meeting.mom_available'));

        // And the document behind the buttons on that card opens.
        $this->get("/api/portal/purchase/onboarding/{$ob->id}/kickoff")->assertOk();
    }

    /* ── the acknowledgement itself ──────────────────────────────────────── */

    /**
     * The heart of it: nothing to read means nothing to acknowledge.
     *
     * Refused at the service, not merely disabled on screen — a hidden button
     * is a courtesy, not a rule.
     */
    public function test_minutes_that_were_never_issued_cannot_be_acknowledged(): void
    {
        $ob = $this->onboarding();
        $this->meeting(PurchaseKickoffStatus::COMPLETED, PurchaseMomApprovalStatus::DRAFT);

        Sanctum::actingAs($this->vendor);

        $this->postJson("/api/portal/purchase/onboarding/{$ob->id}/kickoff/accept")
            ->assertStatus(422);

        $this->assertFalse((bool) $ob->fresh()->acknowledged,
            'the record must not claim the vendor read minutes that were never sent');
    }

    /** Approved minutes with no file behind them are equally unacknowledgeable. */
    public function test_approved_minutes_with_no_document_cannot_be_acknowledged(): void
    {
        $ob = $this->onboarding();
        // Distributable, but nothing on disk — the View button would 404.
        $this->meeting(PurchaseKickoffStatus::COMPLETED, PurchaseMomApprovalStatus::DISTRIBUTED);

        Sanctum::actingAs($this->vendor);

        $this->postJson("/api/portal/purchase/onboarding/{$ob->id}/kickoff/accept")
            ->assertStatus(422);

        $this->assertFalse((bool) $ob->fresh()->acknowledged);
    }

    /** And when the minutes really are issued, the step completes as before. */
    public function test_issued_minutes_can_be_read_and_acknowledged(): void
    {
        $ob = $this->onboarding();
        $this->withDocument(
            $this->meeting(PurchaseKickoffStatus::COMPLETED, PurchaseMomApprovalStatus::DISTRIBUTED)
        );

        Sanctum::actingAs($this->vendor);

        $this->get("/api/portal/purchase/onboarding/{$ob->id}/kickoff")->assertOk();
        $this->postJson("/api/portal/purchase/onboarding/{$ob->id}/kickoff/accept")->assertOk();

        $fresh = $ob->fresh();
        $this->assertTrue((bool) $fresh->acknowledged);
        $this->assertNotNull($fresh->acknowledged_at);
        $this->assertGreaterThanOrEqual(2, $fresh->current_step, 'Step 1 is done, so Step 2 is open');
    }

    /**
     * The download says what is actually wrong.
     *
     * It used to try to generate the document, hand a PurchaseVendor to a method
     * typed for a User, and report the resulting TypeError as "not available
     * yet" — the vendor was told the minutes did not exist when the real reason
     * was invisible.
     */
    public function test_an_unissued_document_is_refused_plainly_rather_than_generated(): void
    {
        $ob = $this->onboarding();
        $m = $this->meeting(PurchaseKickoffStatus::COMPLETED, PurchaseMomApprovalStatus::DRAFT);

        Sanctum::actingAs($this->vendor);

        $this->getJson("/api/portal/purchase/onboarding/{$ob->id}/kickoff")
            ->assertNotFound()
            ->assertJsonPath('message', 'The minutes for this meeting have not been issued yet.');

        $this->assertSame(0, $m->momDocuments()->count(),
            'a vendor pressing View must not mint the document they are being asked to accept');
    }
}
