<?php

namespace Tests\Feature\Shared;

use App\Models\Purchase\PurchaseKickoffMeeting;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Shared\KickoffMeeting;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A kick-off happens once, when a vendor first starts working with the team.
 *
 * Everything else in the catalogue recurs by design — weekly coordination,
 * progress review, technical, financial, HSE. Only this one is a beginning, and
 * a vendor can only begin once.
 *
 * The trap worth testing is not somebody deliberately making two. It is that
 * `kickoff` is the DEFAULT type, so a meeting meant to be a progress review
 * becomes a second kick-off by nobody touching the picker.
 */
class KickoffHappensOnceTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active',
        ])->save();
    }

    private function admin(): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'a-'.Str::random(6).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function vendor(): Vendor
    {
        return Vendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'AlphaCo',
            'email' => 'alpha-'.Str::random(4).'@vendor.local', 'status' => VendorStatus::ACTIVE,
        ]);
    }

    private function schedule(Vendor $v, ?string $type, string $title = 'Meeting')
    {
        return $this->postJson('/api/kickoff/meetings', array_filter([
            'subject_type' => 'vendor', 'subject_id' => $v->id,
            'title' => $title, 'meeting_type' => $type,
            'scheduled_at' => now()->addDay()->toDateTimeString(),
            'end_at' => now()->addDay()->addHour()->toDateTimeString(),
            'mode' => 'online',
        ], fn ($x) => $x !== null));
    }

    /* ── the rule ────────────────────────────────────────────────────── */

    public function test_a_second_kickoff_for_the_same_vendor_is_refused(): void
    {
        $admin = $this->admin();
        $vendor = $this->vendor();
        Sanctum::actingAs($admin);

        $this->schedule($vendor, 'kickoff', 'The kick-off')->assertSuccessful();
        $res = $this->schedule($vendor, 'kickoff', 'Another kick-off')->assertStatus(422);

        // Naming the one in the way, because "a kick-off already exists" leaves
        // whoever hit this with nowhere to go.
        $this->assertStringContainsString('already exists', $res->json('message'));
        $this->assertStringContainsString('kick-off is held once', $res->json('message'));

        $this->assertSame(1, KickoffMeeting::where('meeting_type', 'kickoff')->count());
    }

    /**
     * The default type is kickoff, so this is the realistic way two happen:
     * somebody schedules a follow-up and never touches the picker.
     */
    public function test_leaving_the_type_alone_on_a_second_meeting_is_refused(): void
    {
        $admin = $this->admin();
        $vendor = $this->vendor();
        Sanctum::actingAs($admin);

        $this->schedule($vendor, 'kickoff', 'The kick-off')->assertSuccessful();
        $this->schedule($vendor, null, 'Follow-up nobody re-typed')->assertStatus(422);
    }

    public function test_every_other_type_may_repeat(): void
    {
        $admin = $this->admin();
        $vendor = $this->vendor();
        Sanctum::actingAs($admin);

        $this->schedule($vendor, 'kickoff', 'The kick-off')->assertSuccessful();

        // The whole point of the catalogue: these recur.
        foreach (['weekly_coordination', 'progress_review', 'technical', 'financial'] as $type) {
            $this->schedule($vendor, $type, 'A '.$type)->assertSuccessful();
            $this->schedule($vendor, $type, 'Another '.$type)->assertSuccessful();
        }

        $this->assertSame(9, KickoffMeeting::count());
    }

    public function test_a_different_vendor_may_have_their_own_kickoff(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $this->schedule($this->vendor(), 'kickoff', 'A')->assertSuccessful();
        // The rule is one per vendor, not one per tenant.
        $this->schedule($this->vendor(), 'kickoff', 'B')->assertSuccessful();

        $this->assertSame(2, KickoffMeeting::where('meeting_type', 'kickoff')->count());
    }

    public function test_a_cancelled_kickoff_does_not_block_the_real_one(): void
    {
        $admin = $this->admin();
        $vendor = $this->vendor();
        Sanctum::actingAs($admin);

        $this->schedule($vendor, 'kickoff', 'Called off')->assertSuccessful();
        KickoffMeeting::first()->forceFill(['status' => 'Cancelled'])->save();

        // A kick-off that never happened must not block the one that does, or
        // the only way forward is deleting the history of the false start.
        $this->schedule($vendor, 'kickoff', 'The real one')->assertSuccessful();
    }

    /* ── the other way to end up with two ────────────────────────────── */

    public function test_turning_an_existing_meeting_into_a_second_kickoff_is_refused(): void
    {
        $admin = $this->admin();
        $vendor = $this->vendor();
        Sanctum::actingAs($admin);

        $this->schedule($vendor, 'kickoff', 'The kick-off')->assertSuccessful();
        $this->schedule($vendor, 'progress_review', 'A review')->assertSuccessful();

        $review = KickoffMeeting::where('meeting_type', 'progress_review')->sole();

        $this->putJson("/api/kickoff/meetings/{$review->id}", ['meeting_type' => 'kickoff'])
            ->assertStatus(422);

        $this->assertSame('progress_review', $review->fresh()->meeting_type);
    }

    public function test_the_only_kickoff_can_still_be_saved(): void
    {
        $admin = $this->admin();
        $vendor = $this->vendor();
        Sanctum::actingAs($admin);

        $this->schedule($vendor, 'kickoff', 'The kick-off')->assertSuccessful();
        $meeting = KickoffMeeting::sole();

        // It must not refuse its own existence.
        $this->putJson("/api/kickoff/meetings/{$meeting->id}", [
            'meeting_type' => 'kickoff', 'title' => 'Renamed',
        ])->assertSuccessful();

        $this->assertSame('Renamed', $meeting->fresh()->title);
    }

    /* ── the same rule on the Purchase engine ────────────────────────── */

    public function test_purchase_holds_the_same_rule(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $vendor = PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Southgate',
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => 'sg-'.Str::random(4).'@t.local', 'status' => 'Active', 'portal_status' => 'active',
        ]);

        $body = fn (string $t, ?string $type) => array_filter([
            'purchase_vendor_id' => $vendor->id, 'title' => $t, 'meeting_type' => $type,
            'scheduled_at' => now()->addDay()->toDateTimeString(),
            'end_at' => now()->addDay()->addHour()->toDateTimeString(),
            'mode' => 'online',
        ], fn ($x) => $x !== null);

        $this->postJson('/api/purchase/kickoff', $body('The kick-off', 'kickoff'))->assertSuccessful();
        $this->postJson('/api/purchase/kickoff', $body('Another', 'kickoff'))->assertStatus(422);

        // And the recurring types still recur here too.
        $this->postJson('/api/purchase/kickoff', $body('Review one', 'progress_review'))->assertSuccessful();
        $this->postJson('/api/purchase/kickoff', $body('Review two', 'progress_review'))->assertSuccessful();

        $this->assertSame(1, PurchaseKickoffMeeting::where('meeting_type', 'kickoff')->count());
    }

    /* ── the type named in the spec but missing from the catalogue ───── */

    public function test_financial_is_a_selectable_type_on_both_engines(): void
    {
        $this->assertArrayHasKey('financial', config('meetings.types'));
        $this->assertArrayHasKey('financial', config('purchase_meetings.types'));
    }
}
