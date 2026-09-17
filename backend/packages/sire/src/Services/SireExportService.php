<?php

namespace Sire\Services;

use Illuminate\Support\Collection;
use Sire\Dto\SireUserIdentity;
use Sire\Models\Report;
use Sire\Support\SireStatus;

/**
 * SIRE — the whole backlog as one work order.
 *
 * THE PROBLEM THIS SOLVES. Fixing a defect is the small half of the job. The
 * expensive half is the round trip: open the issue, read it, find the code, fix
 * it, come back, move it on. Forty issues is eighty context switches, and the
 * team stops reporting things long before the developer stops paying for them.
 *
 * GROUPED BY SCREEN, NOT BY TICKET. This is the whole idea and it is why the
 * output is not a spreadsheet. One code change usually closes several issues,
 * because several people hit the same broken screen. Ordering by screen turns
 * "forty tickets" into "six files to open"; ordering by date or by severity --
 * which is what every register does -- keeps them forty separate errands.
 *
 * Within a screen the order IS severity then age, because once you are in the
 * file you want the worst thing first.
 *
 * WHY MARKDOWN. It is meant to be read by a person or handed whole to a coding
 * assistant, and both do better with prose than with JSON. Every issue carries
 * what somebody would otherwise have to open six pages to collect: what broke,
 * what they expected, the route and record they were on, the API call that
 * failed, and the browser it happened in.
 *
 * WHAT IT DELIBERATELY OMITS. No reporter names, no assignees, no timestamps
 * beyond age, no internal commentary. This document exists to be pasted
 * somewhere -- a chat, an editor, a model -- and the fewer people it names, the
 * less it matters where it ends up.
 */
class SireExportService
{
    /** Severity order within a screen: worst first, then oldest. */
    private const SEVERITY_ORDER = 'COALESCE((SELECT level FROM sire_severities WHERE sire_severities.id = sire_reports.severity_id), 0) DESC';

    public function __construct(
        private readonly SireDashboardService $dashboard,
        private readonly SireExportImages $images,
    ) {
    }

    /**
     * One markdown brief for everything matching the register's current filters.
     *
     * @param  array<string, mixed>  $filters  the same shape the register takes
     */
    public function markdown(
        int $tenantId,
        SireUserIdentity $user,
        string $scope,
        array $filters,
        int $limit = 200,
        bool $embedImages = true,
        ?string $baseUrl = null,
    ): string {
        $reports = $this->reports($tenantId, $user, $scope, $filters, $limit);

        $lines = $this->header($reports, $scope, $limit);

        if ($reports->isEmpty()) {
            $lines[] = 'Nothing matches those filters. Either the backlog is clear, or the';
            $lines[] = 'filters are narrower than intended.';

            return implode("\n", $lines)."\n";
        }

        foreach ($this->byScreen($reports) as $place => $group) {
            $lines[] = '';
            $lines[] = '---';
            $lines[] = '';
            $lines[] = "## {$place}";
            $lines[] = '';
            $lines[] = count($group) === 1
                ? '1 issue.'
                : count($group).' issues on this screen — likely one fix, or a few in one file.';

            foreach ($group as $report) {
                $lines = array_merge($lines, $this->issue($report, $embedImages, $baseUrl));
            }
        }

        $lines[] = '';
        $lines[] = '---';
        $lines[] = '';

        if ($this->images->exhausted()) {
            $lines[] = '> **Some screenshots are links rather than pictures.** The brief hit its';
            $lines[] = '> image budget; narrow the modules to get the rest inline.';
            $lines[] = '';
        }

        $lines = array_merge($lines, $this->footer($reports));

        return implode("\n", $lines)."\n";
    }

    /** @return Collection<int, Report> */
    private function reports(int $tenantId, SireUserIdentity $user, string $scope, array $filters, int $limit): Collection
    {
        return $this->dashboard
            ->exportQuery($tenantId, $user, $scope, $filters)
            ->with([
                'severity:id,name,code,level',
                'category:id,name,code',
                'context',
            ])
            // Screen first: that is the grouping, and grouping in SQL keeps the
            // whole thing one query however long the backlog is.
            ->orderByRaw('COALESCE(module, CHAR(255))')
            ->orderByRaw('COALESCE(screen, CHAR(255))')
            ->orderByRaw(self::SEVERITY_ORDER)
            ->orderBy('created_at')
            ->limit(max(1, min($limit, 500)))
            ->get();
    }

    /**
     * Group by where it broke.
     *
     * Issues with no screen land in one bucket at the end rather than one bucket
     * each: they were filed through the API or before context capture, and
     * pretending each is its own place would scatter the document.
     *
     * @param  Collection<int, Report>  $reports
     * @return array<string, array<int, Report>>
     */
    private function byScreen(Collection $reports): array
    {
        $groups = [];

        foreach ($reports as $report) {
            $groups[$this->placeOf($report)][] = $report;
        }

        return $groups;
    }

    private function placeOf(Report $report): string
    {
        if (! $report->module) {
            return 'Unplaced — filed without screen context';
        }

        $bits = array_filter([$report->module, $report->section, $report->screen]);

        return implode(' → ', array_map(
            fn (string $bit) => ucfirst(str_replace(['-', '_'], ' ', $bit)),
            $bits,
        ));
    }

