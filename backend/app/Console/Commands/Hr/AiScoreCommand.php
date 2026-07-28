<?php

namespace App\Console\Commands\Hr;

use App\Models\Hr\HrCandidate;
use App\Services\Hr\Scoring\CandidateScoringEngine;
use App\Services\Hr\Scoring\Dimensions\DimensionResult;
use App\Services\Hr\Scoring\RecommendationEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Compare the AIR job-fit engine against the scores currently on file.
 *
 * Read-only by construction: CandidateScoringEngine::score() computes and returns,
 * and this command never calls ScoreRecorder, never touches hr_candidates, never
 * inserts history and never dispatches a job. Writing is Phase 4's concern, so
 * --dry-run is currently mandatory rather than optional — running without it is
 * refused instead of silently doing something destructive.
 */
class AiScoreCommand extends Command
{
    protected $signature = 'hr:ai-score
        {--dry-run : Report what the engine would produce. Required in this phase.}
        {--tenant= : Restrict to one tenant id}
        {--limit= : Cap the number of candidates}
        {--changed-only : Show only candidates whose score would change}';

    protected $description = 'Dry-run the AIR job-fit scoring engine and compare against existing ai_score values';

    public function handle(CandidateScoringEngine $engine): int
    {
        if (! $this->option('dry-run')) {
            $this->components->error('Refusing to run without --dry-run.');
            $this->line('  Applying scores is Phase 4 and is not wired yet. Use: <fg=cyan>php artisan hr:ai-score --dry-run</>');

            return self::FAILURE;
        }

        $query = HrCandidate::query()
            ->with(['jobPosting.manpowerRequest', 'interviewRounds'])
            ->orderBy('id');

        if ($tenant = $this->option('tenant')) {
            $query->where('tenant_id', (int) $tenant);
        }
        if ($limit = $this->option('limit')) {
            $query->limit((int) $limit);
        }

        $candidates = $query->get();
        if ($candidates->isEmpty()) {
            $this->components->warn('No candidates matched.');

            return self::SUCCESS;
        }

        // Guard rail: prove to the operator that nothing was written, by counting
        // every write statement the engine issues. It must be zero.
        $writes = 0;
        DB::listen(function ($q) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $q->sql)) {
                $writes++;
            }
        });

        $rows = [];
        $changed = 0;
        $diffs = [];
        $confidences = [];
        $newBands = [];
        $unscored = 0;

        foreach ($candidates as $c) {
            $result = $engine->score($c);

            $old = $c->ai_score !== null ? (int) $c->ai_score : null;
            $new = $result->overallScore;

            $scoredKeys = array_map(
                fn (DimensionResult $d) => $d->key,
                array_filter($result->dimensions, fn (DimensionResult $d) => $d->isScored())
            );

            if ($old !== $new) {
                $changed++;
            }
            if ($old !== null && $new !== null) {
                $diffs[] = $new - $old;
            }
            $confidences[] = $result->confidence;
            $newBands[$result->recommendation] = ($newBands[$result->recommendation] ?? 0) + 1;
            if ($new === null) {
                $unscored++;
            }

            if ($this->option('changed-only') && $old === $new) {
                continue;
            }

            $rows[] = [
                $c->id,
                mb_strimwidth((string) $c->name, 0, 18, '…'),
                $old ?? '—',
                // A suppressed score shows what it WOULD have been, so the operator
                // can see the engine measured something and why it was withheld.
                $new ?? ($result->provisionalScore !== null
                    ? 'null <fg=gray>('.$result->provisionalScore.')</>'
                    : 'null'),
                $result->confidence.'%',
                mb_strimwidth($this->legacyRecommendation($c), 0, 20, '…'),
                mb_strimwidth($result->recommendation, 0, 20, '…'),
                implode(',', $scoredKeys) ?: '—',
            ];
        }

        $this->newLine();
        $this->components->info('AIR job-fit engine — DRY RUN (no data written)');
        $this->table(
            ['#', 'Candidate', 'Old', 'New', 'Conf', 'Old recommendation', 'New recommendation', 'Scored dimensions'],
            $rows
        );

        $avgDiff = $diffs !== [] ? array_sum($diffs) / count($diffs) : 0;
        $avgConf = $confidences !== [] ? array_sum($confidences) / count($confidences) : 0;

        $this->components->twoColumnDetail('<fg=gray>Total candidates</>', (string) $candidates->count());
        $this->components->twoColumnDetail('<fg=gray>Scores that would change</>', $changed.' of '.$candidates->count());
        $this->components->twoColumnDetail('<fg=gray>Would become unscored (null)</>', (string) $unscored);
        // State the sample size: the average only covers candidates that have BOTH an
        // old and a new score, which after the confidence gate can be a small subset.
        // Reporting a bare "-15.0 pts" over 2 of 30 candidates would read as typical.
        $this->components->twoColumnDetail(
            '<fg=gray>Average score difference</>',
            $diffs === []
                ? 'n/a (no candidate has both an old and a new score)'
                : sprintf('%+.1f pts <fg=gray>(over %d of %d comparable)</>', $avgDiff, count($diffs), $candidates->count())
        );
        $this->components->twoColumnDetail('<fg=gray>Average confidence</>', sprintf('%.1f%%', $avgConf));

        $this->newLine();
        $this->line('  <fg=gray>New recommendation distribution</>');
        foreach (RecommendationEngine::ALL as $band) {
            if (! empty($newBands[$band])) {
                $this->components->twoColumnDetail('    '.$band, (string) $newBands[$band]);
            }
        }

        $this->newLine();
        if ($writes === 0) {
            $this->components->info('Verified: 0 write statements issued. Nothing was persisted.');
        } else {
            // Should be unreachable. Loud rather than silent if it ever is not.
            $this->components->error("Expected 0 writes, observed {$writes}. Investigate before Phase 4.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * What the candidate's recommendation reads as TODAY, so the comparison is
     * against live behaviour rather than an idealised baseline.
     *
     * Prefers the stored ai_breakdown label; otherwise reproduces the frontend
     * aiBand() thresholds (90/70/50) that Candidates, JobWorkspace and
     * CandidateProfile currently render.
     */
    private function legacyRecommendation(HrCandidate $c): string
    {
        $breakdown = $c->ai_breakdown;
        if (is_array($breakdown) && ! empty($breakdown['recommendation'])) {
            return (string) $breakdown['recommendation'];
        }

        if ($c->ai_score === null) {
            return '—';
        }

        $s = (int) $c->ai_score;

        return match (true) {
            $s >= 90 => 'Highly Recommended',
            $s >= 70 => 'Recommended',
            $s >= 50 => 'Consider',
            default  => 'Not Recommended',
        };
    }
}
