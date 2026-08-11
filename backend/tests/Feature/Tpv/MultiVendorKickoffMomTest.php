<?php

namespace Tests\Feature\Tpv;

use App\Models\Shared\KickoffMeeting;
use App\Models\Shared\KickoffMeetingSubject;
use App\Models\Tenant;
use App\Models\Tpv\TpvOnboarding;
use App\Models\Vendor\Vendor;
use App\Services\Tpv\KickoffPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A multi-vendor kickoff names ONE vendor on kickoffable_type/kickoffable_id;
 * every other vendor exists only as a row in kickoff_meeting_subjects.
 *
 * KickoffPdfService::findKickoffMeeting() matched the two columns and never the
 * pivot, so Portal Step 1 told each secondary vendor "Kickoff meeting is not
 * completed yet" while the primary read the same minutes without trouble. The
 * failure was silent — the portal renders that as an ordinary empty state.
 *
 * Both directions are pinned: the secondary must now resolve, and the primary's
 * existing fast path (onboarding.kickoff_meeting_id) must keep winning.
 */
class MultiVendorKickoffMomTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'Tenant 1', 'slug' => 'tenant-1',
            'subdomain' => 'tenant1', 'status' => 'active',
        ])->save();
    }

    private function vendor(string $name): Vendor
    {
        return Vendor::create([
            'tenant_id' => self::TENANT, 'company_name' => $name,
            'email' => strtolower($name).'@test.local', 'status' => 'Active',
        ]);
    }

    private function onboardingFor(Vendor $v, ?int $meetingId = null): TpvOnboarding
    {
        return TpvOnboarding::create([
            'tenant_id' => self::TENANT, 'vendor_id' => $v->id,
            'kickoff_meeting_id' => $meetingId, 'current_step' => 1, 'status' => 'In Progress',
        ]);
    }

    /** A completed meeting with a MOM, primary $a plus every vendor in $extra. */
    private function meetingWith(Vendor $a, array $extra = []): KickoffMeeting
    {
        $meeting = KickoffMeeting::create([
            'tenant_id' => self::TENANT, 'kickoffable_type' => Vendor::class,
            'kickoffable_id' => $a->id, 'title' => 'Kickoff', 'status' => 'Completed',
            'scheduled_at' => now()->subDay(), 'completed_at' => now(),
            'mom_path' => 'tenant-1/meeting-x/mom-shared.pdf',
        ]);

        foreach (array_merge([$a], $extra) as $i => $v) {
            KickoffMeetingSubject::create([
                'tenant_id' => self::TENANT, 'kickoff_meeting_id' => $meeting->id,
                'subject_type' => Vendor::class, 'subject_id' => $v->id,
                'is_primary' => $i === 0,
            ]);
        }

        return $meeting;
    }

    public function test_secondary_vendors_resolve_the_same_mom_as_the_primary(): void
    {
        $primary = $this->vendor('PrimaryCo');
        $second  = $this->vendor('SecondCo');
        $third   = $this->vendor('ThirdCo');

        $meeting = $this->meetingWith($primary, [$second, $third]);

        // Only the primary gets the back-pointer, exactly as syncOnboardingPointer
        // writes it — the secondaries must resolve through the pivot alone.
        $obPrimary = $this->onboardingFor($primary, $meeting->id);
        $obSecond  = $this->onboardingFor($second);
        $obThird   = $this->onboardingFor($third);

        $svc = app(KickoffPdfService::class);

        foreach (['primary' => $obPrimary, 'second' => $obSecond, 'third' => $obThird] as $label => $ob) {
            $found = $svc->findKickoffMeeting($ob);

            $this->assertNotNull($found, "The {$label} vendor must resolve the kickoff meeting.");
            $this->assertSame($meeting->id, $found->id);
            // Same stored file — the fix must not imply a per-vendor copy.
            $this->assertSame($meeting->mom_path, $found->mom_path);
        }
    }

    /** The pre-existing single-vendor path must be untouched. */
    public function test_primary_only_meeting_still_resolves(): void
    {
        $only    = $this->vendor('SoloCo');
        $meeting = $this->meetingWith($only);
        $ob      = $this->onboardingFor($only, $meeting->id);

        $found = app(KickoffPdfService::class)->findKickoffMeeting($ob);

        $this->assertNotNull($found);
        $this->assertSame($meeting->id, $found->id);
    }

    /** A vendor on no meeting must still resolve nothing — the fix widens, not opens. */
    public function test_unrelated_vendor_gets_nothing(): void
    {
        $onMeeting  = $this->vendor('OnMeetingCo');
        $unrelated  = $this->vendor('UnrelatedCo');
        $this->meetingWith($onMeeting);

        $this->assertNull(
            app(KickoffPdfService::class)->findKickoffMeeting($this->onboardingFor($unrelated)),
            'A vendor absent from the meeting must not inherit its MOM.'
        );
    }

    /** Tenant isolation still holds through the new pivot branch. */
    public function test_pivot_branch_stays_tenant_scoped(): void
    {
        (new Tenant())->forceFill([
            'id' => 999, 'name' => 'Other', 'slug' => 'other', 'subdomain' => 'other', 'status' => 'active',
        ])->save();

        $primary = $this->vendor('HostCo');
        $foreign = Vendor::create([
            'tenant_id' => 999, 'company_name' => 'ForeignCo',
            'email' => 'foreign@test.local', 'status' => 'Active',
        ]);

        $meeting = $this->meetingWith($primary);
        // A pivot row pointing at a vendor in another tenant must not leak the MOM.
        KickoffMeetingSubject::create([
            'tenant_id' => self::TENANT, 'kickoff_meeting_id' => $meeting->id,
            'subject_type' => Vendor::class, 'subject_id' => $foreign->id, 'is_primary' => false,
        ]);

        $foreignOb = TpvOnboarding::create([
            'tenant_id' => 999, 'vendor_id' => $foreign->id,
            'current_step' => 1, 'status' => 'In Progress',
        ]);

        $this->assertNull(
            app(KickoffPdfService::class)->findKickoffMeeting($foreignOb),
            'forTenant() must still bound the search after the pivot branch was added.'
        );
    }
}
