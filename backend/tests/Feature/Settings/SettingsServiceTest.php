<?php

namespace Tests\Feature\Settings;

use App\Models\TenantSetting;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class SettingsServiceTest extends TestCase
{
    use RefreshDatabase;

    private SettingsService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new SettingsService();
    }

    public function test_get_group_returns_registry_defaults_when_empty(): void
    {
        $group = $this->svc->getGroup(1, 'branding');
        $this->assertSame('#7C3AED', $group['primary_color']);
        $this->assertNull($group['logo_url']);
    }

    public function test_set_and_get_roundtrip(): void
    {
        $this->svc->set(1, 'general', 'app_name', 'Acme CRM');
        $this->assertSame('Acme CRM', $this->svc->get(1, 'general', 'app_name'));
        $this->assertSame('Acme CRM', $this->svc->getGroup(1, 'general')['app_name']);
    }

    public function test_set_group_persists_and_ignores_unknown_keys(): void
    {
        $out = $this->svc->setGroup(1, 'general', [
            'app_name' => 'Acme',
            'support_email' => 'help@acme.test',
            'not_a_real_key' => 'ignored',
        ]);

        $this->assertSame('Acme', $out['app_name']);
        $this->assertSame('help@acme.test', $out['support_email']);
        $this->assertArrayNotHasKey('not_a_real_key', $out);
        $this->assertDatabaseMissing('tenant_settings', ['key' => 'not_a_real_key']);
    }

    public function test_tenant_isolation(): void
    {
        $this->svc->set(1, 'general', 'app_name', 'Tenant One');

        // Tenant 2 sees only its own (default), never tenant 1's value.
        $this->assertNull($this->svc->getGroup(2, 'general')['app_name']);
        $this->assertSame('Tenant One', $this->svc->getGroup(1, 'general')['app_name']);
    }

    public function test_reads_encrypted_row_as_plaintext(): void
    {
        // Seed a row flagged encrypted (the write-encrypt path activates once a
        // registry key is marked encrypted; the read-decrypt path is exercised here).
        TenantSetting::create([
            'tenant_id' => 1, 'group' => 'general', 'key' => 'app_name',
            'value' => Crypt::encryptString('Secret Co'), 'is_encrypted' => true, 'autoload' => true,
        ]);

        $this->assertSame('Secret Co', $this->svc->getGroup(1, 'general')['app_name']);
    }

    public function test_cache_is_used_and_flushed_on_write(): void
    {
        $this->svc->set(1, 'general', 'app_name', 'A');
        $this->assertSame('A', $this->svc->getGroup(1, 'general')['app_name']); // caches raw map

        // Mutate the row directly, bypassing the service → cache should still show 'A'.
        TenantSetting::where('tenant_id', 1)->where('group', 'general')->where('key', 'app_name')->update(['value' => 'B']);
        $this->assertSame('A', $this->svc->getGroup(1, 'general')['app_name']);

        // After an explicit flush, the fresh value is read.
        $this->svc->flush(1);
        $this->assertSame('B', $this->svc->getGroup(1, 'general')['app_name']);
    }
}
