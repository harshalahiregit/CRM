<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Exceptions\UnauthorizedTenantException;
use App\Jobs\Hr\RecalculateCandidateScore;
use App\Models\Hr\HrCandidate;
use App\Support\Hr\ResumeTextExtractor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ResumeService
{
    public const ALLOWED_MIMES = ['pdf', 'doc', 'docx'];
    public const MAX_SIZE_KB   = 5120; // 5 MB

    public function upload(HrCandidate $candidate, int $tenantId, UploadedFile $file): array
    {
        $this->assertBelongsToTenant($candidate, $tenantId);

        if ($candidate->resume_path && Storage::disk('hr_resumes')->exists($candidate->resume_path)) {
            Storage::disk('hr_resumes')->delete($candidate->resume_path);
        }

        $ext       = $file->getClientOriginalExtension();
        $safeName  = Str::slug($candidate->name).'_'.$candidate->id.'_'.time().'.'.$ext;
        $tenantDir = 'tenant_'.$candidate->tenant_id;

        $path = $file->storeAs($tenantDir, $safeName, 'hr_resumes');

        $candidate->update(['resume_path' => $path]);

        // #15 — auto-fetch DEPT / DESIGNATION / PRESENT CO. / REFERENCE from the
        // CV. Only ever FILLS BLANKS: a recruiter who has already typed a value,
        // or one the LinkedIn parse supplied, is never overwritten by a guess
        // from a document.
        $autoFilled = $this->enrichFromResume($candidate);

        Log::channel('hr')->info('Resume uploaded', ['candidate_id' => $candidate->id, 'tenant_id' => $candidate->tenant_id]);

        // Re-score AFTER the enrichment above, so the dimensions see the fields the
        // CV just filled in rather than the blanks they replaced.
        RecalculateCandidateScore::dispatch(
            $candidate->id, $candidate->tenant_id, RecalculateCandidateScore::TRIGGER_RESUME_UPLOADED
        );

        return [
            'success'     => true,
            'resume_path' => $path,
            'filename'    => $safeName,
            'size_kb'     => round($file->getSize() / 1024, 1),
            'mime'        => $file->getClientMimeType(),
            // Additive — existing callers ignore it. Lets the UI say WHICH fields
            // it filled in rather than silently mutating the form under the user.
            'auto_filled' => $autoFilled,
        ];
    }

    /**
     * #15 — re-run the CV extraction for a candidate whose resume is already on
     * disk, without asking the recruiter to re-upload the same file.
     *
     * Same fill-only rules as the upload path; same single parser. Exists because
     * every candidate uploaded before this feature landed would otherwise never
     * benefit from it.
     */
    public function extract(HrCandidate $candidate, int $tenantId): array
    {
        $this->assertBelongsToTenant($candidate, $tenantId);

        if (! $candidate->resume_path) {
            throw new BusinessException('No resume uploaded for this candidate.', 404);
        }

        $filled = $this->enrichFromResume($candidate);

        return [
            'success'     => true,
            'auto_filled' => $filled,
            'message'     => $filled === []
                ? 'Nothing new could be read from this resume — the fields it names are already filled, or the file holds no readable text.'
                : 'Filled '.count($filled).' field(s) from the resume.',
            'candidate'   => $candidate->fresh()->only([
                'id', 'current_company', 'current_designation', 'current_department',
                'referred_by_id', 'referred_by_name', 'professional_references',
            ]),
        ];
    }

    public function resolveDownload(HrCandidate $candidate, int $tenantId): array
    {
        $this->assertBelongsToTenant($candidate, $tenantId);

        if (! $candidate->resume_path) {
            throw new BusinessException('No resume uploaded for this candidate.', 404);
        }

        if (! Storage::disk('hr_resumes')->exists($candidate->resume_path)) {
            throw new BusinessException('Resume file not found on disk.', 404);
        }

        $path     = Storage::disk('hr_resumes')->path($candidate->resume_path);
        $filename = basename($candidate->resume_path);
        $mime     = mime_content_type($path) ?: 'application/octet-stream';

        return ['path' => $path, 'filename' => $filename, 'mime' => $mime];
    }

    public function delete(HrCandidate $candidate, int $tenantId): void
    {
        $this->assertBelongsToTenant($candidate, $tenantId);

        if ($candidate->resume_path && Storage::disk('hr_resumes')->exists($candidate->resume_path)) {
            Storage::disk('hr_resumes')->delete($candidate->resume_path);
        }

        $candidate->update(['resume_path' => null]);

        Log::channel('hr')->info('Resume deleted', ['candidate_id' => $candidate->id, 'tenant_id' => $candidate->tenant_id]);

        RecalculateCandidateScore::dispatch(
            $candidate->id, $candidate->tenant_id, RecalculateCandidateScore::TRIGGER_RESUME_DELETED
        );
    }

    /**
     * #15 — fill blank present-employment fields from the uploaded CV.
     *
     * FILL-ONLY, never overwrite. The LinkedIn parse and the recruiter both run
     * before this in practice, and a value a human typed outranks one a regex
     * found. That rule is what makes running this automatically on every upload
     * safe.
     *
     * Parsing itself lives in CandidateService — this method decides only WHICH
     * of the results are allowed to land, so there is one parser, not two.
     *
     * @return string[] the fields actually filled
     */
    private function enrichFromResume(HrCandidate $candidate): array
    {
        $text = ResumeTextExtractor::fromStoredPath($candidate->resume_path);

        if ($text === null) {
            return [];  // scanned image, legacy .doc, unreadable — nothing to add
        }

        $candidates = app(CandidateService::class);
        $parsed     = $candidates->parseResumeText($text);

        $updates = [];
        foreach (['current_designation', 'current_department', 'current_company', 'referred_by_name'] as $field) {
            if (blank($candidate->{$field}) && ! blank($parsed[$field])) {
                $updates[$field] = $parsed[$field];
            }
        }

        if (empty($candidate->professional_references) && ! empty($parsed['professional_references'])) {
            $updates['professional_references'] = $parsed['professional_references'];
        }

        if ($updates === []) {
            return [];
        }

        $candidate->update($updates);

        // #15 — the REFERENCE names a person; resolve them to a real employee where
        // one matches, so the referral is a link and not just a string. The matching
        // rule lives in CandidateService with the rest of the reference logic.
        if (isset($updates['referred_by_name']) && ! $candidate->referred_by_id) {
            $candidates->linkReferrerByName($candidate, $updates['referred_by_name']);
        }

        $fields = array_keys($updates);

        $candidate->recordAudit('Details auto-filled from resume', null, null, ['fields' => $fields]);

        Log::channel('hr')->info('Candidate enriched from resume', [
            'candidate_id' => $candidate->id, 'fields' => $fields,
        ]);

        return $fields;
    }

    private function assertBelongsToTenant(HrCandidate $candidate, int $tenantId): void
    {
        if ($candidate->tenant_id !== $tenantId) {
            Log::channel('hr')->warning('Resume action rejected: tenant mismatch', ['candidate_id' => $candidate->id, 'candidate_tenant_id' => $candidate->tenant_id, 'requester_tenant_id' => $tenantId]);
            throw new UnauthorizedTenantException('Unauthorized');
        }
    }
}
