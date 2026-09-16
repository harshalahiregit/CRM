<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeSalary;
use App\Models\Hr\HrExitClearance;
use App\Models\Hr\HrExitRequest;
use App\Models\Tenant;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * The letters an employee is actually given, and one they are owed.
 *
 * "एंट्री टू एग्जिट मींस उसमें ऑफर लेटर, अपॉइंटमेंट लेटर, कन्फर्मेशन लेटर,
 * अप्रेजल, देन उसके बाद अपना रेजिग्नेशन, रेजिग्नेशन अप्रूवल, देन रिलीविंग
 * लेटर, एक्सपीरियंस लेटर" — the offer, appointment and confirmation ends of
 * that list were built. The exit end was not: the exit PROCESS existed in full
 * (request, approval, clearance, settlement, interview) and produced no
 * document, so the one artefact the departing person actually needs — the
 * thing their next employer asks for — had to be typed by hand in Word.
 *
 * ── Why each letter refuses to issue early ──
 *
 * These are not internal records. A relieving letter states that somebody has
 * been released and their dues are settled; issuing one before clearance means
 * certifying a fact that is not yet true, in writing, to a third party who will
 * rely on it. An experience letter dated before the last working day says
 * somebody left before they did. Neither is recoverable by editing a row later,
 * because the PDF has already gone.
 *
 * So each guard here is about what the document ASSERTS, not about tidiness:
 *
 *   relieving   → exit approved AND clearance completed
 *   experience  → exit approved and the last working day has passed
 *   appraisal   → an actual salary revision to describe
 */
class LetterService
{
    public const DOC_DISK = 'hr_documents';

    public const RELIEVING = 'relieving';
    public const EXPERIENCE = 'experience';
    public const APPRAISAL = 'appraisal';
    public const TYPES = [self::RELIEVING, self::EXPERIENCE, self::APPRAISAL];

