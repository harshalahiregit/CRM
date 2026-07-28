<?php

namespace App\Services\Hr\Scoring\Dimensions;

use App\Models\Hr\HrCandidate;
use App\Models\Hr\HrJobPosting;

/**
 * Resume content match — DELIBERATELY ALWAYS UNAVAILABLE.
 *
 * No resume parser exists in this codebase: ResumeService does upload, download and
 * delete, and never opens the file. The old engine gave 55 points for a resume
 * existing and 20 for none, then weighted that at 30% — making "did someone attach
 * a PDF" the single largest factor in the career-portal score.
 *
 * Scoring a file's existence is not scoring a resume. Until a parser exists this
 * returns null, the weight redistributes to dimensions that measured something, and
 * confidence drops accordingly — which is the honest signal.
 *
 * When a parser lands, this is the only class that changes.
 */
class ResumeDimension
{
    public const KEY = 'resume';

    public function score(HrCandidate $candidate, ?HrJobPosting $job): DimensionResult
    {
        return DimensionResult::unavailable(self::KEY, 'Resume',
            'Resume parsing unavailable — a stored file is not evidence of fit, so this dimension is not scored.',
            ['resume_on_file' => (bool) $candidate->resume_path]
        );
    }
}
