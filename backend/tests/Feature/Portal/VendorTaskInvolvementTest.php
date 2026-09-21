<?php

namespace Tests\Feature\Portal;

use App\Models\Notification;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Purchase\PurchaseVendorNotification;
use App\Models\Task\Task;
use App\Models\Task\TaskComment;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Services\StatusService;
use App\Services\Task\TaskService;
use App\Support\Purchase\PurchaseVendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A vendor is told about its work, and can answer on it.
 *
 * Three things were broken and are covered here together, because they are one
 * complaint: "the vendor was not notified, and on the portal the task is just
 * shown — how do they get involved?"
 *
 *  1. Filing a task AGAINST a vendor (rel_type/rel_id) notified nobody. Only
 *     assignment did, and assignment needs a User — which a Purchase vendor has
 *     not got, by design.
 *  2. A status change from a portal went through a bare update(), so the admin
 *     side was never told the work had moved.
 *  3. The portal had no task detail at all: no brief, no conversation, no files.
 */
class VendorTaskInvolvementTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private PurchaseVendor $vendor;
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active',
        ])->save();

        $this->staff = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Staff Sam', 'role' => 'admin',
            'email' => 'sam@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);

        $this->vendor = PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Bolt Supplies',
            'purchase_vendor_code' => 'PV-'.uniqid(),
            'status' => PurchaseVendorStatus::ACTIVE, 'portal_status' => 'active',
        ]);
        $this->markOnboarded($this->vendor);
    }

    private function task(array $overrides = []): Task
    {
        return app(TaskService::class)->create(array_merge([
            'name'       => 'Deliver the bolts',
            'description' => '<p>Ten crates, gate 3.</p>',
            'start_date' => '2026-01-01',
            'priority'   => 'high',
            'rel_type'   => 'purchase_vendor',
            'rel_id'     => $this->vendor->id,
        ], $overrides), self::TENANT, $this->staff->id);
    }

    /* ── 1. The link itself notifies ─────────────────────────────────────── */

    public function test_filing_a_task_against_a_purchase_vendor_rings_its_bell(): void
    {
        $task = $this->task();

        $row = PurchaseVendorNotification::where('purchase_vendor_id', $this->vendor->id)->first();

        $this->assertNotNull($row, 'A task filed against a purchase vendor must notify it.');
        $this->assertSame('task.vendor_linked', $row->type);
        $this->assertStringContainsString($task->name, $row->title);
        // The bell is read inside the portal, so the link has to be a portal one.
        $this->assertSame('/purchase-portal/tasks', $row->link);
    }

    public function test_a_tpv_is_notified_through_its_portal_logins(): void
    {
        $rep = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Vendor Rep', 'role' => 'third_party_vendor',
            'email' => 'rep@tpv.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
        $tpv = Vendor::create([
            'tenant_id' => self::TENANT, 'vendor_code' => 'V-'.uniqid(),
            'company_name' => 'Crane Hire Ltd', 'user_id' => $rep->id,
        ]);
        $this->markOnboarded($tpv);

        $this->task(['rel_type' => 'tpv_vendor', 'rel_id' => $tpv->id]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $rep->id,
            'type'    => 'task.vendor_linked',
        ]);
    }

    public function test_an_inactive_login_is_not_rung(): void
    {
        $gone = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Retired Rep', 'role' => 'third_party_vendor',
            'email' => 'gone@tpv.local', 'password' => bcrypt('x'), 'status' => 'inactive',
        ]);
        $tpv = Vendor::create([
            'tenant_id' => self::TENANT, 'vendor_code' => 'V-'.uniqid(),
            'company_name' => 'Ghost Ltd', 'user_id' => $gone->id,
        ]);
        $this->markOnboarded($tpv);

        $this->task(['rel_type' => 'tpv_vendor', 'rel_id' => $tpv->id]);

        // A deactivated account cannot open the portal, so a bell row for it is a
        // message nobody will ever read.
        $this->assertDatabaseMissing('notifications', ['user_id' => $gone->id, 'type' => 'task.vendor_linked']);
    }

    public function test_editing_an_already_linked_task_does_not_re_announce_it(): void
    {
        $task = $this->task();
        $before = PurchaseVendorNotification::where('purchase_vendor_id', $this->vendor->id)->count();

        app(TaskService::class)->update($task->id, ['name' => 'Deliver the bolts (revised)'], self::TENANT, $this->staff->id);

        $this->assertSame(
            $before,
            PurchaseVendorNotification::where('purchase_vendor_id', $this->vendor->id)->count(),
            'Only a genuine change of link may notify; editing another field must not.'
        );
    }

    public function test_moving_the_link_to_another_vendor_notifies_the_new_one(): void
    {
        $task = $this->task();

        $other = PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Rival Supplies',
            'purchase_vendor_code' => 'PV-'.uniqid(),
            'status' => PurchaseVendorStatus::ACTIVE, 'portal_status' => 'active',
        ]);
        $this->markOnboarded($other);

        app(TaskService::class)->update($task->id, ['rel_id' => $other->id], self::TENANT, $this->staff->id);

        $this->assertDatabaseHas('purchase_vendor_notifications', [
            'purchase_vendor_id' => $other->id,
            'type'               => 'task.vendor_linked',
        ]);
    }

    /* ── 2. The status write is no longer silent ─────────────────────────── */

    public function test_a_portal_status_change_tells_the_admin_side(): void
    {
        $task = $this->task();
        app(TaskService::class)->syncAssignees($task->id, [$this->staff->id], self::TENANT, $this->staff->id);

        $keys = app(StatusService::class)->keys('task', self::TENANT);
        $next = collect($keys)->first(fn ($k) => $k !== $task->status) ?? 'in_progress';

        Sanctum::actingAs($this->vendor);
        $this->patchJson("/api/portal/purchase/tasks/{$task->id}/status", ['status' => $next])
            ->assertOk()->assertJsonPath('data.status', $next);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->staff->id,
            'type'    => 'task.status_changed',
        ]);
    }

    /* ── 3. The task is readable, and answerable ─────────────────────────── */

    public function test_the_portal_serves_the_whole_task_not_five_fields(): void
    {
        $task = $this->task();
        app(TaskService::class)->addComment($task->id, '<p>Gate 3 is closed on Tuesdays.</p>', self::TENANT, $this->staff->id);

        Sanctum::actingAs($this->vendor);
        $body = $this->getJson("/api/portal/purchase/work-tasks/{$task->id}")->assertOk()->json('data');

        $this->assertSame('Deliver the bolts', $body['name']);
        $this->assertStringContainsString('Ten crates', $body['description']);
        $this->assertStringContainsString('Gate 3', $body['comments'][0]['body']);
        $this->assertSame('Staff Sam', $body['comments'][0]['author']);
        $this->assertFalse($body['comments'][0]['is_vendor']);
    }

    public function test_a_purchase_vendor_writes_into_the_same_thread(): void
    {
        $task = $this->task();

        Sanctum::actingAs($this->vendor);
        $this->postJson("/api/portal/purchase/work-tasks/{$task->id}/comments", [
            'body' => 'Crates are loaded, leaving at 6am.',
        ])->assertCreated();

        $comment = TaskComment::where('task_id', $task->id)->firstOrFail();

        // No User row is invented for the company, and the name is readable on
        // the staff side without joining anything.
        $this->assertNull($comment->user_id);
        $this->assertSame('purchase_vendor', $comment->author_kind);
        $this->assertSame((int) $this->vendor->id, (int) $comment->author_id);
        $this->assertSame('Bolt Supplies', $comment->author_label);
        $this->assertTrue($comment->is_vendor_author);

        // And the staff console reads it from the ordinary thread.
        $thread = app(TaskService::class)->listComments($task->id, self::TENANT);
        $this->assertCount(1, $thread);
        $this->assertSame('Bolt Supplies', $thread->first()->author_label);
    }

    public function test_the_vendors_comment_notifies_the_people_on_the_task(): void
    {
        $task = $this->task();
        app(TaskService::class)->syncAssignees($task->id, [$this->staff->id], self::TENANT, $this->staff->id);
        Notification::query()->delete();

        Sanctum::actingAs($this->vendor);
        $this->postJson("/api/portal/purchase/work-tasks/{$task->id}/comments", ['body' => 'Running late.'])
            ->assertCreated();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->staff->id,
            'type'    => 'task.commented',
        ]);
    }

    public function test_a_vendor_may_attach_a_file_and_download_one(): void
    {
        $task = $this->task();

        Sanctum::actingAs($this->vendor);
        $this->postJson("/api/portal/purchase/work-tasks/{$task->id}/comments", [
            'body'  => 'Signed delivery note.',
            'files' => [UploadedFile::fake()->create('note.pdf', 12, 'application/pdf')],
        ])->assertCreated();

        $detail = $this->getJson("/api/portal/purchase/work-tasks/{$task->id}")->assertOk()->json('data');
        $file = $detail['comments'][0]['attachments'][0];
        $this->assertSame('note.pdf', $file['name']);

        $this->get("/api/portal/purchase/work-tasks/{$task->id}/files/{$file['id']}")->assertOk();
    }

    public function test_an_empty_post_is_not_a_contribution(): void
    {
        $task = $this->task();

        Sanctum::actingAs($this->vendor);
        $this->postJson("/api/portal/purchase/work-tasks/{$task->id}/comments", [])
            ->assertStatus(422);
    }

    /* ── The TPV portal, which is a different controller entirely ────────── */

    public function test_a_tpv_reads_and_answers_its_own_task(): void
    {
        $rep = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Vendor Rep', 'role' => 'third_party_vendor',
            'email' => 'rep@tpv.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
        $tpv = Vendor::create([
            'tenant_id' => self::TENANT, 'vendor_code' => 'V-'.uniqid(),
            'company_name' => 'Crane Hire Ltd', 'user_id' => $rep->id,
        ]);
        $this->markOnboarded($tpv);
        $task = $this->task(['rel_type' => 'tpv_vendor', 'rel_id' => $tpv->id]);

        Sanctum::actingAs($rep);

        $body = $this->getJson("/api/portal/my-work/tasks/{$task->id}")->assertOk()->json('data');
        $this->assertStringContainsString('Ten crates', $body['description']);

        $this->postJson("/api/portal/my-work/tasks/{$task->id}/comments", ['body' => 'Crane booked for Friday.'])
            ->assertCreated();

        // A TPV IS a User, so it authors as itself -- no polymorphic author is
        // needed, and the staff console shows a person's name.
        $comment = TaskComment::where('task_id', $task->id)->firstOrFail();
        $this->assertSame($rep->id, $comment->user_id);
        $this->assertSame('user', $comment->author_kind);
        $this->assertSame('Vendor Rep', $comment->author_label);
        $this->assertFalse($comment->is_vendor_author);
    }

    public function test_a_tpv_cannot_reach_a_task_that_is_not_theirs(): void
    {
        $rep = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Vendor Rep', 'role' => 'third_party_vendor',
            'email' => 'rep@tpv.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
        Vendor::create([
            'tenant_id' => self::TENANT, 'vendor_code' => 'V-'.uniqid(),
            'company_name' => 'Crane Hire Ltd', 'user_id' => $rep->id,
        ]);

        // Filed against the PURCHASE vendor, and assigned to nobody.
        $task = $this->task();

        Sanctum::actingAs($rep);
        $this->getJson("/api/portal/my-work/tasks/{$task->id}")->assertNotFound();
        $this->postJson("/api/portal/my-work/tasks/{$task->id}/comments", ['body' => 'hi'])->assertNotFound();
    }

    public function test_a_tpv_status_change_also_tells_the_admin_side(): void
    {
        $rep = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Vendor Rep', 'role' => 'third_party_vendor',
            'email' => 'rep@tpv.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
        $tpv = Vendor::create([
            'tenant_id' => self::TENANT, 'vendor_code' => 'V-'.uniqid(),
            'company_name' => 'Crane Hire Ltd', 'user_id' => $rep->id,
        ]);
        $this->markOnboarded($tpv);
        $task = $this->task(['rel_type' => 'tpv_vendor', 'rel_id' => $tpv->id]);
        app(TaskService::class)->syncAssignees($task->id, [$this->staff->id], self::TENANT, $this->staff->id);

        $keys = app(StatusService::class)->keys('task', self::TENANT);
        $next = collect($keys)->first(fn ($k) => $k !== $task->status) ?? 'in_progress';

        Sanctum::actingAs($rep);
        $this->patchJson("/api/portal/my-work/tasks/{$task->id}/status", ['status' => $next])->assertOk();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->staff->id,
            'type'    => 'task.status_changed',
        ]);
    }

    /* ── Ownership, on every one of the new routes ───────────────────────── */

    public function test_a_vendor_cannot_reach_another_vendors_task(): void
    {
        $other = PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Rival Supplies',
            'purchase_vendor_code' => 'PV-'.uniqid(),
            'status' => PurchaseVendorStatus::ACTIVE, 'portal_status' => 'active',
        ]);
        $this->markOnboarded($other);
        $task = $this->task(['rel_id' => $other->id]);

        Sanctum::actingAs($this->vendor);
        $this->getJson("/api/portal/purchase/work-tasks/{$task->id}")->assertNotFound();
        $this->postJson("/api/portal/purchase/work-tasks/{$task->id}/comments", ['body' => 'hi'])->assertNotFound();
        $this->get("/api/portal/purchase/work-tasks/{$task->id}/files/1")->assertNotFound();
    }
}
