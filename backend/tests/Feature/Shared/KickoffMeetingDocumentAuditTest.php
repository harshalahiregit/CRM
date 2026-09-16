<?php

namespace Tests\Feature\Shared;

use App\Models\Purchase\PurchaseKickoffDocument;
use App\Models\Purchase\PurchaseKickoffMeeting;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Shared\KickoffMeeting;
use App\Models\Shared\KickoffMeetingDocument;
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
 * A meeting's supporting documents, audited from the vendor's side.
 *
 * These are the least-guarded thing hanging off a meeting: a file on disk behind
 * a numeric id. Three ways in matter, and each is asked here rather than assumed:
 * before the minutes are approved, from another vendor's account, and by pairing
 * a real document id with a different meeting's id in the URL.
 */
class KickoffMeetingDocumentAuditTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('kickoff_docs');
        Storage::fake('purchase_kickoff_docs');

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();
    }

    private function vendorWithUser(string $name): Vendor
    {
        $user = User::create([
            'tenant_id' => self::TENANT, 'name' => $name, 'role' => 'third_party_vendor',
            'email' => Str::slug($name).'-'.Str::random(4).'@t.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);
        $vendor = Vendor::create([
            'tenant_id' => self::TENANT, 'company_name' => $name, 'status' => VendorStatus::ACTIVE,
        ]);
        $vendor->forceFill(['user_id' => $user->id])->save();

        return $vendor->fresh();
    }

    private function meetingFor(Vendor $vendor, string $momStatus): KickoffMeeting
    {
        return KickoffMeeting::create([
            'tenant_id' => self::TENANT, 'kickoffable_type' => Vendor::class,
            'kickoffable_id' => $vendor->id, 'title' => 'Kickoff',
            'meeting_type' => 'kickoff', 'status' => KickoffStatus::COMPLETED,
            'scheduled_at' => now()->subDay()->format('Y-m-d H:i:s'),
            'duration_minutes' => 60, 'mom_status' => $momStatus,
        ]);
    }

    private function documentOn(KickoffMeeting $meeting): KickoffMeetingDocument
    {
        Storage::disk('kickoff_docs')->put("m{$meeting->id}.pdf", 'evidence');

        return KickoffMeetingDocument::create([
            'tenant_id' => self::TENANT,
            'kickoff_meeting_id' => $meeting->id,
            'label' => 'Site layout',
            'original_name' => 'layout.pdf',
            'path' => "m{$meeting->id}.pdf",
        ]);
    }

    public function test_a_document_is_withheld_until_the_minutes_are_distributed(): void
    {
        $vendor  = $this->vendorWithUser('Acme');
        $meeting = $this->meetingFor($vendor, MomApprovalStatus::DRAFT);
        $doc     = $this->documentOn($meeting);

        Sanctum::actingAs(User::find($vendor->user_id));

        $this->assertSame(403,
            $this->get("/api/portal/meetings/{$meeting->id}/documents/{$doc->id}/download")->getStatusCode(),
            'a document must not be downloadable before the minutes are approved');
    }

    public function test_a_document_is_downloadable_once_the_minutes_are_distributed(): void
    {
        $vendor  = $this->vendorWithUser('Acme');
        $meeting = $this->meetingFor($vendor, MomApprovalStatus::DISTRIBUTED);
        $doc     = $this->documentOn($meeting);

        Sanctum::actingAs(User::find($vendor->user_id));

        $this->get("/api/portal/meetings/{$meeting->id}/documents/{$doc->id}/download")
            ->assertOk();
    }

    public function test_a_vendor_cannot_download_another_vendors_document(): void
    {
        $mine   = $this->vendorWithUser('Acme');
        $rival  = $this->vendorWithUser('Rival');
        $theirs = $this->meetingFor($rival, MomApprovalStatus::DISTRIBUTED);
        $doc    = $this->documentOn($theirs);

        Sanctum::actingAs(User::find($mine->user_id));

        $this->assertSame(404,
            $this->get("/api/portal/meetings/{$theirs->id}/documents/{$doc->id}/download")->getStatusCode(),
            'one vendor must never reach another\'s meeting documents');
    }

    /**
     * The id-swap: a document the caller IS allowed to see, requested through a
     * meeting id they are also allowed to see — but the two do not belong
     * together. Checking only "do you own the meeting" would let this through.
     */
    public function test_a_document_cannot_be_pulled_through_a_different_meeting(): void
    {
        $vendor = $this->vendorWithUser('Acme');
        $one    = $this->meetingFor($vendor, MomApprovalStatus::DISTRIBUTED);
        $two    = $this->meetingFor($vendor, MomApprovalStatus::DISTRIBUTED);
        $docOnTwo = $this->documentOn($two);

        Sanctum::actingAs(User::find($vendor->user_id));

        $status = $this->get("/api/portal/meetings/{$one->id}/documents/{$docOnTwo->id}/download")->getStatusCode();

        $this->assertSame(404, $status,
            "a document from meeting {$two->id} was served through meeting {$one->id} ({$status})");
    }

    /* ── the same three questions of the Purchase engine ─────────────────── */

    private function purchaseVendor(string $name): PurchaseVendor
    {
        return PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => $name,
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => Str::slug($name).'-'.Str::random(4).'@t.local',
            'status' => 'Active', 'portal_status' => 'active',
        ]);
    }

    private function purchaseMeetingFor(PurchaseVendor $vendor, string $momStatus): PurchaseKickoffMeeting
    {
        return PurchaseKickoffMeeting::create([
            'tenant_id' => self::TENANT, 'purchase_vendor_id' => $vendor->id,
            'title' => 'Kickoff', 'meeting_type' => 'kickoff',
            'status' => PurchaseKickoffStatus::COMPLETED,
            'scheduled_at' => now()->subDay()->format('Y-m-d H:i:s'),
            'duration_minutes' => 60, 'mom_status' => $momStatus,
        ]);
    }

    private function purchaseDocumentOn(PurchaseKickoffMeeting $meeting): PurchaseKickoffDocument
    {
        Storage::disk('purchase_kickoff_docs')->put("m{$meeting->id}.pdf", 'evidence');

        return PurchaseKickoffDocument::create([
            'tenant_id' => self::TENANT,
            'purchase_kickoff_meeting_id' => $meeting->id,
            'label' => 'Site layout',
            'original_name' => 'layout.pdf',
            'path' => "m{$meeting->id}.pdf",
        ]);
    }

    public function test_purchase_documents_are_withheld_until_distributed(): void
    {
        $vendor  = $this->purchaseVendor('Southgate');
        $meeting = $this->purchaseMeetingFor($vendor, PurchaseMomApprovalStatus::DRAFT);
        $doc     = $this->purchaseDocumentOn($meeting);

        Sanctum::actingAs($vendor);

        $this->assertSame(403,
            $this->get("/api/portal/purchase/meetings/{$meeting->id}/documents/{$doc->id}/download")->getStatusCode());
    }

    public function test_a_purchase_vendor_cannot_download_another_vendors_document(): void
    {
        $mine   = $this->purchaseVendor('Southgate');
        $rival  = $this->purchaseVendor('Rival');
        $theirs = $this->purchaseMeetingFor($rival, PurchaseMomApprovalStatus::DISTRIBUTED);
        $doc    = $this->purchaseDocumentOn($theirs);

        Sanctum::actingAs($mine);

        $this->assertSame(404,
            $this->get("/api/portal/purchase/meetings/{$theirs->id}/documents/{$doc->id}/download")->getStatusCode());
    }

    public function test_a_purchase_document_cannot_be_pulled_through_a_different_meeting(): void
    {
        $vendor   = $this->purchaseVendor('Southgate');
        $one      = $this->purchaseMeetingFor($vendor, PurchaseMomApprovalStatus::DISTRIBUTED);
        $two      = $this->purchaseMeetingFor($vendor, PurchaseMomApprovalStatus::DISTRIBUTED);
        $docOnTwo = $this->purchaseDocumentOn($two);

        Sanctum::actingAs($vendor);

        $this->assertSame(404,
            $this->get("/api/portal/purchase/meetings/{$one->id}/documents/{$docOnTwo->id}/download")->getStatusCode(),
            'a document must belong to the meeting it is requested through');
    }
}
