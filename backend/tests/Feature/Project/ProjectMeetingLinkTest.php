<?php

namespace Tests\Feature\Project;

use App\Models\Project\Project;
use App\Models\Shared\MeetingDistribution;
use App\Models\Tenant;
use App\Models\TenantMailSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A project meeting's link reaches the people in it.
 *
 * This is the third meeting implementation in the system and much the simplest:
 * one row, a typed-in link, and `participants` as a free-text column somebody
 * filled by tagging colleagues from a menu. It had no roster, no register and
 * no send at all — the link sat on the row and whoever remembered to look found
 * it.
 *
 * It now uses the same MeetingLinkAnnouncer as both kickoff engines, so:
 *   - saving a link mails it to everybody tagged, resolving each typed name
 *     against this tenant's users;
 *   - a name that resolves to nobody is reported BY NAME, not dropped;
 *   - an instant-start URL cannot be saved at all, because mailing it would put
 *     each recipient in an empty meeting of their own;
 *   - it goes through the tenant's own SMTP, and a tenant with none is told so.
 */
class ProjectMeetingLinkTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private const ROOM = 'https://meet.google.com/pqr-stuv-wxy';

    private User $admin;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active',
        ])->save();

        $this->admin = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Super Admin', 'role' => 'admin',
            'email' => 'admin-'.Str::random(6).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);

        $this->project = Project::create([
            'tenant_id' => self::TENANT, 'name' => 'Warehouse fit-out',
            'start_date' => now()->toDateString(), 'created_by' => $this->admin->id,
        ]);

        Sanctum::actingAs($this->admin);
    }

    private function smtp(): void
    {
        TenantMailSetting::create([
            'tenant_id' => self::TENANT, 'host' => 'smtp.sangoe.local', 'port' => 587,
            'username' => 'mailer', 'password' => 'secret', 'encryption' => 'tls',
            'from_name' => 'Sangoe', 'from_email' => 'no-reply@sangoe.local',
            'enabled' => true, 'verify_peer' => true,
        ]);
    }

    private function captureMail(): \ArrayObject
    {
        $sent = new \ArrayObject();

        $this->instance(\App\Services\Mail\TenantMailer::class, new class($sent) extends \App\Services\Mail\TenantMailer
        {
            public function __construct(private \ArrayObject $sent) {}

            public function sendRawHtml(?int $tenantId, string|array $to, string $subject, string $html, ?string $text = null, array $attachments = [], ?string $replyTo = null): void
            {
                $this->sent[] = ['to' => is_array($to) ? implode(',', $to) : $to, 'html' => $html, 'text' => (string) $text];
            }
        });

        return $sent;
    }

    public function test_saving_a_link_mails_it_to_everyone_tagged_on_the_meeting(): void
    {
        $this->smtp();
        $colleague = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Ravi Kumar', 'role' => 'staff',
            'email' => 'ravi@sangoe.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);

        $sent = $this->captureMail();

        // The tag menu writes names, comma-separated. They are resolved against
        // this tenant's users, which is where the address actually lives.
        $res = $this->postJson("/api/projects/{$this->project->id}/meetings", [
            'title' => 'Weekly site review', 'mode' => 'online',
            'meeting_link' => self::ROOM,
            'participants' => 'Ravi Kumar, someone@outside.local',
            'planned_date' => now()->addDay()->toDateTimeString(),
        ])->assertStatus(201);

        $this->assertTrue($res->json('data.notified.smtp_ready'));
        $this->assertSame(3, $res->json('data.notified.recipients'), 'two tagged plus the organiser');
        $this->assertSame(3, $res->json('data.notified.reachable'));

        $meeting = \App\Models\Project\ProjectMeeting::first();
        app(\App\Services\Shared\MeetingLinkAnnouncer::class)->announce($meeting, $this->admin);

        $to = array_map(fn ($m) => strtolower($m['to']), iterator_to_array($sent));
        $this->assertContains(strtolower($colleague->email), $to, 'resolved from a typed name');
        $this->assertContains('someone@outside.local', $to, 'a typed address is used as it stands');
        $this->assertContains(strtolower($this->admin->email), $to);

        foreach ($sent as $mail) {
            $this->assertStringContainsString(self::ROOM, $mail['html']);
        }

        $this->assertSame(MeetingDistribution::ENGINE_PROJECT,
            MeetingDistribution::where('kind', MeetingDistribution::KIND_LINK)->first()->engine);
    }

    public function test_a_name_that_matches_nobody_is_reported_not_dropped(): void
    {
        $this->smtp();
        $this->captureMail();

        $res = $this->postJson("/api/projects/{$this->project->id}/meetings", [
            'title' => 'Weekly site review', 'mode' => 'online',
            'meeting_link' => self::ROOM,
            'participants' => 'A Contractor Nobody Added',
        ])->assertStatus(201);

        $names = array_column($res->json('data.notified.unreachable'), 'name');
        $this->assertContains('A Contractor Nobody Added', $names);
    }

    public function test_an_instant_start_url_cannot_be_saved_on_a_project_meeting(): void
    {
        $this->postJson("/api/projects/{$this->project->id}/meetings", [
            'title' => 'Weekly site review', 'mode' => 'online',
            'meeting_link' => 'https://meet.google.com/new',
        ])->assertStatus(422);

        $this->postJson("/api/projects/{$this->project->id}/meetings", [
            'title' => 'Weekly site review', 'mode' => 'online',
            'meeting_link' => 'meet.google.com/pqr-stuv-wxy',
        ])->assertStatus(422);

        $this->assertSame(0, \App\Models\Project\ProjectMeeting::count());
    }

    public function test_changing_the_link_re_sends_it_but_editing_the_notes_does_not(): void
    {
        $this->smtp();
        $this->captureMail();

        $created = $this->postJson("/api/projects/{$this->project->id}/meetings", [
            'title' => 'Weekly site review', 'mode' => 'online', 'meeting_link' => self::ROOM,
        ])->assertStatus(201)->json('data');

        // Same meeting, different field. Re-mailing the link on every save is
        // how a useful notification becomes one people filter away.
        $this->putJson("/api/projects/{$this->project->id}/meetings/{$created['id']}", [
            'title' => 'Weekly site review', 'meeting_link' => self::ROOM, 'notes' => 'Bring the drawings.',
        ])->assertOk()->assertJsonPath('data.notified', null);

        $this->putJson("/api/projects/{$this->project->id}/meetings/{$created['id']}", [
            'title' => 'Weekly site review', 'meeting_link' => 'https://meet.google.com/aaa-bbbb-ccc',
        ])->assertOk()->assertJsonPath('data.notified.smtp_ready', true);
    }

    public function test_a_tenant_with_no_smtp_is_told_so(): void
    {
        $this->captureMail();

        $res = $this->postJson("/api/projects/{$this->project->id}/meetings", [
            'title' => 'Weekly site review', 'mode' => 'online', 'meeting_link' => self::ROOM,
        ])->assertStatus(201);

        $this->assertFalse($res->json('data.notified.smtp_ready'));
        $this->assertStringContainsString('Settings', (string) $res->json('data.notified.smtp_reason'));
    }
}
