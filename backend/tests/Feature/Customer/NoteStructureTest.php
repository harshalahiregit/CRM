<?php

namespace Tests\Feature\Customer;

use App\Models\Customer\Client;
use App\Models\Customer\ClientNote;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Notes carry when the conversation happened, can be pinned, and can be found.
 *
 * `contacted_at` is distinct from `created_at` on purpose: someone logs Friday's
 * call on Monday, and "when did we last speak to this customer" is unanswerable
 * from the row's timestamp. The legacy CRM had exactly this field
 * (tblnotes.date_contacted); the port dropped it.
 *
 * Seven note types were already stored but nothing filtered on them, so finding
 * the one escalation among forty routine notes meant scrolling.
 */
class NoteStructureTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;
    private User $me;

    protected function setUp(): void
    {
        parent::setUp();

        $t = Tenant::create([
            'name' => 'Acme', 'slug' => 'acme', 'subdomain' => 'acme',
            'plan' => 'professional', 'status' => 'active',
        ]);
        $this->client = Client::create(['tenant_id' => $t->id, 'company' => 'Widget Ltd', 'active' => true]);
        $this->me = User::create([
            'tenant_id' => $t->id, 'name' => 'Zafar', 'role' => 'admin',
            'email' => 'z'.uniqid().'@test.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
        Sanctum::actingAs($this->me);
    }

    private function url(string $q = ''): string
    {
        return "/api/customers/{$this->client->id}/notes".($q ? "?{$q}" : '');
    }

    private function note(array $over = []): ClientNote
    {
        return ClientNote::create(array_merge([
            'tenant_id' => $this->client->tenant_id,
            'client_id' => $this->client->id,
            'content'   => 'Spoke to procurement.',
            'created_by' => $this->me->id,
            'visibility' => 'team',
        ], $over));
    }

    // ── when the conversation happened ───────────────────────────────────────

    public function test_a_note_records_when_the_conversation_happened(): void
    {
        $this->postJson($this->url(), [
            'content'      => 'Call about renewal terms.',
            'type'         => 'Commercial',
            'contacted_at' => '2026-08-21 15:30:00',
        ])->assertCreated();

        $note = ClientNote::first();
        $this->assertSame('2026-08-21 15:30', $note->contacted_at->format('Y-m-d H:i'));
        // Typed today, happened on the 21st — the whole point of the field.
        $this->assertNotSame(
            $note->contacted_at->toDateString(),
            $note->created_at->toDateString(),
        );
    }

    public function test_notes_are_ordered_by_when_they_happened_not_when_typed(): void
    {
        // Written second, but the conversation was older.
        $this->note(['content' => 'Recent call', 'contacted_at' => now()->subDay()]);
        $this->note(['content' => 'Older call',  'contacted_at' => now()->subMonth()]);

        $rows = $this->getJson($this->url())->assertOk()->json();

        $this->assertSame('Recent call', $rows[0]['content']);
    }

    public function test_a_note_with_no_contacted_at_falls_back_to_when_it_was_written(): void
    {
        // COALESCE, so a note without the field is not sorted to the bottom.
        $this->note(['content' => 'No date given']);

        $this->getJson($this->url())->assertOk()->assertJsonFragment(['content' => 'No date given']);
    }

    // ── pinning ──────────────────────────────────────────────────────────────

    public function test_a_pinned_note_comes_first_however_old_it_is(): void
    {
        $this->note(['content' => 'Payment terms agreed', 'is_pinned' => true, 'contacted_at' => now()->subYear()]);
        $this->note(['content' => 'Routine call today', 'contacted_at' => now()]);

        $rows = $this->getJson($this->url())->assertOk()->json();

        $this->assertSame('Payment terms agreed', $rows[0]['content'],
            'a permanently relevant note should not sink under routine ones');
    }

    public function test_pinned_notes_can_be_listed_alone(): void
    {
        $this->note(['content' => 'Pinned one', 'is_pinned' => true]);
        $this->note(['content' => 'Not pinned']);

        $rows = $this->getJson($this->url('pinned=1'))->assertOk()->json();

        $this->assertCount(1, $rows);
        $this->assertSame('Pinned one', $rows[0]['content']);
    }

    // ── finding one among many ───────────────────────────────────────────────

    public function test_notes_can_be_filtered_by_type(): void
    {
        $this->note(['content' => 'The escalation', 'type' => 'Escalation']);
        $this->note(['content' => 'A meeting',      'type' => 'Meeting']);

        $rows = $this->getJson($this->url('type=Escalation'))->assertOk()->json();

        $this->assertCount(1, $rows);
        $this->assertSame('The escalation', $rows[0]['content']);
    }

    public function test_notes_can_be_filtered_by_visibility(): void
    {
        $this->note(['content' => 'Customer sees this', 'visibility' => 'client']);
        $this->note(['content' => 'Team only',          'visibility' => 'team']);

        $rows = $this->getJson($this->url('visibility=client'))->assertOk()->json();

        $this->assertCount(1, $rows);
        $this->assertSame('Customer sees this', $rows[0]['content']);
    }

    public function test_notes_can_be_searched(): void
    {
        $this->note(['content' => 'Discussed the warranty clause']);
        $this->note(['content' => 'Talked about delivery dates']);

        $rows = $this->getJson($this->url('search=warranty'))->assertOk()->json();

        $this->assertCount(1, $rows);
    }

    // ── the filters must not become a way around visibility ──────────────────

    public function test_filtering_never_exposes_another_users_private_note(): void
    {
        $other = User::create([
            'tenant_id' => $this->client->tenant_id, 'name' => 'Someone', 'role' => 'staff',
            'email' => 's'.uniqid().'@test.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
        $this->note(['content' => 'Their private note', 'visibility' => 'private', 'created_by' => $other->id]);

        // Asking for private explicitly must not hand over someone else's.
        $rows = $this->getJson($this->url('visibility=private'))->assertOk()->json();

        $this->assertCount(0, $rows, 'a filter must not be a way around the visibility rule');
    }

    public function test_my_own_private_note_is_still_mine_to_see(): void
    {
        $this->note(['content' => 'My private note', 'visibility' => 'private']);

        $rows = $this->getJson($this->url('visibility=private'))->assertOk()->json();

        $this->assertCount(1, $rows);
    }
}
