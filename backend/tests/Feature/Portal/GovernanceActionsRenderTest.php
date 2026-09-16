<?php

namespace Tests\Feature\Portal;

use App\Models\Purchase\PurchaseKickoffMeeting;
use App\Models\Purchase\PurchaseMomActionItem;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Tenant;
use App\Support\Purchase\PurchaseVendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A governance action item tells the vendor what to do — in words.
 *
 * Action items are written by staff in a rich editor, so `description` holds
 * HTML, and a pasted screenshot rides along as a base64 `data:image` src. The
 * portal handed the model straight to a screen that renders text, so the vendor
 * read this as their instruction:
 *
 *   ACT-2026-0021 · <span style="font-size: x-large;">Hiiii Guysss</span>
 *   <img src="data:image/jpeg;base64,/9j/4AAQSkZJRgABAQAAAQABAAD/…
 *
 * — several thousand characters of base64 in a heading, and the actual
 * instruction lost inside it. Same defect as the ticket thread, same fix: the
 * server sends a sanitized `*_html` twin to render and a plain-text twin to
 * read, and it does so on the way OUT, so rows already written display
 * correctly with no backfill.
 */
class GovernanceActionsRenderTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    /** A 1×1 JPEG, standing in for the screenshot someone pasted. */
    private const PASTED_IMAGE = 'data:image/jpeg;base64,/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAA==';

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();
    }

    private function vendorWithAction(string $description, ?string $remark = null): array
    {
        $vendor = PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Bolt Supplies',
            'purchase_vendor_code' => 'PV-'.Str::random(8),
            'status' => PurchaseVendorStatus::ACTIVE, 'portal_status' => 'active',
            'email' => 'bolt@vendor.test',
        ]);

        $meeting = PurchaseKickoffMeeting::create([
            'tenant_id' => self::TENANT, 'purchase_vendor_id' => $vendor->id,
            'title' => 'Kickoff', 'status' => 'Completed',
            'scheduled_at' => now()->subDay()->format('Y-m-d H:i:s'),
        ]);

        $action = PurchaseMomActionItem::create([
            'tenant_id' => self::TENANT,
            'purchase_kickoff_meeting_id' => $meeting->id,
            'action_ref' => 'ACT-2026-0021',
            'description' => $description,
            'remark' => $remark,
            'status' => 'Open', 'priority' => 'Medium',
        ]);

        return [$vendor, $action];
    }

    /** The body from the report, near enough verbatim. */
    public function test_a_rich_action_item_reaches_the_vendor_as_words(): void
    {
        [$vendor] = $this->vendorWithAction(
            '<span style="font-size: x-large;">Hiiii Guysss</span>'
            .'<img src="'.self::PASTED_IMAGE.'" alt="" style="width:33%"><p><br></p>'
        );

        Sanctum::actingAs($vendor);
        $row = $this->getJson('/api/portal/purchase/actions')->assertOk()->json('data.0');

        // The readable instruction, with no markup and no base64 anywhere in it.
        $this->assertSame('Hiiii Guysss', $row['description']);
        $this->assertStringNotContainsString('base64', $row['description']);
        $this->assertStringNotContainsString('<', $row['description']);

        // And the renderable twin keeps both the text and the pasted image.
        $this->assertStringContainsString('Hiiii Guysss', $row['description_html']);
        $this->assertStringContainsString('data:image/jpeg;base64,', $row['description_html'],
            'a pasted screenshot is content — it must survive sanitization');
    }

    /**
     * Script does not survive, even though the image does.
     *
     * The allowlist is what separates the two, and it is worth stating: an
     * action item is authored by staff but rendered in a vendor's browser.
     */
    public function test_script_in_an_action_item_never_reaches_the_vendor(): void
    {
        [$vendor] = $this->vendorWithAction('<p>Do the thing</p><script>alert(1)</script>');

        Sanctum::actingAs($vendor);
        $row = $this->getJson('/api/portal/purchase/actions')->assertOk()->json('data.0');

        $this->assertStringNotContainsString('<script', $row['description_html']);
        $this->assertStringNotContainsString('alert(1)', $row['description_html']);
        $this->assertStringContainsString('Do the thing', $row['description_html']);
    }

    /** Plain text stays plain, and a vendor's own progress note is plain text. */
    public function test_plain_text_is_left_readable(): void
    {
        [$vendor, $action] = $this->vendorWithAction('Fix the gate register');

        Sanctum::actingAs($vendor);
        $this->postJson("/api/portal/purchase/actions/{$action->id}/respond", [
            'note' => 'Done on 3 Sep — a < b test',
        ])->assertOk();

        $row = $this->getJson('/api/portal/purchase/actions')->assertOk()->json('data.0');

        $this->assertSame('Fix the gate register', $row['description']);
        $this->assertStringContainsString('Fix the gate register', $row['description_html']);
        // The vendor's note comes back escaped, so `<` renders as a character.
        $this->assertStringContainsString('a &lt; b', $row['remark_html']);
    }
}
