<?php

namespace Tests\Feature\Hrm;

use App\Models\Hr\HrEmployee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The two pictures the app shows in its headers.
 *
 * Both used to be hardcoded to '' in the login payload, so a profile photograph
 * saved from Settings appeared once -- in the response to the save -- and was
 * gone on the next sign-in, which read exactly like the upload had failed. The
 * company logo was never sendable at all, and the only way to set one would
 * have been to edit PHP.
 */
class HrmBrandingAndAvatarTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Sangoe', 'slug' => 'brand-t', 'status' => 'active']);
    }

    private function person(?string $avatar = null): User
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Priya Sharma',
            'email' => 'priya@example.test', 'phone' => '9876543210',
            'password' => Hash::make('Password123!'), 'role' => 'staff', 'status' => 'active',
            'avatar' => $avatar,
        ]);

        HrEmployee::create([
            'tenant_id' => $this->tenant->id, 'employee_code' => 'SNE-1', 'name' => 'Priya Sharma',
            'department' => 'Ops', 'designation' => 'Analyst', 'joining_date' => '2020-01-01',
            'status' => 'Active', 'user_id' => $user->id, 'app_login_enabled' => true,
        ]);

        return $user;
    }

    private function login(): array
    {
        return $this->postJson('/api/Hrm/login', [
            'email' => 'priya@example.test', 'password' => 'Password123!',
        ])->json();
    }

    public function test_a_saved_profile_picture_survives_the_next_sign_in(): void
    {
        $this->person('avatars/t1/face.webp');

        $avatar = $this->login()['data']['user']['avatar'];

        $this->assertNotSame('', $avatar, 'Login dropped the avatar, so the app forgets it on every sign-in');
        // A signed link, not a guessable public path into a directory of faces.
        $this->assertStringContainsString('signature=', $avatar);
        $this->assertStringContainsString('/api/Hrm/avatar/', $avatar);
    }

    public function test_someone_with_no_picture_gets_an_empty_string_not_a_broken_link(): void
    {
        $this->person(null);

        // The app renders whatever it is handed. A URL that 404s draws a broken
        // image; '' falls through to the placeholder, which is the honest look.
        $this->assertSame('', $this->login()['data']['user']['avatar']);
    }

    public function test_the_company_logo_comes_from_branding_settings(): void
    {
        $this->person();
        app(SettingsService::class)->set($this->tenant->id, 'branding', 'logo_url', 'https://cdn.example.test/logo.png');

        $this->assertSame(
            'https://cdn.example.test/logo.png',
            $this->login()['data']['workspaces'][0]['logo'],
            'An admin changing the logo in Settings must reach the app without a code change'
        );
    }

    public function test_a_site_relative_logo_is_sent_absolute(): void
    {
        $this->person();
        app(SettingsService::class)->set($this->tenant->id, 'branding', 'logo_url', '/storage/logo.png');

        $logo = $this->login()['data']['workspaces'][0]['logo'];

        // Image.network cannot resolve '/storage/logo.png' against nothing.
        $this->assertStringStartsWith('http', $logo);
        $this->assertStringEndsWith('/storage/logo.png', $logo);
    }

    public function test_no_logo_configured_is_an_empty_string(): void
    {
        $this->person();

        $this->assertSame('', $this->login()['data']['workspaces'][0]['logo']);
    }
}
