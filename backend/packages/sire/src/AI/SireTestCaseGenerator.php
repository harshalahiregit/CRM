<?php

namespace Sire\AI;

use Sire\Models\Report;
use Sire\Support\SireTestCategory;

/**
 * SIRE — suggested test cases.
 *
 * Direct port of tests/reference/testCaseGenerator.mjs; both run
 * fixtures/test-case-generation-cases.json.
 *
 * A CHECKLIST GENERATOR, not a language model. Every clause it emits is either a
 * fixed template phrase or a quotation from the issue — it invents no prose, and
 * that constraint is what makes it safe to ship without a vendor.
 *
 * Most test cases for a defect are formulaic: reproduce it, prove the fix, check
 * the edges, check the neighbours. A generated checklist a human then edits beats
 * a blank form. What it must never do is sound like it understood the bug.
 *
 * SIX CATEGORIES. Two always, four only on a signal. A boundary test on an issue
 * with no boundaries is noise, and noise in a QA checklist trains people to skim.
 */
class SireTestCaseGenerator
{
    /*
     * The vocabulary belongs to core — a person typing a boundary test by hand
     * uses the same word. Aliased here for readability; SireTestCategory is the
     * definition.
     */
    private const HAPPY_PATH       = SireTestCategory::HAPPY_PATH;
    private const FAILURE_PATH     = SireTestCategory::FAILURE_PATH;
    private const BOUNDARY         = SireTestCategory::BOUNDARY;
    private const PERMISSION       = SireTestCategory::PERMISSION;
    private const REGRESSION       = SireTestCategory::REGRESSION;
    private const RELATED_WORKFLOW = SireTestCategory::RELATED_WORKFLOW;

    /** Words that mean an issue has edges worth probing. */
    private const BOUNDARY_HINTS = [
        'limit', 'max', 'maximum', 'min', 'minimum', 'empty', 'blank', 'zero', 'null',
        'length', 'size', 'count', 'page', 'pagination', 'date', 'expiry', 'expire',
        'timeout', 'duplicate', 'overflow', 'truncat', 'decimal', 'negative', 'large',
    ];

    /** Words that mean access control is part of the story. */
    private const PERMISSION_HINTS = [
        'permission', 'access', 'denied', '403', '401', 'unauthorized', 'unauthorised',
        'role', 'admin', 'forbidden', 'owner', 'visibility', 'restricted', 'tenant',
        'login', 'auth',
    ];

    /** Not boundary values: these are outcomes, not inputs. */
    private const STATUS_CODES = ['200', '201', '404', '500', '403', '401', '422'];

    private const MAX_QUOTE = 240;

