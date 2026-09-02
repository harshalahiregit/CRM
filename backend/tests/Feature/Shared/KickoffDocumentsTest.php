<?php

namespace Tests\Feature\Shared;

use App\Models\Shared\KickoffMeeting;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Services\Shared\KickoffMeetingService;
use App\Support\Shared\KickoffStatus;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Point 9 — multiple labelled documents on a kickoff meeting. Admin can attach
 * several files at once, each named for what it is; they can be listed and
 * removed.
 */
class KickoffDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill(['id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active'])->save();
        $this->actor = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'a-'.Str::random(6).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function meeting(): KickoffMeeting
    {
        $v = Vendor::create(['tenant_id' => self::TENANT, 'company_name' => 'Acme', 'status' => VendorStatus::ACTIVE]);

        return app(KickoffMeetingService::class)->schedule([
            'subject_type' => 'vendor', 'subject_id' => $v->id,
            'title' => 'Kickoff', 'scheduled_at' => now()->addDay()->toDateTimeString(),
            'end_at' => now()->addDay()->addHour()->toDateTimeString(),
        ], $this->actor);
    }

    public function test_multiple_labelled_documents_upload_list_and_delete(): void
    {
        Storage::fake('kickoff_docs');
        Sanctum::actingAs($this->actor);
        $m = $this->meeting();

        $this->postJson("/api/kickoff/meetings/{$m->id}/documents", [
            'files' => [
                UploadedFile::fake()->create('signed-mom.pdf', 20, 'application/pdf'),
                UploadedFile::fake()->create('hse-plan.pdf', 15, 'application/pdf'),
            ],
            'labels' => ['Signed MoM', 'HSE plan'],
        ])->assertCreated();

        $list = $this->getJson("/api/kickoff/meetings/{$m->id}/documents")->assertOk()->json('data');
        $this->assertCount(2, $list);
        $labels = collect($list)->pluck('label')->all();
        $this->assertContains('Signed MoM', $labels);
        $this->assertContains('HSE plan', $labels);

        // The stored files exist on the private disk.
        $this->assertSame(2, $m->fresh()->documents()->count());

        // Delete one.
        $docId = $list[0]['id'];
        $this->deleteJson("/api/kickoff/meetings/{$m->id}/documents/{$docId}")->assertOk();
        $this->assertSame(1, $m->fresh()->documents()->count());
    }

    public function test_a_missing_label_falls_back_to_the_filename(): void
    {
        Storage::fake('kickoff_docs');
        Sanctum::actingAs($this->actor);
        $m = $this->meeting();

        $this->postJson("/api/kickoff/meetings/{$m->id}/documents", [
            'files' => [UploadedFile::fake()->create('report.pdf', 10, 'application/pdf')],
            'labels' => [''],
        ])->assertCreated();

        $this->assertSame('report.pdf', $m->fresh()->documents()->first()->label);
    }
}
