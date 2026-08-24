<?php

namespace Tests\Feature\Customer;

use App\Models\Customer\Client;
use App\Models\Customer\ClientVaultAccessLog;
use App\Models\Customer\ClientVaultEntry;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Customer\VaultUnlockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The vault holds documents, and opening it requires confirming who you are.
 *
 * Both this CRM and the legacy one built the vault as a credential store, so a
 * signed agreement had nowhere to go but Files — which has no per-entry
 * visibility and no access log. A server password was better protected than a
 * contract, which is backwards.
 *
 * What matters here is not that upload works; it is that a document inherits
 * everything a credential already had — visibility, creator-only management,
 * and a log entry every time it leaves the vault — plus the re-authentication
 * the legacy CRM required and we had dropped.
 */
class VaultDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery';

    private Tenant $tenant;
    private Client $client;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('attachments');

        $this->tenant = Tenant::create([
            'name' => 'Acme', 'slug' => 'acme', 'subdomain' => 'acme',
            'plan' => 'professional', 'status' => 'active',
        ]);
        $this->client = Client::create([
            'tenant_id' => $this->tenant->id, 'company' => 'Widget Ltd', 'active' => true,
        ]);
        $this->owner = $this->user('staff');
        Sanctum::actingAs($this->owner);
    }

    private function user(string $role): User
    {
        return User::create([
            'tenant_id' => $this->tenant->id, 'name' => ucfirst($role), 'role' => $role,
            'email' => $role.'-'.uniqid().'@test.local',
            'password' => Hash::make(self::PASSWORD), 'status' => 'active',
        ]);
    }

    private function unlock(): void
    {
        $this->postJson('/api/customers/vault/unlock', ['password' => self::PASSWORD])->assertOk();
    }

    private function upload(array $over = []): array
    {
        return $this->postJson("/api/customers/{$this->client->id}/vault", array_merge([
            'title'    => 'Master Services Agreement',
            'category' => 'Agreement',
            'file'     => UploadedFile::fake()->create('msa.pdf', 40, 'application/pdf'),
        ], $over))->assertCreated()->json();
    }

    // ── documents ────────────────────────────────────────────────────────────

    public function test_a_document_can_be_stored_in_the_vault(): void
    {
        $entry = $this->upload();

        $this->assertSame('document', $entry['kind']);
        $this->assertSame('Agreement', $entry['category']);
        $this->assertSame('msa.pdf', $entry['file_name']);
        Storage::disk('attachments')->assertExists($entry['file_path']);
    }

    public function test_the_document_is_not_web_reachable_and_downloads_through_the_guard(): void
    {
        $entry = $this->upload();
        $this->unlock();

        $this->get("/api/customers/{$this->client->id}/vault/{$entry['id']}/download")
             ->assertOk()
             ->assertHeader('content-disposition', 'attachment; filename=msa.pdf');
    }

    public function test_replacing_a_document_removes_the_one_it_replaced(): void
    {
        $entry = $this->upload();
        $first = $entry['file_path'];

        $this->putJson("/api/customers/{$this->client->id}/vault/{$entry['id']}", [
            'title' => 'Master Services Agreement',
            'file'  => UploadedFile::fake()->create('msa-v2.pdf', 40, 'application/pdf'),
        ])->assertOk();

        // Otherwise a "replaced" confidential file sits on disk unreachable and
        // unnoticed by anyone auditing what the vault holds.
        Storage::disk('attachments')->assertMissing($first);
    }

    public function test_an_entry_with_no_document_cannot_be_downloaded(): void
    {
        $entry = ClientVaultEntry::create([
            'tenant_id' => $this->tenant->id, 'client_id' => $this->client->id,
            'title' => 'Server login', 'password' => 'secret', 'created_by' => $this->owner->id,
        ]);
        $this->unlock();

        $this->get("/api/customers/{$this->client->id}/vault/{$entry->id}/download")->assertNotFound();
    }

    // ── the download is a disclosure, so it is logged ─────────────────────────

    public function test_downloading_is_recorded_in_the_access_log(): void
    {
        $entry = $this->upload();
        $this->unlock();
        $this->get("/api/customers/{$this->client->id}/vault/{$entry['id']}/download")->assertOk();

        $this->assertDatabaseHas('client_vault_access_log', [
            'vault_entry_id' => $entry['id'],
            'user_id'        => $this->owner->id,
            'action'         => ClientVaultAccessLog::DOWNLOADED,
        ]);
    }

    // ── re-authentication ────────────────────────────────────────────────────

    public function test_the_vault_is_locked_until_the_password_is_confirmed(): void
    {
        $entry = $this->upload();

        // 423 Locked, not 403: the user IS allowed, they simply have not
        // confirmed who they are recently enough, and the UI must tell those
        // apart to know whether to prompt or to refuse.
        $this->get("/api/customers/{$this->client->id}/vault/{$entry['id']}/download")->assertStatus(423);
    }

    public function test_revealing_a_password_also_requires_the_unlock(): void
    {
        $entry = ClientVaultEntry::create([
            'tenant_id' => $this->tenant->id, 'client_id' => $this->client->id,
            'title' => 'Server login', 'password' => 'secret', 'created_by' => $this->owner->id,
        ]);

        $this->postJson("/api/customers/{$this->client->id}/vault/{$entry->id}/reveal")->assertStatus(423);

        $this->unlock();
        $this->postJson("/api/customers/{$this->client->id}/vault/{$entry->id}/reveal")
             ->assertOk()->assertJsonPath('password', 'secret');
    }

    public function test_a_wrong_password_does_not_open_the_vault(): void
    {
        $this->postJson('/api/customers/vault/unlock', ['password' => 'not-it'])->assertStatus(401);
        $this->assertFalse(app(VaultUnlockService::class)->isUnlocked($this->owner));
    }

    public function test_locking_closes_it_again(): void
    {
        $this->unlock();
        $this->assertTrue(app(VaultUnlockService::class)->isUnlocked($this->owner));

        $this->postJson('/api/customers/vault/lock')->assertOk();
        $this->assertFalse(app(VaultUnlockService::class)->isUnlocked($this->owner));
    }

    public function test_repeated_wrong_passwords_lock_the_vault_out(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/customers/vault/unlock', ['password' => 'wrong'])->assertStatus(401);
        }
        // The sixth is refused before the password is even checked.
        $this->postJson('/api/customers/vault/unlock', ['password' => self::PASSWORD])->assertStatus(429);
    }

    public function test_one_users_unlock_does_not_open_it_for_another(): void
    {
        $this->unlock();
        $entry = $this->upload();

        $other = $this->user('admin');
        Sanctum::actingAs($other);

        // The unlock is held against the user id, so it cannot leak sideways.
        $this->get("/api/customers/{$this->client->id}/vault/{$entry['id']}/download")->assertStatus(423);
    }

    // ── existing protections still apply to documents ─────────────────────────

    public function test_a_creator_only_document_is_invisible_to_another_staff_member(): void
    {
        $entry = $this->upload(['visibility' => ClientVaultEntry::VISIBILITY_CREATOR]);

        Sanctum::actingAs($this->user('staff'));
        $this->unlock();

        // 404, not 403 — a colleague should not learn the entry exists.
        $this->get("/api/customers/{$this->client->id}/vault/{$entry['id']}/download")->assertNotFound();
    }

    public function test_the_lock_state_endpoint_tells_the_ui_whether_to_prompt(): void
    {
        $this->getJson('/api/customers/vault/lock-state')->assertOk()->assertJsonPath('unlocked', false);
        $this->unlock();
        $this->getJson('/api/customers/vault/lock-state')->assertOk()->assertJsonPath('unlocked', true);
    }
}
