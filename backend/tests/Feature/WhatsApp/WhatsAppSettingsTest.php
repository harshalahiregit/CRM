<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Tenant;
use App\Models\TenantWhatsAppSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Setting the WhatsApp sender from the UI.
 *
 * The property that matters is the token: it must be storable, never readable
 * back, and never lost by saving the form again. An admin changing the phone
 * number should not have to re-paste a token they no longer have a copy of.
 */
class WhatsAppSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'T', 'slug' => 'was-t', 'status' => 'active']);
        config([
            'whatsapp.cloud.token' => '', 'whatsapp.cloud.phone_number_id' => '',
            'whatsapp.cloud.notification_template' => 'sangoe_notification',
        ]);
    }

    private function admin(): User
    {
        $u = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Admin', 'email' => 'a@example.test',
            'password' => Hash::make('Password123!'), 'role' => 'admin', 'status' => 'active',
        ]);
        Sanctum::actingAs($u);

        return $u;
    }

    private function staff(): User
    {
        $u = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Staff', 'email' => 's@example.test',
            'password' => Hash::make('Password123!'), 'role' => 'staff', 'status' => 'active',
        ]);
        Sanctum::actingAs($u);

        return $u;
    }

    public function test_the_token_is_never_sent_back_to_the_browser(): void
    {
        $this->admin();
        TenantWhatsAppSetting::create([
            'tenant_id' => $this->tenant->id, 'access_token' => 'super-secret',
            'phone_number_id' => '999', 'enabled' => true,
        ]);

        $res = $this->getJson('/api/settings/whatsapp')->assertOk();

        $this->assertStringNotContainsString('super-secret', $res->getContent());
        $this->assertTrue($res->json('settings.has_token'));
    }

    public function test_saving_with_a_blank_token_keeps_the_one_already_stored(): void
    {
        $this->admin();
        TenantWhatsAppSetting::create([
            'tenant_id' => $this->tenant->id, 'access_token' => 'keep-me',
            'phone_number_id' => '999', 'enabled' => true,
        ]);

        // Changing only the phone number must not wipe the credential.
        $this->putJson('/api/settings/whatsapp', [
            'phone_number_id' => '1000', 'enabled' => true,
        ])->assertOk();

        $s = TenantWhatsAppSetting::where('tenant_id', $this->tenant->id)->first();
        $this->assertSame('keep-me', $s->access_token);
        $this->assertSame('1000', $s->phone_number_id);
    }

    public function test_the_screen_says_which_number_messages_leave_from(): void
    {
        $this->admin();

        $this->assertSame('none', $this->getJson('/api/settings/whatsapp')->json('source'));

        config(['whatsapp.cloud.token' => 'platform', 'whatsapp.cloud.phone_number_id' => '111']);
        $this->assertSame('platform', $this->getJson('/api/settings/whatsapp')->json('source'));

        TenantWhatsAppSetting::create([
            'tenant_id' => $this->tenant->id, 'access_token' => 't', 'phone_number_id' => '999', 'enabled' => true,
        ]);
        $this->assertSame('tenant', $this->getJson('/api/settings/whatsapp')->json('source'));
    }

    public function test_check_connection_reads_and_does_not_send(): void
    {
        $this->admin();
        TenantWhatsAppSetting::create([
            'tenant_id' => $this->tenant->id, 'access_token' => 't', 'phone_number_id' => '999', 'enabled' => true,
        ]);

        Http::fake(['graph.facebook.com/*' => Http::response([
            'verified_name' => 'Sangoe', 'display_phone_number' => '+91 731 698 1741',
            'quality_rating' => 'GREEN', 'status' => 'CONNECTED',
        ], 200)]);

        $this->postJson('/api/settings/whatsapp/verify')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('sender.verified_name', 'Sangoe');

        // A check that costs a message is a check nobody runs.
        Http::assertSent(fn ($r) => $r->method() === 'GET');
    }

    public function test_check_connection_reports_a_number_that_cannot_send(): void
    {
        $this->admin();
        TenantWhatsAppSetting::create([
            'tenant_id' => $this->tenant->id, 'access_token' => 't', 'phone_number_id' => '999', 'enabled' => true,
        ]);

        Http::fake(['graph.facebook.com/*' => Http::response([
            'verified_name' => 'Sangoe', 'status' => 'CONNECTED',
            'name_status' => 'DECLINED', 'code_verification_status' => 'EXPIRED',
        ], 200)]);

        $warnings = $this->postJson('/api/settings/whatsapp/verify')->assertOk()->json('warnings');

        // CONNECTED plus either of these is the state where Meta refuses sends
        // with "(#131005) Access denied" and blames the token.
        $this->assertCount(2, $warnings);
    }

    public function test_only_an_administrator_may_change_the_sender(): void
    {
        $this->staff();

        $this->putJson('/api/settings/whatsapp', ['phone_number_id' => '999'])->assertStatus(403);
        $this->postJson('/api/settings/whatsapp/test', ['to' => '9876543210'])->assertStatus(403);
    }

    public function test_a_refusal_from_meta_is_passed_through_rather_than_reported_as_success(): void
    {
        $this->admin();
        config(['whatsapp.cloud.token' => 'platform', 'whatsapp.cloud.phone_number_id' => '111']);

        Http::fake(['graph.facebook.com/*' => Http::response([
            'error' => ['message' => '(#131005) Access denied', 'code' => 131005],
        ], 400)]);

        $this->postJson('/api/settings/whatsapp/test', ['to' => '9876543210'])
            ->assertStatus(422)
            ->assertJsonPath('ok', false);
    }
}
