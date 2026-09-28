<?php

namespace Tests\Feature\Task;

use App\Models\Notification;
use App\Models\Task\Task;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Task comments: pictures in them, and @mentions out of them.
 *
 * Two things were broken and one barely worked.
 *
 *  1. A comment containing an image could not be posted. The box embeds images
 *     inline as base64 — the same rich editor the task description uses — and
 *     the request capped `content` at 5,000 CHARACTERS. One compressed
 *     screenshot is about forty thousand, so every such comment came back 422.
 *     The column behind it was TEXT (65 KB on MySQL), which would not have held
 *     one either.
 *
 *  2. The composer emptied itself the moment the button was pressed, before the
 *     request resolved. So a failed post threw away what had just been written
 *     and put nothing in the thread — from the writer's side the button did
 *     nothing at all. (Covered in the composer, not here.)
 *
 *  3. Mentions were read out of the plain text, matching "@" plus somebody's
 *     full name character for character, with no picker to help write it.
 *     "@Priya" reached nobody; "@Priya Sharma" reached nobody either whenever
 *     the editor put a non-breaking space between the words. Meanwhile a
 *     two-letter name matched inside ordinary words, so "@Drive the update"
 *     notified whoever was called Dr.
 */
class TaskCommentTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private User $author;

    private User $priya;

    private User $rohit;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();

        $this->author = $this->user('admin', 'Super Admin');
        $this->priya = $this->user('staff', 'Priya Sharma');
        $this->rohit = $this->user('staff', 'Rohit Verma');
    }

    private function user(string $role, string $name): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => $name, 'role' => $role,
            'email' => Str::slug($name).'-'.Str::random(4).'@t.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function task(): Task
    {
        return Task::create([
            'tenant_id' => self::TENANT, 'name' => 'Fix pump', 'status' => 'not_started',
            'priority' => 'medium', 'is_public' => true, 'created_by' => $this->author->id,
            'start_date' => '2026-01-01',
        ]);
    }

    /** An inline image the way the editor embeds one. */
    private function inlineImage(int $bytes = 30000): string
    {
        return '<img src="data:image/png;base64,'.base64_encode(random_bytes($bytes)).'" width="600">';
    }

    private function comment(Task $t, string $html)
    {
        Sanctum::actingAs($this->author);

        return $this->postJson("/api/tasks/{$t->id}/comments", ['content' => $html]);
    }

    private function mentionsOf(User $u): int
    {
        return Notification::where('user_id', $u->id)->where('type', 'task.mentioned')->count();
    }

    /* ── a comment with a picture in it ──────────────────────────────────── */

    public function test_a_comment_containing_an_image_can_be_posted(): void
    {
        $t = $this->task();
        $html = '<p>Look at this</p>'.$this->inlineImage();

        $this->assertGreaterThan(5000, strlen($html), 'the fixture must actually exceed the old cap');

        $this->comment($t, $html)->assertCreated();

        $stored = $t->comments()->sole();
        $this->assertStringContainsString('data:image/png;base64,', $stored->content,
            'the image must survive sanitising, not just validation');
    }

    /** Several pictures in one comment, which is what a handover looks like. */
    public function test_several_images_in_one_comment_are_kept(): void
    {
        $t = $this->task();
        $html = '<p>Before and after</p>'.$this->inlineImage().$this->inlineImage();

        $this->comment($t, $html)->assertCreated();

        $this->assertSame(2, substr_count($t->comments()->sole()->content, 'data:image/png;base64,'));
    }

    /**
     * There is still a ceiling, and it says something useful when it is hit.
     *
     * The ceiling is a real 5 MB, so proving it has to send a real 5 MB — and
     * that string is copied several times on its way through the request body,
     * the JSON decode and the validator. Against PHP's default 128 MB that
     * exhausted memory and took the whole suite down with it, which is a worse
     * outcome than a slow test. Raised here rather than lowering the limit,
     * because the limit is the thing under test.
     */
    public function test_an_absurdly_large_comment_is_refused_with_a_readable_reason(): void
    {
        // Restored in `finally`, because PHPUnit runs the whole suite in ONE
        // process and `ini_set` is never rolled back: without this, every test
        // that runs after this one inherits the cap. On a CLI default of -1
        // (unlimited) that LOWERS the ceiling for the rest of the suite, which
        // is why Person 1 was having to exclude this test to get a clean run.
        //
        // `finally` rather than a line after the assertion: this test is about
        // something failing, and a plain restore never runs when it does.
        $old = ini_get('memory_limit');
        ini_set('memory_limit', '512M');

        try {
            $t = $this->task();

            $this->comment($t, str_repeat('x', 5_000_001))
                ->assertStatus(422)
                ->assertJsonPath('errors.content.0', 'This comment is too large to post. Try fewer or smaller images.');
        } finally {
            ini_set('memory_limit', $old);
        }
    }

    /* ── mentions: the picker's marker ───────────────────────────────────── */

    /**
     * What the picker inserts. An id, so it survives a rename, a middle name,
     * a nickname, and whichever kind of space the editor used.
     */
    public function test_a_picked_mention_notifies_that_person(): void
    {
        $t = $this->task();

        $this->comment($t, '<p>Hi <span class="mention-chip" data-mention="'.$this->priya->id.'">@Priya Sharma</span></p>')
            ->assertCreated();

        $this->assertSame(1, $this->mentionsOf($this->priya));
        $this->assertSame(0, $this->mentionsOf($this->rohit));
    }

    /** And the marker survives the sanitizer, or the next read finds nothing. */
    public function test_the_mention_marker_is_not_stripped_on_the_way_in(): void
    {
        $t = $this->task();

        $this->comment($t, '<p><span class="mention-chip" data-mention="'.$this->priya->id.'">@Priya Sharma</span></p>')
            ->assertCreated();

        $this->assertStringContainsString('data-mention="'.$this->priya->id.'"', $t->comments()->sole()->content);
    }

    /**
     * An id in the markup is a claim, not a fact — it arrives from a browser.
     * A vendor is not mentionable however the request is shaped.
     */
    public function test_a_marker_naming_an_external_role_notifies_nobody(): void
    {
        $t = $this->task();
        $vendor = $this->user('third_party_vendor', 'Acme Contractors');

        $this->comment($t, '<p><span data-mention="'.$vendor->id.'">@Acme Contractors</span></p>')
            ->assertCreated();

        $this->assertSame(0, $this->mentionsOf($vendor));
    }

    public function test_mentioning_yourself_notifies_nobody(): void
    {
        $t = $this->task();

        $this->comment($t, '<p><span data-mention="'.$this->author->id.'">@Super Admin</span></p>')
            ->assertCreated();

        $this->assertSame(0, $this->mentionsOf($this->author));
    }

    /* ── mentions: typed by hand ─────────────────────────────────────────── */

    /** People type what they call each other, which is rarely the full record. */
    public function test_a_first_name_mention_notifies(): void
    {
        $t = $this->task();

        $this->comment($t, '<p>@Priya can you look at this?</p>')->assertCreated();

        $this->assertSame(1, $this->mentionsOf($this->priya));
    }

    /**
     * A browser puts a non-breaking space between words routinely, and the old
     * matcher compared against an ordinary one — so the full name, typed
     * correctly, reached nobody.
     */
    public function test_a_non_breaking_space_between_names_still_notifies(): void
    {
        $t = $this->task();

        $this->comment($t, '<p>Hi @Priya&nbsp;Sharma</p>')->assertCreated();

        $this->assertSame(1, $this->mentionsOf($this->priya));
    }

    public function test_two_people_named_in_one_comment_are_both_notified(): void
    {
        $t = $this->task();

        $this->comment($t, '<p>@Priya Sharma and @Rohit Verma please review</p>')->assertCreated();

        $this->assertSame(1, $this->mentionsOf($this->priya));
        $this->assertSame(1, $this->mentionsOf($this->rohit));
    }

    /**
     * Names were matched as substrings, so a short one matched inside ordinary
     * words: a colleague called "Dr" was notified by "@Drive the update over".
     */
    public function test_a_short_name_inside_a_word_does_not_notify(): void
    {
        $t = $this->task();
        $dr = $this->user('staff', 'Dr');

        $this->comment($t, '<p>@Drive the update over to staging</p>')->assertCreated();

        $this->assertSame(0, $this->mentionsOf($dr));
    }

    /** Nor does a longer name matched only as a prefix of a different one. */
    public function test_a_name_that_is_a_prefix_of_another_word_does_not_notify(): void
    {
        $t = $this->task();
        $ann = $this->user('staff', 'Anna');

        $this->comment($t, '<p>@Annabel is not on this team</p>')->assertCreated();

        $this->assertSame(0, $this->mentionsOf($ann));
    }

    /** Named once, told once — not once per way of spotting it. */
    public function test_a_person_both_picked_and_typed_is_notified_once(): void
    {
        $t = $this->task();

        $this->comment($t, '<p><span data-mention="'.$this->priya->id.'">@Priya Sharma</span> — @Priya Sharma again</p>'
        )->assertCreated();

        $this->assertSame(1, $this->mentionsOf($this->priya));
    }

    /**
     * The mention reaches the bell the person actually looks at.
     *
     * Writing a row is not the same as it arriving: this walks the whole way,
     * from posting the comment to the mentioned colleague opening their own
     * notification feed and finding it there, unread, with a link back.
     */
    public function test_a_mention_arrives_in_the_recipients_in_app_notifications(): void
    {
        $t = $this->task();

        $this->comment($t, '<p>Hi <span data-mention="'.$this->priya->id.'">@Priya Sharma</span>, please review</p>')
            ->assertCreated();

        Sanctum::actingAs($this->priya);
        $res = $this->getJson('/api/notifications')->assertOk();

        $this->assertGreaterThanOrEqual(1, $res->json('data.unread_count'));

        $mine = collect($res->json('data.items'))->firstWhere('type', 'task.mentioned');
        $this->assertNotNull($mine, 'the mention must be in the recipient\'s own feed');
        $this->assertSame("Super Admin mentioned you on: {$t->name}", $mine['title']);
        $this->assertSame("/app/tasks/{$t->id}", $mine['link'], 'and take them to the task it was about');
    }

    /** Somebody else's mention is not in this person's feed. */
    public function test_a_mention_is_only_in_the_mentioned_persons_feed(): void
    {
        $t = $this->task();

        $this->comment($t, '<p><span data-mention="'.$this->priya->id.'">@Priya Sharma</span></p>')
            ->assertCreated();

        Sanctum::actingAs($this->rohit);
        $items = collect($this->getJson('/api/notifications')->assertOk()->json('data.items'));

        $this->assertSame(0, $items->where('type', 'task.mentioned')->count());
    }

    /** A comment naming nobody notifies nobody. */
    public function test_a_comment_with_no_mention_notifies_nobody(): void
    {
        $t = $this->task();

        $this->comment($t, '<p>Just a note for the record.</p>')->assertCreated();

        $this->assertSame(0, Notification::where('type', 'task.mentioned')->count());
    }
}