    /** @return array<int, string> */
    private function header(Collection $reports, string $scope, int $limit): array
    {
        $screens = count($this->byScreen($reports));

        $lines = [
            '# SIRE — issue brief',
            '',
            '**For developers.** Generated from the issue register; not a customer-facing',
            'document and not a status report. It carries reproduction detail and internal',
            'screen names, so treat it the way you would treat the codebase.',
            '',
            sprintf(
                '%d issue%s across %d screen%s · scope: `%s` · generated %s',
                $reports->count(),
                $reports->count() === 1 ? '' : 's',
                $screens,
                $screens === 1 ? '' : 's',
                $scope,
                now()->toDayDateTimeString(),
            ),
            '',
            'Grouped by the screen it happened on, because one fix usually closes several',
            'of these. Work down a screen, not down the list.',
        ];

        // Say so when the ceiling was hit. A brief that silently stops at 200 is
        // one somebody works through believing the backlog is clear.
        if ($reports->count() >= $limit) {
            $lines[] = '';
            $lines[] = sprintf(
                '> **This is the first %d.** Narrow the modules or the scope to see the rest.',
                $limit,
            );
        }

        return $lines;
    }

    /** @return array<int, string> */
    private function issue(Report $report, bool $embedImages, ?string $baseUrl): array
    {
        $out = [
            '',
            sprintf(
                '### %s — %s',
                $report->report_number,
                trim((string) $report->title),
            ),
            '',
            sprintf(
                '`%s`%s%s · reported %s',
                $report->status,
                $report->severity ? ' · '.$report->severity->name : '',
                $report->priority ? ' · '.strtoupper($report->priority) : '',
                $report->created_at?->diffForHumans() ?? 'unknown',
            ),
        ];

        if ($report->category) {
            $out[] = '';
            $out[] = '**Type:** '.$report->category->name;
        }

        $out[] = '';
        $out[] = '**What happened**';
        $out[] = '';
        $out[] = $this->quote((string) $report->description);

        foreach ([
            'Steps to reproduce' => $report->steps_to_reproduce,
            'Expected'           => $report->expected_result,
            'Actually got'       => $report->actual_result,
        ] as $label => $value) {
            if (filled($value)) {
                $out[] = '';
                $out[] = "**{$label}**";
                $out[] = '';
                $out[] = $this->quote((string) $value);
            }
        }

        $out = array_merge($out, $this->diagnostics($report));
        $out = array_merge($out, $this->images->blockFor($report, $embedImages, $baseUrl));

        if (filled($report->investigation_notes)) {
            $out[] = '';
            $out[] = '**Already investigated**';
            $out[] = '';
            $out[] = $this->quote((string) $report->investigation_notes);
        }

        return $out;
    }

    /**
     * The things that save a developer opening six other pages.
     *
     * The failed request is the single most useful line in the whole document --
     * it is the one fact a reporter could never have written down themselves.
     *
     * @return array<int, string>
     */
    private function diagnostics(Report $report): array
    {
        $context = $report->context;

        if ($context === null && ! $report->route) {
            return [];
        }

        $facts = [];

        if ($report->route) {
            $facts[] = '- **Route:** `'.$report->route.'`';
        }
        if ($report->related_type && $report->related_id) {
            $facts[] = sprintf('- **Record:** %s #%s', $report->related_type, $report->related_id);
        }

        $failed = $context?->failed_requests;
        if (is_string($failed)) {
            $failed = json_decode($failed, true);
        }
        if (is_array($failed) && $failed !== []) {
            $first = $failed[0];
            $facts[] = sprintf(
                '- **Failed call:** `%s %s` → **%s**%s',
                $first['method'] ?? '?',
                $first['path'] ?? '?',
                $first['status'] ?? 'no response',
                isset($first['correlation_ref']) && $first['correlation_ref']
                    ? ' (ref `'.$first['correlation_ref'].'`)'
                    : '',
            );
        }

        if ($context?->browser || $context?->os) {
            $facts[] = '- **Seen on:** '.trim(($context->browser ?? '').' · '.($context->os ?? ''), ' ·');
        }

        if ($facts === []) {
            return [];
        }

        return array_merge(['', '**Where it broke**', ''], $facts);
    }

    /** @return array<int, string> */
    private function footer(Collection $reports): array
    {
        return [
            '## When these are fixed',
            '',
            'Send the whole batch back in one call rather than opening each issue:',
            '',
            '```',
            'POST /api/sire/reports/transitions',
            '{',
            '  "transitions": [',
            '    { "report_id": '.$reports->first()->id.', "action": "mark_ready_for_qa",',
            '      "fix_summary": "what you changed and what QA should test" }',
            '  ]',
            '}',
            '```',
            '',
            'Each one still runs every guard and is audited separately — this is a way to',
            'avoid forty page loads, not a way around the workflow.',
        ];
    }

    /** Indent as a blockquote so a multi-line description keeps its shape. */
    private function quote(string $text): string
    {
        $text = trim($text);

        if ($text === '') {
            return '> _(nothing written)_';
        }

        return collect(preg_split('/\R/', $text))
            ->map(fn (string $line) => '> '.$line)
            ->implode("\n");
    }

    /** Statuses an export never includes unless asked for by name. */
    public static function defaultScope(): string
    {
        return 'open';
    }

    /** @return array<int, string> */
    public static function terminalStatuses(): array
    {
        return SireStatus::TERMINAL;
    }
}
