<?php

namespace Tests\Feature\Sire;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * SIRE — the brief goes out, the answers come back.
 *
 * The export removed the round trip at the start of the job. Until the import
 * existed, the whole cost reappeared at the end: a developer who fixed thirty
 * issues still had thirty pages to open.
 *
 * What these tests hold down is the boundary. The file has been outside the
 * system -- an editor, a chat, a coding assistant -- so it decides which issues
 * to ATTEMPT and never what is allowed.
 */
class SireBriefImportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private int $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create([
            'name' => 'ACME', 'slug' => 'acme', 'subdomain' => 'acme',
            'plan' => 'professional', 'status' => 'active',
        ]);
        $this->tenantId = $tenant->id;

        $this->admin = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin', 'email' => 'admin@acme.test',
            'password' => bcrypt('password'), 'role' => 'admin',
        ]);

        $this->artisan('sire:seed-defaults', ['--tenant' => $tenant->id]);
        Sanctum::actingAs($this->admin);
    }

    private function issue(string $title): array
    {
        $row = $this->postJson('/api/sire/reports', [
            'title'       => $title,
            'description' => 'Something went wrong on this screen and it needs looking at.',
            'origin'      => 'internal',
            'submit'      => true,
        ])->assertStatus(201)->json('data.report');

        return ['id' => (int) $row['id'], 'number' => (string) $row['report_number']];
    }

    private function brief(): string
    {
        return $this->get('/api/sire/export?scope=open')->assertOk()->getContent();
    }

    private function statusOf(string $number): string
    {
        return (string) DB::table('sire_reports')->where('report_number', $number)->value('status');
    }

    public function test_the_exported_brief_can_be_ticked_and_sent_back(): void
    {
        $a = $this->issue('Invoice footer has a typo');
        $b = $this->issue('Lead save throws a 500');

        // The real document, not a hand-written imitation. If the export stops
        // emitting a tickable line, this test is where it shows up.
        $brief = $this->brief();
        $this->assertStringContainsString('- [ ] **Done**', $brief);

        // Tick only the first one, the way a person would.
        $ticked = $this->tick($brief, $a['number'], 'Fixed the template typo.');

        // --- preview: says what will happen, changes nothing -----------------
        $preview = $this->postJson('/api/sire/reports/import', ['text' => $ticked])->assertOk();

        $this->assertTrue($preview->json('data.dry_run'));
        $this->assertSame(1, $preview->json('data.ready'));
        $this->assertSame('new', $this->statusOf($a['number']), 'a preview must not close anything');

        // --- apply ------------------------------------------------------------
        $applied = $this->postJson('/api/sire/reports/import', ['text' => $ticked, 'apply' => true])->assertOk();

        $this->assertFalse($applied->json('data.dry_run'));
        $this->assertSame(1, $applied->json('data.closed'));

        $this->assertSame('closed', $this->statusOf($a['number']));
        $this->assertSame('new', $this->statusOf($b['number']), 'an unticked issue must be left alone');

        $closed = DB::table('sire_reports')->where('report_number', $a['number'])->first();
        $this->assertStringContainsString('template typo', (string) $closed->resolution_note);
        $this->assertNotNull($closed->closed_at);
    }

    public function test_the_file_can_be_uploaded_rather_than_pasted(): void
    {
        $a = $this->issue('Export button does nothing');
        $ticked = $this->tick($this->brief(), $a['number'], 'Wired the click handler.');

        $file = UploadedFile::fake()->createWithContent('sire-issues.md', $ticked);

        $this->post('/api/sire/reports/import', ['file' => $file, 'apply' => true])
            ->assertOk()
            ->assertJsonPath('data.closed', 1);

        $this->assertSame('closed', $this->statusOf($a['number']));
    }

    /**
     * A REAL file off the disk, not UploadedFile::fake().
     *
     * The fake carries whatever mime you hand it; a browser upload carries what
     * finfo guesses from the bytes, and the endpoint validates with `mimetypes`.
     * A brief with screenshots embedded is base64 megabytes inside a text file,
     * which is exactly the kind of thing that guesses badly — so this uses the
     * real shape rather than trusting that it is text.
     */
    public function test_a_real_markdown_file_with_embedded_screenshots_uploads(): void
    {
        $a = $this->issue('Brief with pictures in it');

        $md = $this->tick($this->brief(), $a['number'], 'Fixed, with evidence attached.')
            ."\n\n![shot](data:image/webp;base64,".base64_encode(random_bytes(40000)).")\n";

        $path = tempnam(sys_get_temp_dir(), 'sire').'.md';
        file_put_contents($path, $md);

        try {
            $this->post('/api/sire/reports/import', [
                // getClientOriginalName/extension as a browser sends them, and
                // the mime left to be guessed from the bytes.
                'file'  => new UploadedFile($path, 'sire-issues-2026-09-22.md', null, null, true),
                'apply' => true,
            ])->assertOk()->assertJsonPath('data.closed', 1);

            $this->assertSame('closed', $this->statusOf($a['number']));
        } finally {
            @unlink($path);
        }
    }

    public function test_sending_the_same_file_twice_is_safe(): void
    {
        $a = $this->issue('Duplicate submit closes twice');
        $ticked = $this->tick($this->brief(), $a['number'], 'Guarded the double submit.');

        $this->postJson('/api/sire/reports/import', ['text' => $ticked, 'apply' => true])
            ->assertOk()->assertJsonPath('data.closed', 1);

        // Re-sending after fixing the last few is the normal way to use this.
        // The already-closed ones must say so quietly, not read as errors.
        $again = $this->postJson('/api/sire/reports/import', ['text' => $ticked, 'apply' => true])->assertOk();

        $this->assertSame(0, $again->json('data.closed'));
        $this->assertSame(1, $again->json('data.skipped'));
        $this->assertSame(0, $again->json('data.failed'));
    }

    public function test_an_untouched_brief_closes_nothing_and_says_why(): void
    {
        $this->issue('Nobody has fixed this yet');

        $response = $this->postJson('/api/sire/reports/import', ['text' => $this->brief(), 'apply' => true])->assertOk();

        $this->assertSame(0, $response->json('data.closed'));
        $this->assertStringContainsString('Nothing is ticked', (string) $response->json('data.message'));
    }

    /**
     * THE IMPORTANT ONE. The file is untrusted input.
     *
     * Anybody can write an issue number into a markdown file. It must not be a
     * way to reach an issue in somebody else's workspace, and the answer has to
     * be the same one the rest of SIRE gives: it does not exist here.
     */
    public function test_a_number_from_another_workspace_is_not_found(): void
    {
        $other = Tenant::create([
            'name' => 'Other', 'slug' => 'other', 'subdomain' => 'other',
            'plan' => 'professional', 'status' => 'active',
        ]);
        $stranger = User::create([
            'tenant_id' => $other->id, 'name' => 'Them', 'email' => 'them@other.test',
            'password' => bcrypt('password'), 'role' => 'admin',
        ]);
        $this->artisan('sire:seed-defaults', ['--tenant' => $other->id]);

        Sanctum::actingAs($stranger);
        $theirs = $this->issue('Their private defect');

        Sanctum::actingAs($this->admin);

        $forged = "### {$theirs['number']} — Their private defect\n\n- [x] **Done** — closing this.\n";

        $response = $this->postJson('/api/sire/reports/import', ['text' => $forged, 'apply' => true])->assertOk();

        $this->assertSame(0, $response->json('data.closed'));
        $this->assertSame(1, $response->json('data.failed'));
        $this->assertSame('new', $this->statusOf($theirs['number']), "another tenant's issue must be untouched");
    }

    public function test_a_tick_with_nothing_written_still_records_something_true(): void
    {
        $a = $this->issue('Ticked but not explained');

        // The boilerplate must not become the resolution note: the register
        // would then be full of a sentence the export wrote about itself.
        $bare = "### {$a['number']} — Ticked but not explained\n\n- [x] **Done** — say what you changed:\n";

        $this->postJson('/api/sire/reports/import', ['text' => $bare, 'apply' => true])
            ->assertOk()
            ->assertJsonPath('data.closed', 1)
            ->assertJsonPath('data.results.0.detailed', false);

        $note = (string) DB::table('sire_reports')->where('report_number', $a['number'])->value('resolution_note');

        $this->assertStringContainsString('no detail was written', $note);
        $this->assertStringNotContainsString('say what you changed', $note);
    }

    /** Tick one issue's box in a real brief and write a note beside it. */
    private function tick(string $brief, string $number, string $note): string
    {
        $lines = preg_split('/\R/', $brief);
        $inSection = false;

        foreach ($lines as $i => $line) {
            if (preg_match('/^###\s/', $line)) {
                $inSection = str_contains($line, $number);

                continue;
            }

            if ($inSection && str_starts_with(trim($line), '- [ ]')) {
                $lines[$i] = '- [x] **Done** — '.$note;
                break;
            }
        }

        return implode("\n", $lines);
    }
}
