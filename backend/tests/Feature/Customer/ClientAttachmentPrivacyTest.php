<?php

namespace Tests\Feature\Customer;

use App\Models\Customer\Client;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Customer attachments must not be readable without credentials.
 *
 * They were stored on the `public` disk, which puts them under a URL any
 * browser can fetch — no token, no session, nothing. Not enumerable, since the
 * filename is random, but a URL leaks through history, a pasted link, a proxy
 * log or a forwarded email, and once it has leaked there is no way back:
 * deactivating the user changes nothing about a public file.
 *
 * Every other sensitive store in this application already uses a private disk
 * (hr_resumes, vendor_docs, purchase_docs, contract_docs). There was even an
 * unused `attachments` disk configured for exactly this.
 */
class ClientAttachmentPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Client $client;
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('attachments');
        Storage::fake('public');

        $this->tenant = Tenant::create([
            'name' => 'Acme', 'slug' => 'acme', 'subdomain' => 'acme',
            'plan' => 'professional', 'status' => 'active',
        ]);
        $this->client = Client::create([
            'tenant_id' => $this->tenant->id, 'company' => 'Widget Ltd', 'active' => true,
        ]);
        $this->staff = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Admin', 'email' => 'a@x.test',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'active',
        ]);
    }

    private function upload(): array
    {
        Sanctum::actingAs($this->staff);

        return $this->postJson("/api/customers/{$this->client->id}/attachments", [
            'file' => UploadedFile::fake()->create('contract.pdf', 12, 'application/pdf'),
        ])->assertCreated()->json();
    }

    public function test_an_upload_lands_on_the_private_disk_and_not_the_public_one(): void
    {
        $row = $this->upload();

        Storage::disk('attachments')->assertExists($row['file_path']);
        Storage::disk('public')->assertMissing($row['file_path']);
    }

    public function test_the_exposed_url_is_the_guarded_endpoint_not_a_storage_path(): void
    {
        $row = $this->upload();

        // Storage::url() would hand out a directly-fetchable path and bypass
        // every check in the controller.
        $this->assertSame(
            "/api/customers/{$this->client->id}/attachments/{$row['id']}/download",
            $row['url']
        );
        $this->assertStringNotContainsString('/storage/', (string) $row['url']);
    }

    public function test_downloading_without_a_token_is_refused(): void
    {
        $row = $this->upload();

        // A fresh, unauthenticated request — the whole point of the change.
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/customers/{$this->client->id}/attachments/{$row['id']}/download")
            ->assertStatus(401);
    }

    public function test_staff_from_another_tenant_cannot_download_it(): void
    {
        $row = $this->upload();

        $other = Tenant::create([
            'name' => 'Globex', 'slug' => 'globex', 'subdomain' => 'globex',
            'plan' => 'professional', 'status' => 'active',
        ]);
        Sanctum::actingAs(User::create([
            'tenant_id' => $other->id, 'name' => 'Them', 'email' => 't@x.test',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'active',
        ]));

        $this->getJson("/api/customers/{$this->client->id}/attachments/{$row['id']}/download")
            ->assertStatus(403);
    }

    public function test_an_attachment_cannot_be_fetched_through_another_customer(): void
    {
        $row = $this->upload();
        $other = Client::create(['tenant_id' => $this->tenant->id, 'company' => 'Other Ltd', 'active' => true]);

        Sanctum::actingAs($this->staff);
        $this->getJson("/api/customers/{$other->id}/attachments/{$row['id']}/download")
            ->assertStatus(404);
    }

    public function test_authorised_staff_get_the_file(): void
    {
        $row = $this->upload();

        Sanctum::actingAs($this->staff);
        $this->get("/api/customers/{$this->client->id}/attachments/{$row['id']}/download")
            ->assertOk()
            ->assertDownload('contract.pdf');
    }

    public function test_deleting_removes_it_from_the_private_disk(): void
    {
        $row = $this->upload();

        Sanctum::actingAs($this->staff);
        $this->deleteJson("/api/customers/{$this->client->id}/attachments/{$row['id']}")->assertOk();

        Storage::disk('attachments')->assertMissing($row['file_path']);
    }
}
