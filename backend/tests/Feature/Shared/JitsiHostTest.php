<?php

namespace Tests\Feature\Shared;

use App\Models\Shared\KickoffMeeting;
use App\Models\Tenant;
use App\Models\Vendor\Vendor;
use App\Services\Settings\SettingsService;
use App\Services\Shared\MeetingLinkService;
use App\Services\Shared\OnlineMeetingService;
use App\Support\Shared\JitsiHost;
use App\Support\Shared\KickoffStatus;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Which server a meeting link points at.
 *
 * meet.jit.si makes whoever STARTS a room sign in with Google — that is 8x8's
 * policy on their free instance, enforced on their side, and the only way past
 * it is a different server. So the server has to be something a tenant can
 * change, and every path that mints a link has to honour it.
 *
 * MeetingLinkService did not: it hard-coded meet.jit.si, so a tenant who had
 * configured their own server still got public-instance links from that path.
 */
class JitsiHostTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        JitsiHost::flush();

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();
    }

    private function setHost(?string $host): void
    {
        app(SettingsService::class)->set(self::TENANT, 'meetings', 'jitsi_domain', $host);
        JitsiHost::flush();
    }

    private function meeting(): KickoffMeeting
    {
        $vendor = Vendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Acme', 'status' => VendorStatus::ACTIVE,
        ]);

        return KickoffMeeting::create([
            'tenant_id' => self::TENANT, 'kickoffable_type' => Vendor::class,
            'kickoffable_id' => $vendor->id, 'title' => 'Kickoff', 'meeting_type' => 'kickoff',
            'status' => KickoffStatus::SCHEDULED, 'mode' => 'online',
            'scheduled_at' => now()->addDay()->format('Y-m-d H:i:s'), 'duration_minutes' => 60,
        ]);
    }

    /* ── the resolver ────────────────────────────────────────────────────── */

    public function test_it_falls_back_to_the_public_instance(): void
    {
        $this->assertSame('meet.jit.si', JitsiHost::for(self::TENANT));
        $this->assertTrue(JitsiHost::isPublic(self::TENANT),
            'the default is the server that asks the host to sign in — callers need to know');
    }

    public function test_a_tenant_setting_wins(): void
    {
        $this->setHost('meet.mycompany.com');

        $this->assertSame('meet.mycompany.com', JitsiHost::for(self::TENANT));
        $this->assertFalse(JitsiHost::isPublic(self::TENANT));
    }

    /**
     * A person setting this will paste a URL as often as they will type a host.
     * Storing the scheme would mint https://https://meet…/room.
     */
    public function test_a_pasted_url_is_reduced_to_its_host(): void
    {
        foreach ([
            'https://meet.mycompany.com/'      => 'meet.mycompany.com',
            'http://meet.mycompany.com'        => 'meet.mycompany.com',
            'meet.mycompany.com/some/path'     => 'meet.mycompany.com',
            '  MEET.MyCompany.com  '           => 'meet.mycompany.com',
            'meet.mycompany.com:8443'          => 'meet.mycompany.com:8443',
        ] as $typed => $expected) {
            $this->assertSame($expected, JitsiHost::clean($typed), "cleaning {$typed}");
        }
    }

    public function test_nonsense_is_refused_so_the_fallback_stands(): void
    {
        foreach (['', '   ', 'not a host', 'javascript:alert(1)', '///'] as $bad) {
            $this->assertNull(JitsiHost::clean($bad), "should refuse: {$bad}");
        }

        $this->setHost('not a host');
        $this->assertSame('meet.jit.si', JitsiHost::for(self::TENANT),
            'a broken setting must not mint a broken link');
    }

    /* ── every path that mints a link ────────────────────────────────────── */

    public function test_the_meeting_link_uses_the_configured_host(): void
    {
        $this->setHost('meet.mycompany.com');

        $data = app(OnlineMeetingService::class)->createMeeting($this->meeting(), 'jitsi');

        $this->assertStringStartsWith('https://meet.mycompany.com/', $data['link']);
        $this->assertStringNotContainsString('meet.jit.si', $data['link']);
    }

    /** The path that was hard-coded, and the reason this test exists. */
    public function test_the_platform_picker_uses_the_configured_host_too(): void
    {
        $this->setHost('meet.mycompany.com');

        $data = app(MeetingLinkService::class)->forPlatform('jitsi', self::TENANT);

        $this->assertStringStartsWith('https://meet.mycompany.com/', $data['link'],
            'this path ignored the setting entirely and always used the public instance');
    }

    public function test_both_paths_agree_on_the_host(): void
    {
        $this->setHost('meet.mycompany.com');

        $a = app(OnlineMeetingService::class)->createMeeting($this->meeting(), 'jitsi')['link'];
        $b = app(MeetingLinkService::class)->forPlatform('jitsi', self::TENANT)['link'];

        $this->assertSame(parse_url($a, PHP_URL_HOST), parse_url($b, PHP_URL_HOST),
            'two ways of creating a link must not land on two different servers');
    }

    /**
     * A meeting keeps the server it was created on. Moving the setting must not
     * silently repoint links people have already been e-mailed.
     */
    public function test_an_existing_link_is_not_rewritten_by_a_later_change(): void
    {
        $meeting = $this->meeting();
        $before  = app(OnlineMeetingService::class)->createMeeting($meeting, 'jitsi')['link'];

        $this->setHost('meet.somewhere-else.com');

        $this->assertSame($before, $meeting->fresh()->meeting_link,
            'an invitation already sent must keep working');
    }
}
