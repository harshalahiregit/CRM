<?php

namespace Tests\Unit\Settings;

use App\Support\Settings\SettingRegistry;
use Tests\TestCase;

class SettingRegistryTest extends TestCase
{
    public function test_groups_and_keys_exist(): void
    {
        $this->assertContains('general', SettingRegistry::groups());
        $this->assertContains('branding', SettingRegistry::groups());
        $this->assertTrue(SettingRegistry::has('general', 'app_name'));
        $this->assertTrue(SettingRegistry::has('branding', 'primary_color'));
        $this->assertFalse(SettingRegistry::has('general', 'nope'));
        $this->assertFalse(SettingRegistry::groupExists('nope'));
    }

    public function test_defaults(): void
    {
        $this->assertSame('#7C3AED', SettingRegistry::defaults('branding')['primary_color']);
        $this->assertNull(SettingRegistry::defaults('general')['app_name']);
    }

    public function test_rules_are_keyed_group_dot_key(): void
    {
        $rules = SettingRegistry::rules(['general', 'branding']);
        $this->assertArrayHasKey('general.app_name', $rules);
        $this->assertArrayHasKey('branding.primary_color', $rules);
        $this->assertContains('email', $rules['general.support_email']);
    }

    public function test_cast_and_forstore(): void
    {
        $this->assertNull(SettingRegistry::cast('general', 'app_name', null));
        $this->assertSame('Acme', SettingRegistry::cast('general', 'app_name', 'Acme'));
        $this->assertSame('#fff', SettingRegistry::forStore('branding', 'primary_color', '#fff'));
    }
}
