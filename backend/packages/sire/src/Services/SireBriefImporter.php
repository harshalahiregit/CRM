<?php

namespace Sire\Services;

use Illuminate\Support\Facades\DB;
use Sire\Dto\SireUserIdentity;
use Sire\Exceptions\SireException;
use Sire\Models\Report;
use Sire\Support\SireStatus;

/**
 * SIRE — the brief, sent back.
 *
 * THE OTHER HALF OF THE EXPORT. The brief takes forty issues out of the system
 * in one file. Until now nothing brought the answers back, so a developer who
 * fixed thirty of them still had thirty pages to open -- which is the cost the
 * export existed to remove, reappearing at the end.
 *
 * So: tick the box under an issue, write one line about what you changed, send
 * the file back. Every ticked issue closes.
 *
 * WHY A CHECKBOX AND NOT AI. The file has been through a developer, an editor,
 * probably a coding assistant. Asking a model "which of these are fixed?" gives
 * an answer that is usually right, which is the worst possible property for
 * something that closes defect records in bulk. A ticked box is a person saying
 * so, it is the same in every file, and when it is wrong you can see why by
 * looking. Nothing here guesses.
 *
 * DELIBERATELY TOLERANT ABOUT EVERYTHING ELSE. The parser only needs two things
 * from a section: the issue number in its heading and whether a box is ticked.
 * Headings may be reworded, notes rewritten, whole paragraphs added or deleted
 * by whatever the file passed through. It must not matter.
 *
 * NOTHING HERE BYPASSES THE WORKFLOW. Each issue runs the same capability check,
 * the same guard and the same required fields as the button, in its own
 * transaction, audited on its own. The file is untrusted input: it decides which
 * issues to *attempt*, never what is allowed.
 */
class SireBriefImporter
{
    /** Same ceiling as the bulk endpoint: a brief is not a migration tool. */
    public const MAX_ISSUES = 100;

    /** A ticked issue with nothing written still has to say something true. */
    private const NO_DETAIL = 'Marked done in the issue brief; no detail was written.';

    public function __construct(private readonly SireWorkflowService $workflow)
    {
    }

    /**
     * Every ticked issue in the file, in the order it appears.
     *
     * @return array<int, array{report_number: string, note: string, detailed: bool}>
     */
    public function parse(string $markdown): array
    {
        // Embedded screenshots are megabytes of base64 that can contain anything.
        // Nothing below needs them and dropping them first keeps the scan cheap.
        $markdown = preg_replace('/data:image\/[a-z.+-]+;base64,[A-Za-z0-9+\/=\s]+/i', '', $markdown) ?? $markdown;

        $lines = preg_split('/\R/', $markdown) ?: [];

        $out = [];
        $current = null;
        $seen = [];

        foreach ($lines as $line) {
            // A new issue heading. The number is what identifies it; everything
            // else on the line is free text and may have been rewritten.
            if (preg_match('/^#{1,6}\s+.*?\b([A-Z]{2,8}-\d{3,})\b/', $line, $m)) {
                $current = $m[1];

                continue;
            }

            // A heading that is not an issue ends the current one, so a tick in
            // the footer cannot be attributed to the last issue above it.
            if ($current !== null && preg_match('/^#{1,6}\s/', $line)) {
                $current = null;

                continue;
            }

            if ($current === null || isset($seen[$current])) {
                continue;
            }

            if (! preg_match('/^\s{0,8}[-*+]\s*\[\s*[xX]\s*\]\s*(.*)$/u', $line, $m)) {
                continue;
            }

            $note = $this->cleanNote($m[1]);

            $seen[$current] = true;
            $out[] = [
                'report_number' => $current,
                'note'          => $note !== '' ? $note : self::NO_DETAIL,
                // Surfaced in the preview so somebody can go back and write one
                // rather than discovering later that the record says nothing.
                'detailed'      => $note !== '',
            ];

            if (count($out) >= self::MAX_ISSUES) {
                break;
            }
        }

        return $out;
    }

