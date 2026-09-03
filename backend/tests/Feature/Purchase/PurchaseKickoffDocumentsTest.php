<?php

namespace Tests\Feature\Purchase;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Purchase\PurchaseVendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Purchase parity — multiple labelled documents on a Purchase kickoff meeting.
 */
class PurchaseKickoffDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill(['id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active'])->save();
    }

    private function admin(): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'a-'.Str::random(6).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    public function test_multiple_labelled_documents_upload_list_and_delete(): void
    {
        Storage::fake('purchase_kickoff_docs');
        Sanctum::actingAs($this->admin());

        $vendor = PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Acme',
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)), 'status' => 'Draft',
        ]);

        $id = $this->postJson('/api/purchase/kickoff', [
            'purchase_vendor_id' => $vendor->id, 'title' => 'Kickoff', 'meeting_type' => 'kickoff',
            'scheduled_at' => now()->addDay()->toDateTimeString(),
            'end_at' => now()->addDay()->addHour()->toDateTimeString(),
        ])->assertCreated()->json('id');

        $this->postJson("/api/purchase/kickoff/{$id}/documents", [
            'files' => [
                UploadedFile::fake()->create('signed-mom.pdf', 20, 'application/pdf'),
                UploadedFile::fake()->create('hse-plan.pdf', 15, 'application/pdf'),
            ],
            'labels' => ['Signed MoM', 'HSE plan'],
        ])->assertCreated();

        $list = $this->getJson("/api/purchase/kickoff/{$id}/documents")->assertOk()->json('data');
        $this->assertCount(2, $list);
        $this->assertContains('Signed MoM', collect($list)->pluck('label')->all());

        $docId = $list[0]['id'];
        $this->deleteJson("/api/purchase/kickoff/{$id}/documents/{$docId}")->assertOk();
        $this->assertCount(1, $this->getJson("/api/purchase/kickoff/{$id}/documents")->json('data'));
    }
}
