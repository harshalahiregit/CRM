<?php

namespace Tests\Feature\Tpv;

use App\Models\Shared\KickoffMeeting;
use App\Models\Shared\KickoffMeetingSubject;
use App\Models\Tenant;
use App\Models\Tpv\TpvOnboarding;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Services\Tpv\TpvOnboardingService;
use App\Support\Tpv\TpvOnboardingStatus as Status;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The two rules that appeared when the six-step wizard moved into the vendor
 * portal, where the vendor drives it instead of staff.
 *
 * 1. A vendor may not skip ahead. setStep() used to accept any step 1..6, which
 *    was harmless while only staff could reach it and is not once the party being
 *    onboarded is the one clicking. Staff stay exempt: step 3 is "complete" only
 *    when EVERY required document is uploaded, so gating them would stop a
 *    reviewer opening step 4 to review the documents that have arrived.
 *
 * 2. Only the designated signatory signs the minutes. Every vendor on a
 *    multi-vendor kickoff can now resolve the meeting — that is what makes the MOM
 *    visible to them — so without a check a secondary reading its own Step 1 would
 *    put its name on the meeting's single signature line.
 */
class PortalOnboardingFlowTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('kickoff_docs');

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'Tenant 1', 'slug' => 'tenant-1',
            'subdomain' => 'tenant1', 'status' => 'active',
        ])->save();
    }

    private function user(string $role): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => ucfirst($role), 'role' => $role,
            'email' => $role.'-'.Str::random(6).'@test.local',
            'password' => bcrypt('secret'), 'status' => 'active',
        ]);
    }

    private function vendor(string $name): Vendor
    {
        return Vendor::create([
            'tenant_id' => self::TENANT, 'company_name' => $name,
            'email' => strtolower($name).'@test.local', 'status' => 'Active',
        ]);
    }

    private function onboardingFor(Vendor $v): TpvOnboarding
    {
        return TpvOnboarding::create([
            'tenant_id' => self::TENANT, 'vendor_id' => $v->id,
            'current_step' => 1, 'status' => Status::IN_PROGRESS,
        ]);
    }

    /** A completed meeting with a stored MOM; $vendors[0] is the signatory. */
    private function meetingFor(array $vendors): KickoffMeeting
    {
        $path = 'tenant-1/meeting-x/mom-'.Str::random(8).'.pdf';
        Storage::disk('kickoff_docs')->put($path, '%PDF-TEST');

        $meeting = KickoffMeeting::create([
            'tenant_id' => self::TENANT, 'kickoffable_type' => Vendor::class,
            'kickoffable_id' => $vendors[0]->id, 'title' => 'Kickoff', 'status' => 'Completed',
            'scheduled_at' => now()->subDay(), 'completed_at' => now(), 'mom_path' => $path,
        ]);

        foreach ($vendors as $i => $v) {
            KickoffMeetingSubject::create([
                'tenant_id' => self::TENANT, 'kickoff_meeting_id' => $meeting->id,
                'subject_type' => Vendor::class, 'subject_id' => $v->id,
                'is_primary' => $i === 0, 'ack_token' => Str::random(48),
            ]);
        }

        return $meeting;
    }

    public function test_vendor_cannot_skip_past_the_first_incomplete_step(): void
    {
        $ob     = $this->onboardingFor($this->vendor('SkipCo'));
        $vendor = $this->user('third_party_vendor');
        $svc    = app(TpvOnboardingService::class);

        // Step 1 is not acknowledged, so 2..6 are all out of reach.
        foreach ([2, 3, 5, 6] as $step) {
            try {
                $svc->setStep($ob, $step, $vendor);
                $this->fail("Step {$step} should have been refused.");
            } catch (\App\Exceptions\BusinessException $e) {
                $this->assertStringContainsString('Complete step 1', $e->getMessage());
            }
        }

        // Step 1 itself is always reachable.
        $this->assertSame(1, $svc->setStep($ob, 1, $vendor)->current_step);
    }

    /** Finishing a step opens exactly the next one, and no further. */
    public function test_completing_a_step_opens_the_next_one_only(): void
    {
        $vendorCo = $this->vendor('StepCo');
        $ob       = $this->onboardingFor($vendorCo);
        $vendor   = $this->user('third_party_vendor');
        $svc      = app(TpvOnboardingService::class);

        $ob->update(['acknowledged' => true]);          // step 1 done
        $this->assertSame(2, $svc->setStep($ob, 2, $vendor)->current_step);

        // Step 2 is still incomplete (no profile), so 3 stays shut.
        $this->expectException(\App\Exceptions\BusinessException::class);
        $svc->setStep($ob, 3, $vendor);
    }

    /** Staff must keep free navigation, or document review becomes unreachable. */
    public function test_staff_are_not_step_gated(): void
    {
        $ob  = $this->onboardingFor($this->vendor('AdminNavCo'));
        $svc = app(TpvOnboardingService::class);

        foreach (['admin', 'staff'] as $role) {
            $this->assertSame(4, $svc->setStep($ob, 4, $this->user($role))->current_step);
        }
    }

    /** The secondary records receipt on its OWN onboarding, never on the minutes. */
    public function test_secondary_vendor_does_not_sign_the_minutes(): void
    {
        $primary   = $this->vendor('PrimaryCo');
        $secondary = $this->vendor('SecondaryCo');
        $meeting   = $this->meetingFor([$primary, $secondary]);

        $secondaryOb = $this->onboardingFor($secondary);
        $svc         = app(TpvOnboardingService::class);

        $svc->acknowledgeKickoff($secondaryOb, $this->user('third_party_vendor'), ['ip' => '10.0.0.1']);

        $this->assertNull(
            $meeting->fresh()->acknowledged_at,
            'A secondary vendor must not sign the meeting minutes.'
        );
        // ...but it is not left stranded on Step 1 either.
        $this->assertTrue((bool) $secondaryOb->fresh()->acknowledged);
    }

    public function test_designated_signatory_does_sign_the_minutes(): void
    {
        $primary   = $this->vendor('SignerCo');
        $secondary = $this->vendor('OtherCo');
        $meeting   = $this->meetingFor([$primary, $secondary]);

        $primaryOb = $this->onboardingFor($primary);

        app(TpvOnboardingService::class)
            ->acknowledgeKickoff($primaryOb, $this->user('third_party_vendor'), ['ip' => '10.0.0.2']);

        $this->assertNotNull($meeting->fresh()->acknowledged_at);
        $this->assertTrue((bool) $primaryOb->fresh()->acknowledged);
    }

    /** A single-vendor meeting predating the pivot still lets its vendor sign. */
    public function test_legacy_single_vendor_meeting_still_signs(): void
    {
        $only = $this->vendor('SoloCo');
        $path = 'tenant-1/meeting-y/mom-'.Str::random(8).'.pdf';
        Storage::disk('kickoff_docs')->put($path, '%PDF-TEST');

        $meeting = KickoffMeeting::create([
            'tenant_id' => self::TENANT, 'kickoffable_type' => Vendor::class,
            'kickoffable_id' => $only->id, 'title' => 'Kickoff', 'status' => 'Completed',
            'scheduled_at' => now()->subDay(), 'completed_at' => now(), 'mom_path' => $path,
        ]);

        $ob = $this->onboardingFor($only);
        $ob->update(['kickoff_meeting_id' => $meeting->id]);

        app(TpvOnboardingService::class)
            ->acknowledgeKickoff($ob, $this->user('third_party_vendor'), []);

        $this->assertNotNull($meeting->fresh()->acknowledged_at);
    }
}
