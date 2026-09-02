<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrRequestMessage;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Hr\RequestThreadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The conversation attached to a request.
 *
 * SangoeTrack already has a back-and-forth and destroys it — resubmitting a
 * settlement deletes the previous one, so the original figures and the rejection
 * reason are gone. These tests pin the properties that stop that happening again:
 * entries cannot be edited or removed, internal notes never reach the employee,
 * and an event's wording is fixed at the moment it is written.
 *
 * HrEmployee stands in for a request subject here — the advance and reimbursement
 * tables land next, and the thread is deliberately polymorphic so it does not
 * care which it is attached to.
 */
class RequestThreadTest extends TestCase
{
    use RefreshDatabase;

    private RequestThreadService $thread;
    private ?Tenant $t = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->thread = app(RequestThreadService::class);
    }

    private function tenant(): Tenant
    {
        return $this->t ??= Tenant::create(['name' => 'T', 'slug' => 'thr-t', 'status' => 'active']);
    }

    private function subject(): HrEmployee
    {
        return HrEmployee::create([
            'tenant_id' => $this->tenant()->id, 'employee_code' => 'SNE-1',
            'name' => 'Priya', 'department' => 'Ops', 'designation' => 'Analyst',
            'joining_date' => now()->toDateString(), 'status' => 'Active',
        ]);
    }

    private function user(string $email = 'admin@example.test'): User
    {
        return User::create([
            'tenant_id' => $this->tenant()->id, 'name' => 'A', 'email' => $email,
            'password' => Hash::make('Password123!'), 'role' => 'admin', 'status' => 'active',
        ]);
    }

    public function test_messages_notes_and_events_all_land_on_the_thread(): void
    {
        $s = $this->subject();
        $u = $this->user();

        $this->thread->message($s, $u, 'Could you attach the receipt?');
        $this->thread->note($s, $u, 'Third claim this month — worth a word with their manager.');
        $this->thread->event($s, 'held', 'Held: the receipt does not match the amount claimed.', $u, ['reason' => 'mismatch']);

        $all = $this->thread->forSubject($s, asEmployee: false);

        $this->assertCount(3, $all);
        $this->assertSame(['message', 'note', 'event'], $all->pluck('kind')->all());
        $this->assertSame('held', $all->last()->event_type);
        $this->assertSame(['reason' => 'mismatch'], $all->last()->meta);
    }

    /** The employee must never see what admins say to each other. */
    public function test_an_employee_never_sees_internal_notes(): void
    {
        $s = $this->subject();
        $u = $this->user();

        $this->thread->message($s, $u, 'Please attach the receipt.');
        $this->thread->note($s, $u, 'Do not approve this without checking with accounts.');
        $this->thread->event($s, 'held', 'Held pending documents.', $u);

        $employeeView = $this->thread->forSubject($s, asEmployee: true);

        $this->assertCount(2, $employeeView);
        $this->assertNotContains('note', $employeeView->pluck('kind')->all());
        $this->assertStringNotContainsString(
            'checking with accounts',
            $employeeView->pluck('body')->implode(' '),
            'An internal note leaked into the employee view.'
        );
    }

    /** The property everything else rests on. */
    public function test_an_entry_cannot_be_edited(): void
    {
        $entry = $this->thread->message($this->subject(), $this->user(), 'Original wording.');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot be edited');

        $entry->update(['body' => 'Rewritten later.']);
    }

    public function test_an_entry_cannot_be_deleted(): void
    {
        $entry = $this->thread->message($this->subject(), $this->user(), 'Said in the moment.');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot be deleted');

        $entry->delete();
    }

    /** And the original survives the attempt. */
    public function test_the_original_survives_a_failed_edit(): void
    {
        $entry = $this->thread->message($this->subject(), $this->user(), 'Original wording.');

        try {
            $entry->update(['body' => 'Rewritten later.']);
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame('Original wording.', HrRequestMessage::find($entry->id)->body);
    }

    public function test_an_empty_entry_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->thread->message($this->subject(), $this->user(), '   ');
    }

    /**
     * The tenant comes from the SUBJECT, not the actor. Taking it from the actor
     * is how a cross-tenant write happens without anyone noticing.
     */
    public function test_the_tenant_is_taken_from_the_subject(): void
    {
        $other = Tenant::create(['name' => 'Other', 'slug' => 'other-t', 'status' => 'active']);
        $outsider = User::create([
            'tenant_id' => $other->id, 'name' => 'X', 'email' => 'x@example.test',
            'password' => Hash::make('Password123!'), 'role' => 'admin', 'status' => 'active',
        ]);

        $s = $this->subject();
        $entry = $this->thread->message($s, $outsider, 'Written by somebody elsewhere.');

        $this->assertSame($s->tenant_id, $entry->tenant_id);
        $this->assertNotSame($other->id, $entry->tenant_id);
    }

    /** Events keep the wording they were written with, forever. */
    public function test_an_event_stores_its_sentence_rather_than_deriving_it(): void
    {
        $s = $this->subject();
        $entry = $this->thread->event($s, 'amount_changed', 'Approved amount changed from 5,000 to 2,500.', $this->user(), [
            'from' => 5000, 'to' => 2500, 'reason' => 'Receipt supports 2,500.',
        ]);

        $this->assertNotEmpty($entry->body);
        $this->assertSame(5000, $entry->meta['from']);
        $this->assertSame(2500, $entry->meta['to']);

        // Stored, so it reads the same however the code phrases things later.
        $this->assertSame('Approved amount changed from 5,000 to 2,500.', HrRequestMessage::find($entry->id)->body);
    }

    public function test_a_system_event_may_have_no_author(): void
    {
        $entry = $this->thread->event($this->subject(), 'auto_closed', 'Closed automatically after settlement.');

        $this->assertNull($entry->author_id);
        $this->assertSame('event', $entry->kind);
    }

    public function test_the_thread_reads_oldest_first(): void
    {
        $s = $this->subject();
        $u = $this->user();

        foreach (['first', 'second', 'third'] as $body) {
            $this->thread->message($s, $u, $body);
        }

        $this->assertSame(['first', 'second', 'third'],
            $this->thread->forSubject($s, asEmployee: true)->pluck('body')->all());
    }
}