    /**
     * @param  array  $opts  related_refs (string[]), regression_of (?string)
     * @return array<int, array<string, mixed>>
     */
    public function generate(Report $issue, array $opts = []): array
    {
        $text = $this->haystack($issue);
        $place = $this->where($issue);
        $out = [];

        // ---- always: prove the intended behaviour works ---------------------
        $out[] = $this->make(
            self::HAPPY_PATH,
            "{$place}: the intended behaviour works",
            "A user with normal access is on {$place}.",
            $this->quote($issue->steps_to_reproduce, 'They perform the action described in the issue.'),
            $this->quote($issue->expected_result, 'The action completes and the expected result is shown.'),
            'Confirms the feature works at all once the fix is in — a fix that breaks the ordinary path is not a fix.',
        );

        // ---- always: prove the reported failure is gone ---------------------
        $out[] = $this->make(
            self::FAILURE_PATH,
            "{$place}: the reported failure no longer happens",
            "A user is on {$place}, in the state described in the issue.",
            $this->quote($issue->steps_to_reproduce, 'They repeat the steps that produced the failure.'),
            'The reported behaviour no longer occurs: '.$this->quote($issue->actual_result, 'the failure described in the issue').'.',
            'This is the test the issue itself asks for. Without it, nothing proves the defect was addressed.',
        );

        // ---- boundary: only when the issue has edges ------------------------
        $boundaryWords = $this->hits($text, self::BOUNDARY_HINTS);
        $numbers = $this->numericSignals($text);

        if ($boundaryWords !== [] || $numbers !== []) {
            $signal = $numbers !== []
                ? 'values around '.implode(', ', array_slice($numbers, 0, 3))
                : 'the '.implode(', ', array_slice($boundaryWords, 0, 3)).' conditions mentioned in the issue';

            $out[] = $this->make(
                self::BOUNDARY,
                "{$place}: behaviour at the edges",
                "A user is on {$place}.",
                "They exercise {$signal} — and the empty, minimum and maximum cases either side.",
                'Each edge is handled predictably: no crash, no silent truncation, and a clear message where the input is refused.',
                'Emitted because the issue mentions '.($numbers !== [] ? 'specific values' : implode(', ', array_slice($boundaryWords, 0, 3))).'.',
            );
        }

        // ---- permission: only WHERE RELEVANT, per the brief ------------------
        $permissionWords = $this->hits($text, self::PERMISSION_HINTS);

        if ($permissionWords !== [] || $issue->related_type) {
            $because = $permissionWords !== []
                ? 'the issue mentions '.implode(', ', array_slice($permissionWords, 0, 3))
                : "the issue concerns a specific {$issue->related_type}, which has owners and viewers";

            $out[] = $this->make(
                self::PERMISSION,
                "{$place}: access is enforced for every role",
                "Users of each role — admin, staff, and any portal role that can reach {$place}.",
                'Each attempts the action in the issue, on a record they own and on one they do not.',
                'Permitted users succeed. Others are refused, and the refusal does not reveal that the record exists.',
                "Emitted because {$because}.",
            );
        }

        // ---- regression: only when this has happened before ------------------
        $regressionRef = $opts['regression_of'] ?? null;
        $reopens = (int) ($issue->reopen_count ?? 0);
        $fixedVersion = $issue->fixedVersion?->version;

        if ($issue->is_regression || $reopens > 0 || $regressionRef || $fixedVersion) {
            $reason = $issue->is_regression
                ? 'this issue is flagged as a regression'
                : ($reopens > 0
                    ? "this issue has been reopened {$reopens} time(s)"
                    : 'a fix is being shipped for it');

            $out[] = $this->make(
                self::REGRESSION,
                $regressionRef
                    ? "{$place}: {$regressionRef} does not come back"
                    : "{$place}: this defect does not come back",
                'The build containing the fix'.($fixedVersion ? " ({$fixedVersion})" : '').'.',
                'Re-run the failing steps'.($regressionRef ? ", and those of {$regressionRef}" : '')
                    .', then repeat after a full page reload and a fresh session.',
                'The defect stays fixed across sessions and reloads, and the earlier behaviour does not reappear.',
                "Emitted because {$reason}.",
            );
        }

        // ---- related workflow: only when something is adjacent ---------------
        $related = $opts['related_refs'] ?? [];

        if ($related !== [] || $issue->section) {
            $scope = $related !== []
                ? 'the linked issues '.implode(', ', array_slice($related, 0, 3))
                : "the rest of the {$issue->section} workflow";

            $out[] = $this->make(
                self::RELATED_WORKFLOW,
                "{$place}: the surrounding workflow still works end to end",
                "The same build, starting from the step before {$place}.",
                'Complete the whole '.($issue->section ?? 'affected')." flow, including {$scope}.",
                'The workflow completes end to end, and nothing downstream of the fix changed behaviour.',
                $related !== []
                    ? 'Emitted because this issue is linked to '.count($related).' other issue(s).'
                    : "Emitted because the issue sits inside the {$issue->section} workflow.",
            );
        }

        return $out;
    }

    private function make(string $category, string $title, string $given, string $when, string $then, string $rationale): array
    {
        return [
            'category'  => $category,
            'title'     => $title,
            'given'     => $given,
            'when'      => $when,
            'then'      => $then,
            'rationale' => $rationale,
            'source'    => 'ai_suggested',
            // Draft until a human accepts it. A generated test is a proposal.
            'status'    => 'draft',
            // NEVER set here. A test nobody ran has no result, and writing one
            // would let a checklist mark itself passed.
            'result'    => null,
        ];
    }

    private function haystack(Report $issue): string
    {
        return mb_strtolower(implode(' ', array_filter([
            $issue->title, $issue->description, $issue->steps_to_reproduce,
            $issue->expected_result, $issue->actual_result,
        ])));
    }

    private function where(Report $issue): string
    {
        foreach ([$issue->screen, $issue->section, $issue->module] as $candidate) {
            if (! empty($candidate)) {
                return $candidate;
            }
        }

        return 'the affected screen';
    }

    private function hits(string $text, array $hints): array
    {
        return array_values(array_filter($hints, fn (string $h) => str_contains($text, $h)));
    }

    /** A number worth testing the edges of — not a version, not a status code. */
    private function numericSignals(string $text): array
    {
        preg_match_all('/\b\d{1,6}\b/', $text, $matches);

        return array_values(array_unique(array_diff($matches[0] ?? [], self::STATUS_CODES)));
    }

    /** Quote the issue rather than paraphrase it. Trimmed, never rewritten. */
    private function quote(?string $text, string $fallback): string
    {
        $t = trim((string) $text);

        if ($t === '') {
            return $fallback;
        }

        return mb_strlen($t) > self::MAX_QUOTE ? mb_substr($t, 0, self::MAX_QUOTE - 1).'…' : $t;
    }
}
