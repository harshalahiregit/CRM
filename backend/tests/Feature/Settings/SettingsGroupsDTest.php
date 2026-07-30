<?php

namespace Tests\Feature\Settings;

use App\Services\Settings\SettingsService;
use App\Support\Settings\SettingRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Increment D — Upload / Security / Notification groups on the shared settings
 * store. Verifies registry wiring, casts (int/bool/array), tenant isolation and
 * the notification matrix roundtrip. Does not touch increments A–B.
 */
class SettingsGroupsDTest extends TestCase
{
    use RefreshDatabase;

    private SettingsService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new SettingsService();
    }

    public function test_new_groups_are_registered(): void
    {
        foreach (['upload', 'security', 'notifications'] as $group) {
            $this->assertTrue(SettingRegistry::groupExists($group), "$group missing");
        }
        $this->assertSame(10, SettingRegistry::defaults('upload')['max_upload_mb']);
        $this->assertSame(8, SettingRegistry::defaults('security')['password_min_length']);
        $this->assertTrue(SettingRegistry::defaults('notifications')['email']);
    }

    public function test_registry_rules_are_keyed_per_group(): void
    {
        $this->assertArrayHasKey('upload.max_upload_mb', SettingRegistry::rules(['upload']));
        $this->assertArrayHasKey('security.password_min_length', SettingRegistry::rules(['security']));
        $this->assertArrayHasKey('notifications.categories', SettingRegistry::rules(['notifications']));
    }

    public function test_getgroup_returns_defaults_including_matrix(): void
    {
        $upload = $this->svc->getGroup(1, 'upload');
        $this->assertSame(10, $upload['max_upload_mb']);
        $this->assertSame('local', $upload['storage_disk']);
        $this->assertFalse($upload['public_visibility']);

        $notif = $this->svc->getGroup(1, 'notifications');
        $this->assertCount(11, $notif['categories']);
        $this->assertTrue($notif['categories']['HR']['email']);
        $this->assertFalse($notif['categories']['HR']['whatsapp']);
    }

    public function test_int_and_bool_casts(): void
    {
        $this->svc->set(1, 'upload', 'max_upload_mb', '25');       // string in
        $this->assertSame(25, $this->svc->get(1, 'upload', 'max_upload_mb')); // int out

        $this->svc->set(1, 'security', 'require_uppercase', false);
        $this->assertFalse($this->svc->get(1, 'security', 'require_uppercase'));
        $this->svc->set(1, 'security', 'two_factor_enabled', '1');
        $this->assertTrue($this->svc->get(1, 'security', 'two_factor_enabled'));
    }

    public function test_notification_matrix_array_roundtrip(): void
    {
        $matrix = SettingRegistry::notificationMatrix();
        $matrix['Sales']['whatsapp'] = true;
        $matrix['Payroll']['email'] = false;

        $out = $this->svc->setGroup(1, 'notifications', ['categories' => $matrix]);

        $this->assertTrue($out['categories']['Sales']['whatsapp']);
        $this->assertFalse($out['categories']['Payroll']['email']);
        // Persisted + re-read (fresh service instance / flushed cache) is identical.
        $this->svc->flush(1);
        $this->assertSame($matrix, $this->svc->getGroup(1, 'notifications')['categories']);
    }

    public function test_tenant_isolation_for_d_groups(): void
    {
        $this->svc->set(1, 'upload', 'max_upload_mb', 512);
        $this->assertSame(512, $this->svc->getGroup(1, 'upload')['max_upload_mb']);
        $this->assertSame(10, $this->svc->getGroup(2, 'upload')['max_upload_mb']); // tenant 2 = default
    }
}