    /**
     * What would happen, or what did.
     *
     * A dry run is the default everywhere this is called from. Closing thirty
     * defect records is not something anybody should discover the results of
     * afterwards, and the file was written somewhere this system cannot see.
     *
     * @param  array<int, array{report_number: string, note: string, detailed: bool}>  $entries
     * @return array<string, mixed>
     */
    public function apply(int $tenantId, SireUserIdentity $user, array $entries, bool $dryRun = true): array
    {
        $results = [];

        foreach ($entries as $entry) {
            $results[] = $this->one($tenantId, $user, $entry, $dryRun);
        }

        $count = static fn (string $state) => count(array_filter($results, fn ($r) => $r['state'] === $state));

        return [
            'dry_run' => $dryRun,
            'ready'   => $count('ready'),
            'closed'  => $count('closed'),
            'skipped' => $count('skipped'),
            'failed'  => $count('failed'),
            'results' => $results,
        ];
    }

    /**
     * @param  array{report_number: string, note: string, detailed: bool}  $entry
     * @return array<string, mixed>
     */
    private function one(int $tenantId, SireUserIdentity $user, array $entry, bool $dryRun): array
    {
        $number = $entry['report_number'];

        $base = [
            'report_number' => $number,
            'note'          => $entry['note'],
            'detailed'      => $entry['detailed'],
        ];

        // forTenant, not a bare lookup: an issue number from another workspace
        // must read as "not in this brief's workspace", exactly as it does
        // everywhere else. A file is not a way to reach across a tenant.
        $report = Report::query()
            ->forTenant($tenantId)
            ->where('report_number', $number)
            ->first();

        if ($report === null) {
            return $base + [
                'state'  => 'failed',
                'title'  => null,
                'reason' => 'No issue with that number in your workspace.',
            ];
        }

        $base['title'] = $report->title;
        $base['id'] = (int) $report->id;

        // Already finished. Not a failure -- re-sending a brief after fixing the
        // last few is the normal way to use this, and the ones already closed
        // should say so quietly rather than filling the report with errors.
        if (in_array($report->status, SireStatus::TERMINAL, true)) {
            return $base + [
                'state'  => 'skipped',
                'reason' => 'Already '.SireStatus::label($report->status).'.',
            ];
        }

        if ($dryRun) {
            // The same guard and capability the real call will run, asked as a
            // question. A preview that shows a close which then fails is worse
            // than no preview.
            $allowed = collect($this->workflow->availableFor($report, $user))
                ->contains(fn (array $t) => $t['action'] === 'close_directly');

            return $base + [
                'state'  => $allowed ? 'ready' : 'failed',
                'status' => $report->status,
                'reason' => $allowed ? null : 'You cannot close this issue.',
            ];
        }

        try {
            $fresh = DB::transaction(fn () => $this->workflow->apply(
                $report,
                'close_directly',
                $user,
                ['resolution_note' => $entry['note']],
            ));

            return $base + ['state' => 'closed', 'status' => $fresh->status, 'reason' => null];
        } catch (SireException $e) {
            // A rule said no. That is an answer, and the caller needs to know
            // which issue it was about.
            return $base + ['state' => 'failed', 'reason' => $e->getMessage()];
        } catch (\Throwable $e) {
            report($e);

            return $base + ['state' => 'failed', 'reason' => 'That issue could not be closed.'];
        }
    }

    /**
     * What the developer actually wrote, without the template around it.
     *
     * The line ships as `- [ ] **Done** — say what you changed:`, so a tick with
     * no writing leaves the boilerplate behind. Treating that as a resolution
     * note would fill the register with a sentence the export wrote itself.
     */
    private function cleanNote(string $raw): string
    {
        $note = trim($raw);

        // Drop a leading bold label, whatever it says: **Done**, **Fixed**, …
        $note = preg_replace('/^\*{1,2}[^*]{0,40}\*{1,2}\s*/u', '', $note) ?? $note;

        // Then the separator the template puts after it.
        $note = preg_replace('/^[\s:\-–—·>]+/u', '', $note) ?? $note;

        // The unedited prompt itself is not a note.
        if (preg_match('/^(say what you changed|what changed|tick this|write what you changed)\b/i', $note)) {
            return '';
        }

        return trim($note);
    }
}
