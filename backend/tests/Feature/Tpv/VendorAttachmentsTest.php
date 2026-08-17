<?php

namespace Tests\Feature\Tpv;

use App\Models\Shared\Attachment;
use App\Models\Shared\AttachmentFolder;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The vendor Attachments tab — a folder tree with uploads.
 *
 * What these hold in place:
 *  - the subject comes from the ROUTE, so a folder or file id belonging to
 *    another vendor is a 404 and never a place to file something;
 *  - deleting a folder takes its contents AND their bytes, so the disk does not
 *    fill with files nothing points at;
 *  - executables are refused;
 *  - Google Drive and OneDrive are import sources — the bytes land on our disk
 *    like any upload, and the origin is recorded rather than linked to.
 */
class VendorAttachmentsTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('attachments');

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'Tenant 1', 'slug' => 'tenant-1',
            'subdomain' => 'tenant1', 'status' => 'active',
        ])->save();
    }

    private function user(string $role): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => ucfirst($role), 'role' => $role,
            'email' => $role.'-'.Str::random(8).'@test.local',
            'password' => bcrypt('secret'), 'status' => 'active',
        ]);
    }

    private function vendor(string $name): Vendor
    {
        return Vendor::create([
            'tenant_id' => self::TENANT, 'company_name' => $name,
            'email' => strtolower($name).'-'.Str::random(4).'@vendor.local',
            'status' => VendorStatus::ACTIVE,
        ]);
    }

    private function folder(Vendor $v, string $name, ?int $parent = null): int
    {
        return $this->postJson("/api/tpv/vendors/{$v->id}/attachment-folders", [
            'name' => $name, 'parent_id' => $parent,
        ])->assertStatus(201)->json('id');
    }

    /**
     * Multipart, not postJson — a file cannot be JSON-encoded. The Accept header
     * keeps validation failures as JSON rather than a redirect.
     */
    private function postFile(Vendor $v, array $payload)
    {
        return $this->post(
            "/api/tpv/vendors/{$v->id}/attachments",
            $payload,
            ['Accept' => 'application/json'],
        );
    }

    private function upload(Vendor $v, string $name = 'notes.pdf', ?int $folder = null, array $extra = []): int
    {
        return $this->postFile($v, array_merge([
            'file' => UploadedFile::fake()->create($name, 12, 'application/pdf'),
            'folder_id' => $folder,
        ], $extra))->assertStatus(201)->json('id');
    }

    /* ── Folders and files ────────────────────────────────────────────── */

    public function test_folders_nest_and_browsing_returns_one_level_with_a_breadcrumb(): void
    {
        $v = $this->vendor('AlphaCo');
        Sanctum::actingAs($this->user('staff'));

        $insurance = $this->folder($v, 'Insurance');
        $y2026     = $this->folder($v, '2026', $insurance);
        $this->upload($v, 'policy.pdf', $y2026);

        // Root shows the top folder only — not the whole tree.
        $root = $this->getJson("/api/tpv/vendors/{$v->id}/attachments")->assertOk()->json();
        $this->assertCount(1, $root['folders']);
        $this->assertCount(0, $root['files']);
        $this->assertSame([], $root['breadcrumbs']);

        // Two levels down, the breadcrumb is the path back out.
        $deep = $this->getJson("/api/tpv/vendors/{$v->id}/attachments?folder_id={$y2026}")->assertOk()->json();
        $this->assertCount(1, $deep['files']);
        $this->assertSame('policy.pdf', $deep['files'][0]['name']);
        $this->assertSame(['Insurance', '2026'], array_column($deep['breadcrumbs'], 'name'));
    }

    public function test_two_folders_cannot_share_a_name_in_the_same_place(): void
    {
        $v = $this->vendor('AlphaCo');
        Sanctum::actingAs($this->user('staff'));

        $this->folder($v, 'Insurance');
        $this->postJson("/api/tpv/vendors/{$v->id}/attachment-folders", ['name' => 'Insurance'])
            ->assertStatus(422);

        // …but the same name one level down is fine, as in any file manager.
        $parent = AttachmentFolder::first()->id;
        $this->postJson("/api/tpv/vendors/{$v->id}/attachment-folders", ['name' => 'Insurance', 'parent_id' => $parent])
            ->assertStatus(201);
    }

    public function test_deleting_a_folder_removes_its_contents_and_their_bytes(): void
    {
        $v = $this->vendor('AlphaCo');
        Sanctum::actingAs($this->user('staff'));

        $top  = $this->folder($v, 'Insurance');
        $sub  = $this->folder($v, '2026', $top);
        $fid  = $this->upload($v, 'policy.pdf', $sub);
        $path = Attachment::withTrashed()->find($fid)->path;

        Storage::disk('attachments')->assertExists($path);

        $this->deleteJson("/api/tpv/vendors/{$v->id}/attachment-folders/{$top}")->assertOk();

        $this->assertNull(AttachmentFolder::find($top));
        $this->assertNull(AttachmentFolder::find($sub));
        $this->assertNull(Attachment::withTrashed()->find($fid));
        // The row going is not enough — the bytes must go too.
        Storage::disk('attachments')->assertMissing($path);
    }

    public function test_a_file_can_be_renamed_and_moved_between_folders(): void
    {
        $v = $this->vendor('AlphaCo');
        Sanctum::actingAs($this->user('staff'));

        $a = $this->folder($v, 'Inbox');
        $b = $this->folder($v, 'Archive');
        $f = $this->upload($v, 'draft.pdf', $a);

        $this->putJson("/api/tpv/vendors/{$v->id}/attachments/{$f}", ['name' => 'final.pdf', 'folder_id' => $b])
            ->assertOk();

        $row = Attachment::find($f);
        $this->assertSame('final.pdf', $row->name);
        $this->assertSame($b, $row->folder_id);
    }

    public function test_a_file_can_be_downloaded(): void
    {
        $v = $this->vendor('AlphaCo');
        Sanctum::actingAs($this->user('staff'));

        $f = $this->upload($v, 'policy.pdf');

        $this->get("/api/tpv/vendors/{$v->id}/attachments/{$f}/download")
            ->assertOk()
            ->assertDownload('policy.pdf');
    }

    public function test_executables_are_refused(): void
    {
        $v = $this->vendor('AlphaCo');
        Sanctum::actingAs($this->user('staff'));

        $this->postFile($v, ['file' => UploadedFile::fake()->create('installer.exe', 10)])
            ->assertStatus(422);

        $this->assertSame(0, Attachment::count());
    }

    /* ── Import sources ───────────────────────────────────────────────── */

    public function test_drive_and_onedrive_files_are_stored_here_with_their_origin_recorded(): void
    {
        $v = $this->vendor('AlphaCo');
        Sanctum::actingAs($this->user('staff'));

        foreach (['google_drive', 'onedrive'] as $source) {
            $id = $this->upload($v, "{$source}.pdf", null, ['source' => $source, 'source_ref' => "ext-{$source}"]);

            $row = Attachment::find($id);
            $this->assertSame($source, $row->source);
            $this->assertSame("ext-{$source}", $row->source_ref);
            // The point of the design: the bytes are OURS, not a link out.
            Storage::disk('attachments')->assertExists($row->path);
        }
    }

    public function test_an_unknown_source_is_rejected(): void
    {
        $v = $this->vendor('AlphaCo');
        Sanctum::actingAs($this->user('staff'));

        $this->postFile($v, [
            'file' => UploadedFile::fake()->create('x.pdf', 5),
            'source' => 'dropbox',
        ])->assertStatus(422);
    }

    /* ── Cross-vendor isolation ───────────────────────────────────────── */

    public function test_one_vendors_files_never_appear_under_another(): void
    {
        $alpha = $this->vendor('AlphaCo');
        $beta  = $this->vendor('BetaCo');

        Sanctum::actingAs($this->user('staff'));

        $this->upload($alpha, 'alpha.pdf');

        $this->getJson("/api/tpv/vendors/{$alpha->id}/attachments")->assertOk()->assertJsonCount(1, 'files');
        $this->getJson("/api/tpv/vendors/{$beta->id}/attachments")->assertOk()->assertJsonCount(0, 'files');
    }

    public function test_another_vendors_file_cannot_be_read_renamed_or_deleted(): void
    {
        $alpha = $this->vendor('AlphaCo');
        $beta  = $this->vendor('BetaCo');

        Sanctum::actingAs($this->user('staff'));
        $f = $this->upload($alpha, 'alpha.pdf');

        // The vendor in the URL is load-bearing, not decoration.
        $this->getJson("/api/tpv/vendors/{$beta->id}/attachments/{$f}/download")->assertStatus(404);
        $this->putJson("/api/tpv/vendors/{$beta->id}/attachments/{$f}", ['name' => 'stolen.pdf'])->assertStatus(404);
        $this->deleteJson("/api/tpv/vendors/{$beta->id}/attachments/{$f}")->assertStatus(404);

        $this->assertSame('alpha.pdf', Attachment::find($f)->name);
    }

    public function test_a_file_cannot_be_filed_into_another_vendors_folder(): void
    {
        $alpha = $this->vendor('AlphaCo');
        $beta  = $this->vendor('BetaCo');

        Sanctum::actingAs($this->user('staff'));
        $betaFolder = $this->folder($beta, 'Beta Only');

        // Uploading INTO a foreign folder, and moving an existing file into one.
        $this->postFile($alpha, [
            'file' => UploadedFile::fake()->create('x.pdf', 5), 'folder_id' => $betaFolder,
        ])->assertStatus(404);

        $f = $this->upload($alpha, 'a.pdf');
        $this->putJson("/api/tpv/vendors/{$alpha->id}/attachments/{$f}", ['folder_id' => $betaFolder])
            ->assertStatus(404);

        $this->assertNull(Attachment::find($f)->folder_id);
    }

    public function test_another_vendors_folder_cannot_be_renamed_or_deleted(): void
    {
        $alpha = $this->vendor('AlphaCo');
        $beta  = $this->vendor('BetaCo');

        Sanctum::actingAs($this->user('staff'));
        $folder = $this->folder($alpha, 'Alpha Only');

        $this->putJson("/api/tpv/vendors/{$beta->id}/attachment-folders/{$folder}", ['name' => 'Hijacked'])
            ->assertStatus(404);
        $this->deleteJson("/api/tpv/vendors/{$beta->id}/attachment-folders/{$folder}")->assertStatus(404);

        $this->assertSame('Alpha Only', AttachmentFolder::find($folder)->name);
    }

    public function test_a_vendor_login_cannot_reach_the_file_area(): void
    {
        $user   = $this->user('third_party_vendor');
        $vendor = $this->vendor('AlphaCo');
        $vendor->update(['user_id' => $user->id]);

        Sanctum::actingAs($user);

        $this->getJson("/api/tpv/vendors/{$vendor->id}/attachments")->assertStatus(403);
        $this->postJson("/api/tpv/vendors/{$vendor->id}/attachment-folders", ['name' => 'x'])->assertStatus(403);
    }
}
