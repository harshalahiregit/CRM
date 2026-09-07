<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrWhatsAppLog;
use App\Models\Notifications\HrNotification;
use App\Models\Tenant;
use App\Models\TenantWhatsAppSetting;
use App\Models\User;
use App\Services\Notifications\Channels\ChannelManager;
use App\Services\Notifications\Channels\WhatsAppChannel;
use App\Services\WhatsApp\CloudApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * WhatsApp delivery over Meta's Cloud API.
 *
 * Nothing here reaches Meta -- Http::fake() stands in -- because a test suite
 * that sends real WhatsApp messages costs money per run and messages real
 * people. What IS asserted is the part that has broken every attempt at this:
 * that a business-initiated message goes as an approved TEMPLATE, always, and
 * never as free text. Text is accepted by Graph and then silently dropped, so
 * "it did not error" proves nothing about whether anybody received it.
 */
class WhatsAppChannelTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'whatsapp.enabled' => true,
            'whatsapp.provider' => 'cloud',
            'whatsapp.cloud.token' => 'platform-token',
            'whatsapp.cloud.phone_number_id' => '111',
            'whatsapp.cloud.api_version' => 'v21.0',
            'whatsapp.cloud.notification_template' => 'sangoe_notification',
            'whatsapp.cloud.notification_template_language' => 'en',
        ]);

        $this->tenant = Tenant::create(['name' => 'T', 'slug' => 'wa-t', 'status' => 'active']);
        $this->user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Priya', 'email' => 'p@example.test',
            'phone' => '9876543210', 'password' => Hash::make('Password123!'),
            'role' => 'staff', 'status' => 'active',
        ]);
    }

    private function notification(?int $recipient = null, ?string $role = null): HrNotification
    {
        return HrNotification::create([
            'tenant_id' => $this->tenant->id, 'module' => 'Leave', 'event' => 'Approved',
            'title' => 'Leave approved', 'message' => 'Your leave was approved.',
            'recipient_user_id' => $recipient ?? $this->user->id, 'recipient_role' => $role,
        ]);
    }

    private function channel(): WhatsAppChannel
    {
        return app(WhatsAppChannel::class);
    }

    private static function accepted(): array
    {
        return ['messages' => [['id' => 'wamid.TEST']]];
    }

    private static function outsideWindow(): array
    {
        return ['error' => [
            'message' => 'Message failed to send',
            'code' => 131047,
            'error_data' => ['details' => 'Re-engagement message'],
        ]];
    }

    public function test_a_notification_always_goes_as_an_approved_template(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(self::accepted(), 200)]);

        $this->assertTrue($this->channel()->send($this->notification())['ok']);

        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            $b = $request->data();

            return ($b['type'] ?? null) === 'template'
                && $b['template']['name'] === 'sangoe_notification'
                && $b['template']['components'][0]['parameters'][0]['text'] === 'Leave approved';
        });
    }

    /**
     * Free text is never tried first, even though it reads better.
     *
     * The channel used to send text and fall back to the template on error
     * 131047. Measured against the live API on 2026-09-07, Graph ACCEPTED every
     * business-initiated free-text send — 200, a real wamid, no error — and
     * delivered none of them. With no error there was nothing to fall back
     * from, so the row was logged 'sent' and the message simply vanished. A
     * single template send has no such failure mode.
     */
    public function test_free_text_is_never_attempted(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(self::accepted(), 200)]);

        $this->channel()->send($this->notification());

        Http::assertNotSent(fn ($request) => ($request->data()['type'] ?? null) === 'text');
    }

    public function test_the_number_is_sent_in_full_international_form(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(self::accepted(), 200)]);

        $this->channel()->send($this->notification());

        // Meta wants digits only: no '+', no spaces, no 'whatsapp:' (Twilio's).
        Http::assertSent(fn ($request) => $request->data()['to'] === '919876543210');
    }

    public function test_the_employee_record_wins_over_the_login_phone(): void
    {
        HrEmployee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Priya', 'employee_code' => 'E1',
            'department' => 'Ops', 'designation' => 'Analyst',
            'status' => 'Active', 'joining_date' => '2020-01-01',
            'user_id' => $this->user->id, 'phone' => '9000000001',
        ]);

        Http::fake(['graph.facebook.com/*' => Http::response(self::accepted(), 200)]);
        $this->channel()->send($this->notification());

        // HR keeps this current; a user row's phone is whatever they typed once.
        Http::assertSent(fn ($request) => $request->data()['to'] === '919000000001');
    }

    public function test_a_person_with_no_number_is_reported_not_sent(): void
    {
        $this->user->update(['phone' => null]);

        Http::fake();
        $result = $this->channel()->send($this->notification());

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('No mobile number', $result['error']);
        Http::assertNothingSent();
    }

    public function test_a_send_is_logged_either_way(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(self::accepted(), 200)]);
        $this->channel()->send($this->notification());

        $this->assertDatabaseHas('hr_whatsapp_logs', [
            'tenant_id' => $this->tenant->id, 'status' => 'sent', 'message_sid' => 'wamid.TEST',
        ]);
    }

    public function test_a_refusal_is_logged_with_metas_reason(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response([
            'error' => ['message' => 'Unsupported post request', 'code' => 131030,
                'error_data' => ['details' => 'Recipient phone number not in allowed list']],
        ], 400)]);

        $result = $this->channel()->send($this->notification());

        $this->assertFalse($result['ok']);
        // The actionable half lives in details; keeping only message is why this
        // integration looks broken with no clue why.
        $this->assertStringContainsString('not in allowed list', $result['error']);
        $this->assertSame('failed', HrWhatsAppLog::first()->status);
    }

    public function test_a_tenant_with_its_own_number_sends_from_it(): void
    {
        TenantWhatsAppSetting::create([
            'tenant_id' => $this->tenant->id, 'provider' => 'cloud',
            'access_token' => 'tenant-token', 'phone_number_id' => '999',
            'api_version' => 'v21.0', 'enabled' => true,
        ]);

        Http::fake(['graph.facebook.com/*' => Http::response(self::accepted(), 200)]);
        $this->channel()->send($this->notification());

        Http::assertSent(fn ($request) => str_contains($request->url(), '/999/messages')
            && $request->hasHeader('Authorization', 'Bearer tenant-token'));
    }

    public function test_a_tenant_row_that_is_switched_off_falls_back_to_the_platform_sender(): void
    {
        TenantWhatsAppSetting::create([
            'tenant_id' => $this->tenant->id, 'access_token' => 'tenant-token',
            'phone_number_id' => '999', 'enabled' => false,
        ]);

        Http::fake(['graph.facebook.com/*' => Http::response(self::accepted(), 200)]);
        $this->channel()->send($this->notification());

        Http::assertSent(fn ($request) => str_contains($request->url(), '/111/messages'));
    }

    public function test_no_credentials_anywhere_is_reported_not_thrown(): void
    {
        config(['whatsapp.cloud.token' => '', 'whatsapp.cloud.phone_number_id' => '']);

        Http::fake();
        $result = $this->channel()->send($this->notification());

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('not configured', $result['error']);
    }

    public function test_whatsapp_is_a_live_channel_not_a_placeholder(): void
    {
        $manager = app(ChannelManager::class);

        $this->assertInstanceOf(WhatsAppChannel::class, $manager->for('whatsapp'));
        $this->assertFalse($manager->isPrepared('whatsapp'));
    }

    public function test_a_public_logo_puts_the_branded_template_on_the_message(): void
    {
        config(['whatsapp.cloud.notification_template_with_logo' => 'sangoe_notification_logo']);
        app(\App\Services\Settings\SettingsService::class)
            ->set($this->tenant->id, 'branding', 'logo_url', 'https://cdn.example.test/logo.png');

        Http::fakeSequence('graph.facebook.com/*')
            ->push(self::outsideWindow(), 200)
            ->push(self::accepted(), 200);

        $this->channel()->send($this->notification());

        Http::assertSent(function ($request) {
            $b = $request->data();
            if (($b['type'] ?? null) !== 'template') {
                return false;
            }
            $header = collect($b['template']['components'])->firstWhere('type', 'header');

            return $b['template']['name'] === 'sangoe_notification_logo'
                && $header['parameters'][0]['image']['link'] === 'https://cdn.example.test/logo.png';
        });
    }

    public function test_a_logo_meta_cannot_reach_is_ignored_rather_than_sent_broken(): void
    {
        // Meta fetches the header image itself. A LAN address resolves for this
        // server and for nobody else, and the result is a message with a blank
        // header and no error anyone would notice.
        app(\App\Services\Settings\SettingsService::class)
            ->set($this->tenant->id, 'branding', 'logo_url', 'https://192.168.31.159:8000/logo.png');

        Http::fakeSequence('graph.facebook.com/*')
            ->push(self::outsideWindow(), 200)
            ->push(self::accepted(), 200);

        $this->channel()->send($this->notification());

        Http::assertSent(function ($request) {
            $b = $request->data();

            return ($b['type'] ?? null) !== 'template'
                || $b['template']['name'] === 'sangoe_notification';
        });
    }

    public function test_a_plain_http_logo_is_ignored_too(): void
    {
        app(\App\Services\Settings\SettingsService::class)
            ->set($this->tenant->id, 'branding', 'logo_url', 'http://cdn.example.test/logo.png');

        Http::fakeSequence('graph.facebook.com/*')
            ->push(self::outsideWindow(), 200)
            ->push(self::accepted(), 200);

        $this->channel()->send($this->notification());

        Http::assertSent(function ($request) {
            $b = $request->data();

            return ($b['type'] ?? null) !== 'template'
                || $b['template']['name'] === 'sangoe_notification';
        });
    }

    public function test_ten_digit_numbers_are_assumed_indian_and_others_are_left_alone(): void
    {
        $this->assertSame('919876543210', CloudApiClient::normalise('9876543210'));
        $this->assertSame('919876543210', CloudApiClient::normalise('+91 98765-43210'));
        $this->assertSame('919876543210', CloudApiClient::normalise('0091 9876543210'));
        // A number that already carries a different country code is not touched.
        $this->assertSame('14155552671', CloudApiClient::normalise('+1 415 555 2671'));
    }
}
