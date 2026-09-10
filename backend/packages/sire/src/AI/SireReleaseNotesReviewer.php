<?php

namespace Sire\AI;

use Sire\Models\Release;
use Sire\Models\Report;
use Sire\Support\SireReleaseClass;
use Sire\Support\SireStatus;

/**
 * SIRE — AI-assisted release notes.
 *
 * WHAT IT HONESTLY DOES, AND WHY
 *
 * Without a language model, SIRE cannot rewrite prose — so it does not pretend to.
 * It REVIEWS instead: it reads the issues that shipped and reports, concretely,
 * what will go wrong when the notes are published.
 *
 *   - which shipped issues have no customer-facing summary and will therefore be
 *     silently omitted from the user-facing document
 *   - which customer-facing summaries contain internal jargon — identifiers, stack
 *     symbols, module codes — that should not reach a customer
 *   - which are so terse they say nothing
 *   - how many security fixes are being counted rather than described
 *
 * That is genuinely more useful than generated prose would be. The structured
 * generator (SireReleaseNotesService) already assembles the document from columns;
 * what a human actually needs is a list of the entries that will read badly, and
 * that is a checkable list rather than a guess.
 *
 * ASSISTIVE ONLY. It writes nothing. Publication still requires the approval
 * gate from D19 — generate, submit, approve, publish — and this only ever
 * produces a suggestion attached to the release.
 */
class SireReleaseNotesReviewer
{
    /** A summary shorter than this tells a customer nothing. */
    private const MIN_USEFUL_SUMMARY = 20;

    /** Shapes that mean internal vocabulary leaked into a customer-facing line. */
    private const JARGON_PATTERNS = [
        '/\b[A-Z][a-z]+[A-Z]\w*/' => 'a CamelCase identifier',
        '/::/'                    => 'a code reference (::)',
        '/\$\w+/'                 => 'a variable name',
        '/\b(null|undefined|nullptr|NaN)\b/i' => 'a programming term',
        '/\b\w+\(\)/'             => 'a function call',
        '/\b(stack ?trace|exception|deref|segfault|regex)\b/i' => 'implementation vocabulary',
        '/\b(SIR-\d+)\b/'         => 'an internal issue number',
    ];

    public function review(Release $release, iterable $issues): array
    {
        $findings = [];
        $counts = ['shipped' => 0, 'announceable' => 0, 'missing_summary' => 0, 'security' => 0, 'excluded' => 0];

        foreach ($issues as $issue) {
            $counts['shipped']++;

            if (! $issue->include_in_release_notes) {
                $counts['excluded']++;

                continue;
            }

            $class = $issue->releaseClass();

            if (in_array($class, SireReleaseClass::REDACTED_FOR_USERS, true)) {
                // Counted, never described — see D24. Not a finding, just a fact
                // the reviewer reports so nobody is surprised by the wording.
                $counts['security']++;

                continue;
            }

            $summary = trim((string) $issue->user_facing_summary);

            if ($summary === '') {
                $counts['missing_summary']++;
                $findings[] = $this->finding(
                    'missing_summary', 'high', $issue,
                    'Will be left out of the user-facing notes entirely — it has no customer-facing summary.',
                    'Write one sentence describing what changed for the person using it.',
                );

                continue;
            }

            $counts['announceable']++;

            if (mb_strlen($summary) < self::MIN_USEFUL_SUMMARY) {
                $findings[] = $this->finding(
                    'terse_summary', 'medium', $issue,
                    sprintf('The customer-facing summary is %d characters — likely too short to mean anything.', mb_strlen($summary)),
                    'Say what changed and where, not just that something did.',
                );
            }

            foreach (self::JARGON_PATTERNS as $pattern => $description) {
                if (preg_match($pattern, $summary, $matches)) {
                    $findings[] = $this->finding(
                        'internal_jargon', 'high', $issue,
                        sprintf('The customer-facing summary contains %s ("%s").', $description, mb_substr($matches[0], 0, 40)),
                        'Rewrite in the words a customer would use.',
                    );

                    break; // one jargon finding per issue is enough to act on
                }
            }
        }

        return [
            'counts'   => $counts,
            // Worst first, and each names the issue so it can be fixed directly.
            'findings' => $this->sortBySeverity($findings),
            'summary'  => $this->narrative($counts, $findings),
            'ready'    => $findings === [],
        ];
    }

    private function finding(string $type, string $severity, Report $issue, string $detail, string $suggestion): array
    {
        return [
            'type'       => $type,
            'severity'   => $severity,
            'issue'      => $issue->report_number,
            'issue_id'   => $issue->id,
            'detail'     => $detail,
            'suggestion' => $suggestion,
        ];
    }

    private function sortBySeverity(array $findings): array
    {
        $rank = ['high' => 0, 'medium' => 1, 'low' => 2];
        usort($findings, fn (array $a, array $b) => ($rank[$a['severity']] ?? 3) <=> ($rank[$b['severity']] ?? 3));

        return $findings;
    }

    private function narrative(array $counts, array $findings): string
    {
        if ($counts['shipped'] === 0) {
            return 'Nothing is stamped as shipped in this release yet.';
        }

        $parts = [sprintf('%d issue(s) shipped.', $counts['shipped'])];

        if ($counts['announceable'] > 0) {
            $parts[] = sprintf('%d will appear in the user-facing notes.', $counts['announceable']);
        }
        if ($counts['missing_summary'] > 0) {
            $parts[] = sprintf('%d will be omitted for want of a customer-facing summary.', $counts['missing_summary']);
        }
        if ($counts['security'] > 0) {
            $parts[] = sprintf('%d security fix(es) will be counted but not described.', $counts['security']);
        }
        if ($counts['excluded'] > 0) {
            $parts[] = sprintf('%d marked internal-only.', $counts['excluded']);
        }
        if ($findings === []) {
            $parts[] = 'Nothing needs attention before publication.';
        }

        return implode(' ', $parts);
    }
}
