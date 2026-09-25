<?php

namespace Tests\Feature\Shared;

use App\Models\Shared\KickoffMeeting;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The minutes have to say when people were actually there.
 *
 * The PDF's attendance table printed a status and the organiser's verdict and
 * nothing else, so the one question a set of minutes is asked most often —
 * "what time did they come in, what time did they leave, how long were they
 * there?" — had no answer on the document.
 *
 * The MOM E-MAIL was further behind still: its column read
 * `attended ? 'Present' : 'Absent'`, the legacy boolean. So a meeting nobody
 * had marked yet e-mailed the vendor a table asserting every one of their
 * people missed it, and Late / Excused / Online / Offline all collapsed into
 * two words. The mail and the document now say the same thing.
 */
class MomCarriesAttendanceTimesTest extends TestCase
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

    /** A meeting whose register has been filled in by an admin. */
    private function meetingWithRegister(): KickoffMeeting
    {
        $admin = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Anita Rao', 'role' => 'admin',
            'email' => 'anita-'.Str::random(5).'@sangoe.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
        $vendor = Vendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'AlphaCo',
            'email' => 'alpha-'.Str::random(5).'@vendor.local', 'status' => VendorStatus::ACTIVE,
        ]);

        $meeting = KickoffMeeting::create([
            'tenant_id' => self::TENANT, 'created_by' => $admin->id,
            'kickoffable_type' => Vendor::class, 'kickoffable_id' => $vendor->id,
            'title' => 'Kickoff — AlphaCo', 'status' => 'Completed', 'mode' => 'online',
            'scheduled_at' => now()->subDay(), 'minutes' => 'Everything was agreed.',
        ]);

        $meeting->attendees()->create([
            'tenant_id' => self::TENANT, 'name' => 'Ravi Kumar', 'role' => 'Site Engineer',
            'organisation' => 'AlphaCo', 'side' => 'external',
            'attendance_status' => 'Late', 'attended' => true,
            'in_at' => now()->subDay()->setTime(10, 15),
            'out_at' => now()->subDay()->setTime(11, 45),
            'marked_by' => $admin->id, 'marked_at' => now(),
        ]);

        // Nobody has marked this one. It must not print as Absent — the
        // document would be asserting somebody missed a meeting nobody has
        // reviewed.
        $meeting->attendees()->create([
            'tenant_id' => self::TENANT, 'name' => 'Sunita Rao', 'role' => 'QA',
            'organisation' => 'AlphaCo', 'side' => 'external',
        ]);

        return $meeting->fresh(['attendees', 'momItems']);
    }

    public function test_the_mom_pdf_prints_status_times_duration_and_who_marked_it(): void
    {
        $meeting = $this->meetingWithRegister();

        $html = view('pdf.kickoff_mom', [
            'meeting' => $meeting,
            'tenant' => Tenant::find(self::TENANT),
            'projectName' => null,
            'subjectNames' => ['AlphaCo'],
            'subjectName' => 'AlphaCo',
            'generatedBy' => 'Anita Rao',
            'generatedAt' => now(),
        ])->render();

        $this->assertStringContainsString('In / Out', $html, 'the table has a times column');
        $this->assertStringContainsString('10:15–11:45', $html);
        $this->assertStringContainsString('90 min', $html, 'the duration, computed from the typed window');
        $this->assertStringContainsString('Marked by Anita Rao', $html);
        $this->assertStringContainsString('Late', $html, 'the recorded state, not the boolean projection');
        $this->assertStringContainsString('Not marked', $html, 'and an unmarked person is not called absent');
    }

    public function test_the_mom_email_says_the_same_thing_as_the_pdf(): void
    {
        $meeting = $this->meetingWithRegister();

        $html = view('emails.shared.kickoff_mom', [
            'meeting' => $meeting,
            'meetingDate' => $meeting->scheduled_at->format('d M Y'),
            'recipientName' => 'AlphaCo',
            'details' => ['Meeting' => $meeting->title],
            'attendees' => $meeting->attendees,
            'presentCount' => 1,
            'momItems' => collect(),
            'ackUrl' => null,
            'momUrl' => null,
            'deadline' => null,
            'windowHours' => 48,
            'companyName' => 'Sangoe',
            'logoUrl' => null,
        ])->render();

        $this->assertStringContainsString('In / Out', $html);
        $this->assertStringContainsString('10:15', $html);
        $this->assertStringContainsString('11:45', $html);
        $this->assertStringContainsString('90 min', $html);
        $this->assertStringContainsString('Marked by Anita Rao', $html);

        // The legacy boolean is gone: Late is Late, and an unmarked person is
        // not asserted to have missed the meeting.
        $this->assertStringContainsString('Late', $html);
        $this->assertStringContainsString('Not marked', $html);
    }

    public function test_the_purchase_mom_pdf_carries_the_same_columns(): void
    {
        // The Purchase document is the shared one's twin; a column added to one
        // and not the other is how these two rosters drifted before.
        $source = file_get_contents(resource_path('views/pdf/purchase_kickoff_mom.blade.php'));

        $this->assertStringContainsString('In / Out', $source);
        $this->assertStringContainsString('attendance_minutes', $source);
        $this->assertStringContainsString('markedNote', $source);
    }
}
