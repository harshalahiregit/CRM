<?php

namespace Sire\AI;

use Sire\Models\IssueToken;
use Sire\Models\Report;
use Illuminate\Support\Facades\DB;

/**
 * SIRE — maintain the inverted index.
 *
 * Runs from its OWN scheduled command, not from the core write path. That is
 * deliberate on two counts:
 *
 *   1. Core never calls into the AI layer, so the layering holds and deleting the
 *      Ai namespace leaves the workflow intact.
 *   2. Issue creation cannot be slowed, blocked or broken by indexing. The brief
 *      asks that AI being unavailable not break issue creation; the strongest
 *      version of that is AI not being on the creation path at all.
 *
 * The lag this introduces does not matter: duplicate detection compares a NEW
 * issue (tokenised on the fly) against HISTORICAL ones, and those are already
 * indexed. The new issue only needs to be in the index by the time the next one
 * is filed.
 */
class SireIssueIndexer
{
    /** Tokens kept per issue. Beyond this the tail is noise. */
    private const MAX_TOKENS = 40;

    public function __construct(private readonly SireTextAnalyzer $text)
    {
    }

    /** Reindex one issue. Idempotent: the old rows go, the new rows arrive. */
    public function index(Report $report): int
    {
        $titleTokens = $this->text->tokenize($report->title);
        $bodyTokens = array_values(array_diff($this->text->tokenize($report->description), $titleTokens));

        $rows = [];
        $now = now();

        foreach (array_slice($titleTokens, 0, self::MAX_TOKENS) as $token) {
            $rows[$token] = [
                'tenant_id'  => $report->tenant_id,   // explicit: this runs in a command
                'report_id'  => $report->id,
                'token'      => mb_substr($token, 0, 48),
                'weight'     => IssueToken::WEIGHT_TITLE,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach ($bodyTokens as $token) {
            if (count($rows) >= self::MAX_TOKENS) {
                break;
            }
            $rows[$token] ??= [
                'tenant_id'  => $report->tenant_id,
                'report_id'  => $report->id,
                'token'      => mb_substr($token, 0, 48),
                'weight'     => IssueToken::WEIGHT_BODY,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::transaction(function () use ($report, $rows) {
            IssueToken::query()
                ->forTenant($report->tenant_id)
                ->where('report_id', $report->id)
                ->delete();

            if ($rows !== []) {
                IssueToken::insert(array_values($rows));
            }
        });

        return count($rows);
    }

    /**
     * Index everything not yet indexed, or changed since it was.
     *
     * Chunked and tenant-derived-per-row: this runs with no authenticated user, so
     * nothing may rely on the BelongsToTenant auto-stamp.
     */
    public function sweep(?int $tenantId = null, int $limit = 500): array
    {
        $indexed = 0;
        $tenants = [];

        Report::query()
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereNotExists(function ($q) {
                $q->selectRaw(1)
                    ->from('sire_issue_tokens')
                    ->whereColumn('sire_issue_tokens.report_id', 'sire_reports.id')
                    ->whereColumn('sire_issue_tokens.tenant_id', 'sire_reports.tenant_id')
                    // Reindex when the issue has been edited since it was indexed.
                    ->whereColumn('sire_issue_tokens.updated_at', '>=', 'sire_reports.updated_at');
            })
            ->orderBy('id')
            ->limit($limit)
            ->chunkById(100, function ($reports) use (&$indexed, &$tenants) {
                foreach ($reports as $report) {
                    $this->index($report);
                    $indexed++;
                    $tenants[$report->tenant_id] = true;
                }
            });

        return ['indexed' => $indexed, 'tenants' => count($tenants)];
    }

    /**
     * Tokens that appear in few enough issues to be real evidence.
     *
     * Computed per query rather than stored: document frequency changes with every
     * new issue, and a stale rarity table would quietly stop boosting the terms
     * that matter most.
     */
    public function rareTokens(int $tenantId, array $tokens, int $threshold = 3): array
    {
        if ($tokens === []) {
            return [];
        }

        return IssueToken::query()
            ->forTenant($tenantId)
            ->whereIn('token', $tokens)
            ->groupBy('token')
            ->havingRaw('COUNT(DISTINCT report_id) <= ?', [$threshold])
            ->pluck('token')
            ->all();
    }
}