    /**
     * Build one letter and return its stored path plus the rendered model.
     *
     * @return array{type:string, path:string, filename:string, letter:array}
     */
    public function generate(string $type, HrEmployee $employee, int $tenantId, ?User $actor = null): array
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new BusinessException('Unknown letter type.');
        }

        $letter = match ($type) {
            self::RELIEVING  => $this->relieving($employee, $tenantId),
            self::EXPERIENCE => $this->experience($employee, $tenantId),
            self::APPRAISAL  => $this->appraisal($employee, $tenantId),
        };

        $tenant = Tenant::find($tenantId);

        $pdf = Pdf::loadView('pdf.hr_letter', [
            'letter'   => $letter,
            'employee' => $employee,
            'tenant'   => $tenant,
        ])->setPaper('a4');

        $filename = sprintf('%s_letter_%s.pdf', $type, $employee->employee_code ?: $employee->id);
        $path = "hr/documents/letters/tenant_{$tenantId}/employee_{$employee->id}/{$filename}";

        Storage::disk(self::DOC_DISK)->put($path, $pdf->output());

        $employee->recordAudit(ucfirst($type).' Letter Issued', $actor, null, [
            'employee_id' => $employee->id,
            'path'        => $path,
        ]);

        return ['type' => $type, 'path' => $path, 'filename' => $filename, 'letter' => $letter];
    }

    /** The raw PDF bytes, for a download response. */
    public function download(string $type, HrEmployee $employee, int $tenantId): array
    {
        $built = $this->generate($type, $employee, $tenantId);

        return [
            'contents' => Storage::disk(self::DOC_DISK)->get($built['path']),
            'filename' => $built['filename'],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | The three letters
    |--------------------------------------------------------------------------
    */

    /**
     * Relieving letter — "you have been released, and we owe you nothing".
     *
     * The clearance gate is the important one. Relieving somebody whose laptop
     * is unreturned and whose advance is unrecovered puts the company's own
     * signature on a statement that the account is settled.
     */
    private function relieving(HrEmployee $employee, int $tenantId): array
    {
        $exit = $this->approvedExit($employee, $tenantId);

        $clearance = HrExitClearance::where('tenant_id', $tenantId)
            ->where('employee_id', $employee->id)
            ->latest('id')
            ->first();

        if (! $clearance || $clearance->status !== HrExitClearance::COMPLETED) {
            throw new BusinessException(
                'Clearance is not complete for this employee. A relieving letter states that all dues are settled, '
                .'so it cannot be issued before that is true.'
            );
        }

        $lwd = $exit->last_working_date;

        return [
            'type'   => self::RELIEVING,
            'title'  => 'Relieving Letter',
            'ref'    => 'REL-'.str_pad((string) $exit->id, 4, '0', STR_PAD_LEFT),
            'rows'   => array_filter([
                'Employee Name'      => $employee->name,
                'Employee Code'      => $employee->employee_code,
                'Designation'        => $employee->designation,
                'Department'         => $employee->department,
                'Date of Joining'    => $this->date($employee->joining_date),
                'Last Working Day'   => $this->date($lwd),
                'Reason for Leaving' => $exit->exitType?->name ?? $exit->reason,
            ]),
            'body' => [
                sprintf(
                    'This is to certify that %s (Employee Code %s) was employed with us as %s and has been relieved '
                    .'from their duties with effect from the close of business on %s.',
                    $employee->name, $employee->employee_code ?: '—',
                    $employee->designation ?: 'an employee', $this->date($lwd)
                ),
                'All company property issued to them has been returned and the exit clearance has been completed. '
                .'No dues remain outstanding on either side as at the date of this letter.',
                'We thank them for their service and wish them well in their future endeavours.',
            ],
        ];
    }

    /**
     * Experience / service certificate — the document the next employer asks for.
     *
     * Deliberately states dates and role and nothing else. A certificate that
     * editorialises about conduct is one the company has to defend later, and
     * the person asking for it needs the facts, not an opinion.
     */
    private function experience(HrEmployee $employee, int $tenantId): array
    {
        $exit = $this->approvedExit($employee, $tenantId);
        $lwd = $exit->last_working_date ? Carbon::parse($exit->last_working_date) : null;

        if ($lwd && $lwd->isFuture()) {
            throw new BusinessException(
                'The last working day is '.$lwd->format('d M Y').
                '. An experience letter cannot be dated before somebody has actually left.'
            );
        }

        $from = $employee->joining_date ? Carbon::parse($employee->joining_date) : null;

        return [
            'type'  => self::EXPERIENCE,
            'title' => 'Experience Certificate',
            'ref'   => 'EXP-'.str_pad((string) $employee->id, 4, '0', STR_PAD_LEFT),
            'rows'  => array_filter([
                'Employee Name'   => $employee->name,
                'Employee Code'   => $employee->employee_code,
                'Designation'     => $employee->designation,
                'Department'      => $employee->department,
                'Date of Joining' => $this->date($employee->joining_date),
                'Date of Leaving' => $this->date($exit->last_working_date),
                'Total Service'   => $from && $lwd ? $this->tenure($from, $lwd) : null,
            ]),
            'body' => [
                sprintf(
                    'This is to certify that %s was employed with us from %s to %s.',
                    $employee->name, $this->date($employee->joining_date), $this->date($exit->last_working_date)
                ),
                sprintf(
                    'At the time of leaving they held the position of %s in the %s department.',
                    $employee->designation ?: '—', $employee->department ?: '—'
                ),
                'This certificate is issued at their request.',
            ],
        ];
    }

    /**
     * Appraisal letter — a salary revision, described.
     *
     * Reads the revision the salary record already carries rather than taking
     * figures as input: a letter stating a number payroll does not hold is how
     * an employee is told one thing and paid another.
     */
    private function appraisal(HrEmployee $employee, int $tenantId): array
    {
        $current = HrEmployeeSalary::where('tenant_id', $tenantId)
            ->where('employee_id', $employee->id)
            ->where('status', HrEmployeeSalary::ACTIVE)
            ->latest('id')
            ->first();

        if (! $current) {
            throw new BusinessException('This employee has no active salary to describe.');
        }

        // The revision it replaced, so the letter can state both figures.
        $previous = HrEmployeeSalary::where('tenant_id', $tenantId)
            ->where('employee_id', $employee->id)
            ->where('id', '<', $current->id)
            ->latest('id')
            ->first();

        if (! $previous) {
            throw new BusinessException(
                'There is no earlier salary on record for this employee, so there is no revision to describe. '
                .'An appraisal letter states what the salary has changed from.'
            );
        }

        $delta = (float) $current->annual_ctc - (float) $previous->annual_ctc;
        $pct = (float) $previous->annual_ctc > 0
            ? round($delta / (float) $previous->annual_ctc * 100, 2)
            : null;

        return [
            'type'  => self::APPRAISAL,
            'title' => 'Salary Revision Letter',
            'ref'   => 'APR-'.str_pad((string) $current->id, 4, '0', STR_PAD_LEFT),
            'rows'  => array_filter([
                'Employee Name'    => $employee->name,
                'Employee Code'    => $employee->employee_code,
                'Designation'      => $employee->designation,
                'Effective From'   => $this->date($current->effective_from),
                'Previous Annual CTC' => $this->money($previous->annual_ctc),
                'Revised Annual CTC'  => $this->money($current->annual_ctc),
                'Increase'         => $delta != 0
                    ? $this->money($delta).($pct !== null ? " ({$pct}%)" : '')
                    : null,
                'Reason'           => $current->reason,
            ]),
            'body' => [
                sprintf(
                    'We are pleased to inform you that following a review of your performance, your compensation '
                    .'has been revised with effect from %s.',
                    $this->date($current->effective_from)
                ),
                sprintf(
                    'Your revised annual cost to company is %s, against the previous %s.',
                    $this->money($current->annual_ctc), $this->money($previous->annual_ctc)
                ),
                'All other terms and conditions of your employment remain unchanged.',
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function approvedExit(HrEmployee $employee, int $tenantId): HrExitRequest
    {
        $exit = HrExitRequest::where('tenant_id', $tenantId)
            ->where('employee_id', $employee->id)
            ->where('status', HrExitRequest::APPROVED)
            ->latest('id')
            ->first();

        if (! $exit) {
            throw new BusinessException(
                'This employee has no approved exit. Relieving and experience letters certify that somebody has '
                .'left, so there has to be an approved exit to certify.'
            );
        }

        return $exit;
    }

    /**
     * "3 years, 2 months" — how a certificate states service, not a day count.
     *
     * The int casts are load-bearing. Carbon 3 returns a FLOAT from diffInYears
     * and diffInMonths, so without them a service certificate reads
     * "5.2465753424658 years, 2.9666666666667 months" — on company letterhead,
     * to somebody's next employer.
     */
    private function tenure(Carbon $from, Carbon $to): string
    {
        $years = (int) $from->diffInYears($to);
        $months = (int) $from->copy()->addYears($years)->diffInMonths($to);

        $parts = [];
        if ($years > 0) {
            $parts[] = $years.' year'.($years === 1 ? '' : 's');
        }
        if ($months > 0) {
            $parts[] = $months.' month'.($months === 1 ? '' : 's');
        }

        return $parts === [] ? 'Less than a month' : implode(', ', $parts);
    }

    private function date($d): ?string
    {
        return $d ? Carbon::parse($d)->format('d F Y') : null;
    }

    private function money($v): string
    {
        return '₹'.number_format((float) $v, 2);
    }
}
