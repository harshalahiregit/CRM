<?php

namespace Tests\Feature\Sire;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * SIRE — closing from the commits that are going live.
 *
 * The zero-effort path: nobody ticks anything, nobody opens a page. A commit
 * says what it fixes, and the issue closes when that commit reaches production.
 *
 * The line these tests hold is the one between MENTIONING an issue and CLOSING
 * it. People reference issue numbers constantly while discussing them, and a
 * register that closes on a mention is worse than one that closes on nothing.
 */
class SireCloseFromCommitsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private int $tenantId;
    private string $repo;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create([
            'name' => 'ACME', 'slug' => 'acme', 'subdomain' => 'acme',
            'plan' => 'professional', 'status' => 'active',
        ]);
        $this->tenantId = $tenant->id;

        $this->admin = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Admin', 'email' => 'admin@acme.test',
            'password' => bcrypt('password'), 'role' => 'admin',
        ]);

        $this->artisan('sire:seed-defaults', ['--tenant' => $tenant->id]);
        Sanctum::actingAs($this->admin);

        $this->repo = $this->makeRepo();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->repo)) {
            $this->rmrf($this->repo);
        }

        parent::tearDown();
    }

    private function issue(string $title): string
    {
        return (string) $this->postJson('/api/sire/reports', [
            'title' => $title,
            'description' => 'Something went wrong on this screen and it needs looking at.',
            'origin' => 'internal', 'submit' => true,
        ])->assertStatus(201)->json('data.report.report_number');
    }

    private function statusOf(string $number): string
    {
        return (string) DB::table('sire_reports')->where('report_number', $number)->value('status');
    }

    /** A real git repository, because the command shells out to a real git. */
    private function makeRepo(): string
    {
        $dir = sys_get_temp_dir().'/sire-commits-'.bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);

        $run = function (string $cmd) use ($dir) {
            exec(sprintf('git -C %s %s 2>&1', escapeshellarg($dir), $cmd), $out, $status);

            return $status;
        };

        exec(sprintf('git init -q %s 2>&1', escapeshellarg($dir)));
        $run('config user.email test@example.com');
        $run('config user.name Test');
        $run('config commit.gpgsign false');

        file_put_contents($dir.'/a.txt', 'one');
        $run('add -A');
        $run('commit -q -m "chore: the starting point"');

        return $dir;
    }

    private function commit(string $subject): void
    {
        file_put_contents($this->repo.'/a.txt', bin2hex(random_bytes(6)));
        exec(sprintf('git -C %s add -A 2>&1', escapeshellarg($this->repo)));
        exec(sprintf('git -C %s commit -q -m %s 2>&1', escapeshellarg($this->repo), escapeshellarg($subject)));

        $this->commitsMade += 1;
    }

    private function closeFromCommits(array $extra = []): void
    {
        $this->artisan('sire:close-from-commits', array_merge([
            '--repo'   => $this->repo,
            '--since'  => 'HEAD~'.$this->commitsMade,
            '--tenant' => $this->tenantId,
            '--user'   => $this->admin->id,
        ], $extra));
    }

    private int $commitsMade = 0;

    public function test_a_closing_verb_closes_the_issue_and_the_message_is_the_reason(): void
    {
        $number = $this->issue('Invoice builder throws on a null customer');

        $this->commit("fix: guard the null customer on the invoice builder (fixes {$number})");

        $this->closeFromCommits(['--apply' => true]);

        $this->assertSame('closed', $this->statusOf($number));

        // The commit message is the best resolution note available: written by
        // the person who made the change, at the moment they made it.
        $note = (string) DB::table('sire_reports')->where('report_number', $number)->value('resolution_note');
        $this->assertStringContainsString('guard the null customer', $note);
    }

    /** THE IMPORTANT ONE. Talking about an issue is not fixing it. */
    public function test_a_bare_mention_closes_nothing(): void
    {
        $number = $this->issue('Still very much open');

        $this->commit("refactor: tidy the invoice builder, see {$number} for context");
        $this->commit("docs: note that {$number} and others share a root cause");

        $this->closeFromCommits(['--apply' => true]);

        $this->assertSame('new', $this->statusOf($number), 'a mention must never close an issue');
    }

    public function test_it_previews_unless_told_to_apply(): void
    {
        $number = $this->issue('Should survive a dry run');

        $this->commit("fix: something real (fixes {$number})");

        $this->closeFromCommits();

        $this->assertSame('new', $this->statusOf($number), 'without --apply nothing may change');
    }

    public function test_it_refuses_to_run_without_an_actor(): void
    {
        // Closing an issue is an act and the audit trail names who did it.
        // Guessing an actor would put a name on something nobody did.
        $this->artisan('sire:close-from-commits', [
            '--repo'  => $this->repo,
            '--since' => 'HEAD',
            '--apply' => true,
        ])->assertExitCode(1);
    }

    public function test_an_issue_number_from_another_workspace_is_not_touched(): void
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

        $this->commit("fix: reach across the tenant boundary (fixes {$theirs})");

        $this->closeFromCommits(['--apply' => true]);

        $this->assertSame('new', $this->statusOf($theirs), "another tenant's issue must be untouched");
    }

    private function rmrf(string $dir): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @chmod($item->getPathname(), 0666) && @unlink($item->getPathname());
        }

        @rmdir($dir);
    }
}
