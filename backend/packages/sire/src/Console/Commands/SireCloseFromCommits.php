<?php

namespace Sire\Console\Commands;

use Illuminate\Console\Command;
use Sire\Contracts\SireUserProvider;
use Sire\Services\SireBriefImporter;

/**
 * SIRE — close what this deploy actually fixes.
 *
 * THE ZERO-EFFORT OPTION. Ticking boxes in a brief still asks a developer to
 * remember something, and the thing they already do without being asked is
 * write a commit message. So: mention an issue with a closing verb --
 *
 *     fix: guard the null customer on the invoice builder (fixes SIR-000013)
 *
 * -- and the issue closes when that commit REACHES PRODUCTION. Not when it is
 * written, not when it is merged. "Fixed" and "fixed for the people who
 * reported it" are different days, and a register that conflates them tells
 * everyone the backlog is cleaner than it is.
 *
 * A CLOSING VERB, NOT A MENTION. `see SIR-000013` and `same root cause as
 * SIR-000014` must not close anything; people reference issues constantly while
 * discussing them. Only the verbs below count.
 *
 * WHERE THIS RUNS. The deploy rsyncs with --exclude='.git/', so production has
 * no git history and could never work this out for itself. This runs on the
 * machine doing the deploying, where the history is, and talks to whichever
 * database that machine's config points at -- run it over SSH on the server, or
 * locally against the live DB, but run it as part of the deploy.
 *
 * FAILS CLOSED. No tenant, no actor, no range: it does nothing and says why. An
 * automation that closes defect records is not something to leave guessing.
 */
class SireCloseFromCommits extends Command
{
    protected $signature = 'sire:close-from-commits
        {--since= : git ref to start from (default: the commit in build-id.txt)}
        {--until=HEAD : git ref to stop at}
        {--repo= : path to the git checkout (default: the app root)}
        {--tenant= : tenant id the issues belong to}
        {--user= : user id to record as the one who closed them}
        {--apply : actually close them; prints what it would do otherwise}';

    protected $description = 'Close SIRE issues named with a closing verb in the commits being deployed';

    /** Only these count. A bare mention is people talking, not a fix. */
    private const CLOSING = 'fix|fixes|fixed|fixing|close|closes|closed|resolve|resolves|resolved';

    public function handle(SireBriefImporter $importer, SireUserProvider $users): int
    {
        $tenantId = (int) ($this->option('tenant') ?: 0);
        $userId = (int) ($this->option('user') ?: 0);

        if ($tenantId <= 0 || $userId <= 0) {
            $this->error('--tenant and --user are both required: closing an issue is an act, and the audit trail names who did it.');

            return self::FAILURE;
        }

        $actor = $users->lookup($tenantId, $userId);

        if ($actor === null) {
            $this->error("No user {$userId} in tenant {$tenantId}.");

            return self::FAILURE;
        }

        $repo = (string) ($this->option('repo') ?: base_path());
        $since = (string) ($this->option('since') ?: $this->lastDeployed($repo));

        if ($since === '') {
            $this->error('Nothing to compare against. Pass --since, or make sure build-id.txt holds the last deployed commit.');

            return self::FAILURE;
        }

        $commits = $this->commits($repo, $since, (string) $this->option('until'));

        if ($commits === null) {
            $this->error("Could not read git history in {$repo}. Is it a checkout, and is {$since} a real ref?");

            return self::FAILURE;
        }

        $entries = $this->entriesFrom($commits);

        if ($entries === []) {
            $this->info(sprintf('%d commit(s) since %s, none naming an issue with a closing verb.', count($commits), $since));

            return self::SUCCESS;
        }

        $apply = (bool) $this->option('apply');

        $summary = $importer->apply($tenantId, $actor, $entries, ! $apply);

        foreach ($summary['results'] as $row) {
            $this->line(sprintf(
                '  %-9s %-14s %s',
                $row['state'] === 'ready' ? 'would' : $row['state'],
                $row['report_number'],
                $row['reason'] ?? $row['note'],
            ));
        }

        $this->info(sprintf(
            '%s — %d to close, %d already closed, %d could not be.',
            $apply ? 'Closed '.$summary['closed'] : 'Dry run (pass --apply)',
            $apply ? $summary['closed'] : $summary['ready'],
            $summary['skipped'],
            $summary['failed'],
        ));

        return self::SUCCESS;
    }

    /**
     * The issues these commits claim to fix, newest note wins.
     *
     * @param  array<int, array{sha: string, subject: string}>  $commits
     * @return array<int, array{report_number: string, note: string, detailed: bool}>
     */
    private function entriesFrom(array $commits): array
    {
        $found = [];

        foreach ($commits as $commit) {
            if (! preg_match_all(
                '/\b(?:'.self::CLOSING.')\b[\s:#]*([A-Z]{2,8}-\d{3,})/i',
                $commit['subject'],
                $matches,
            )) {
                continue;
            }

            foreach ($matches[1] as $number) {
                $number = strtoupper($number);

                // The commit message IS the resolution note. It was written by
                // the person who made the change, at the moment they made it,
                // and it is better than anything they would type into a box
                // three days later.
                $found[$number] = [
                    'report_number' => $number,
                    'note'          => trim($commit['subject']).' ('.$commit['sha'].')',
                    'detailed'      => true,
                ];
            }
        }

        return array_values($found);
    }

    /**
     * @return array<int, array{sha: string, subject: string}>|null
     */
    private function commits(string $repo, string $since, string $until): ?array
    {
        // --no-pager and a unit separator: a subject can contain anything,
        // including newlines once somebody uses a multi-line -m.
        $cmd = sprintf(
            'git -C %s --no-pager log --no-merges --pretty=format:%%h%%x1f%%s%%x1e %s..%s 2>&1',
            escapeshellarg($repo),
            escapeshellarg($since),
            escapeshellarg($until ?: 'HEAD'),
        );

        exec($cmd, $lines, $status);

        if ($status !== 0) {
            return null;
        }

        $out = [];

        foreach (explode("\x1e", implode("\n", $lines)) as $record) {
            $record = trim($record, "\n\r");

            if ($record === '') {
                continue;
            }

            [$sha, $subject] = array_pad(explode("\x1f", $record, 2), 2, '');

            if ($sha !== '') {
                $out[] = ['sha' => trim($sha), 'subject' => $subject];
            }
        }

        return $out;
    }

    /** The commit currently live, as stamped by deploy.sh. */
    private function lastDeployed(string $repo): string
    {
        foreach ([base_path('build-id.txt'), $repo.'/build-id.txt', $repo.'/backend/build-id.txt'] as $path) {
            if (is_file($path)) {
                return trim((string) file_get_contents($path));
            }
        }

        return '';
    }
}
