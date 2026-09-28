<?php

namespace Tests\Feature\Hrm;

use App\Models\Hr\HrEmployee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The profile picture is actually stored.
 *
 * The app has always posted it under `profile`, and the handler never read it —
 * it returned avatar => '' hardcoded. So somebody chose a photo, watched it
 * appear in the avatar, pressed Save, was told "Profile updated", and it was
 * gone on the next launch. Nothing anywhere said so.
 */
class ProfilePictureTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $tenant = Tenant::create(['name' => 'S', 'slug' => 'avatar', 'status' => 'active']);

        $this->user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Ravi', 'email' => 'r@avatar.test',
            'password' => Hash::make('x'), 'role' => 'staff', 'status' => 'active',
        ]);

        HrEmployee::create([
            'tenant_id' => $tenant->id, 'employee_code' => 'E1', 'name' => 'Ravi',
            'department' => 'Ops', 'designation' => 'Analyst',
            'joining_date' => '2020-01-01', 'status' => 'Active', 'user_id' => $this->user->id,
        ]);

        Sanctum::actingAs($this->user);
    }

    public function test_a_posted_picture_is_stored_and_returned(): void
    {
        $r = $this->postJson('/api/Hrm/edit-profile', [
            'name'    => 'Ravi Kumar',
            'profile' => UploadedFile::fake()->image('me.jpg', 800, 800),
        ])->assertOk();

        $this->assertSame(1, $r->json('status'));

        $avatar = $this->user->fresh()->avatar;
        $this->assertNotNull($avatar, 'The picture was discarded.');
        $this->assertStringStartsWith('avatars/', $avatar);
        Storage::disk('local')->assertExists($avatar);

        // And the app is handed a URL it can load, not an empty string.
        $this->assertNotSame('', $r->json('data.avatar'));
        $this->assertStringContainsString('/Hrm/avatar/', $r->json('data.avatar'));
    }

    /** A photo from a phone is megabytes; an avatar is displayed tiny. */
    public function test_the_picture_is_converted_to_webp(): void
    {
        $this->postJson('/api/Hrm/edit-profile', [
            'profile' => UploadedFile::fake()->image('me.png', 1200, 1200),
        ])->assertOk();

        $this->assertStringEndsWith('.webp', (string) $this->user->fresh()->avatar);
    }

    /** Updating a name must not wipe a picture that is already there. */
    public function test_saving_without_a_picture_keeps_the_existing_one(): void
    {
        $this->postJson('/api/Hrm/edit-profile', [
            'profile' => UploadedFile::fake()->image('me.jpg'),
        ])->assertOk();

        $before = $this->user->fresh()->avatar;

        $this->postJson('/api/Hrm/edit-profile', ['name' => 'Renamed'])->assertOk();

        $this->assertSame($before, $this->user->fresh()->avatar);
    }

    /**
     * Refused — and refused in the shape the APP understands.
     *
     * Every Hrm endpoint answers a validation failure with HTTP 200 and an
     * integer status of 0; 401 is reserved for a dead session. A 422 here would
     * be handled by the app as a network error rather than as a message.
     */
    public function test_something_that_is_not_an_image_is_refused(): void
    {
        $r = $this->postJson('/api/Hrm/edit-profile', [
            'profile' => UploadedFile::fake()->create('notes.pdf', 40),
        ])->assertOk();

        $this->assertSame(0, $r->json('status'));
        $this->assertStringContainsString('image', (string) $r->json('message'));
        $this->assertNull($this->user->fresh()->avatar, 'Nothing should have been stored.');
    }

    /**
     * The file is served by signature only.
     *
     * It is a photograph of a named employee, so a guessable public URL to the
     * avatars directory would be a directory of everybody's faces.
     */
    public function test_the_avatar_url_needs_a_valid_signature(): void
    {
        $url = $this->postJson('/api/Hrm/edit-profile', [
            'profile' => UploadedFile::fake()->image('me.jpg'),
        ])->json('data.avatar');

        $this->get($url)->assertOk();

        // Tampering with the signature breaks it.
        $this->get(preg_replace('/signature=[a-f0-9]+/', 'signature=deadbeef', $url))
            ->assertForbidden();
    }

    /** A crafted path must not walk out of the avatars directory. */
    public function test_a_crafted_path_is_refused(): void
    {
        $url = \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'hrm.avatar', now()->addHour(), ['path' => base64_encode('../../.env')],
        );

        $this->get($url)->assertNotFound();
    }
}
