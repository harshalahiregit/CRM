<?php

namespace App\Support\Shared;

use App\Support\Medical\MedicalQcStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * What is wrong with a worker's medical, whose move it is, and what to do.
 *
 * The badge step used to say, in full: "1 item blocking the entry badge —
 * Medical Report is Pending — awaiting quality check." Every word of that is
 * true and none of it is useful. It does not say whether the vendor has missed
 * something, whether they can fix it, or what happens next — so a vendor reads
 * "pending" as "you still owe us something" and goes hunting for a missing
 * document that does not exist, because the certificate is already in and the
 * quality team simply has not looked at it yet.
 *
 * The distinction that actually matters is WHOSE MOVE IT IS:
 *
 *   · `vendor`        — there is something to do, and `action` says what.
 *   · `quality_team`  — nothing is needed from the vendor. Saying so plainly is
 *                       the whole point: it stops them looking.
 *
 * Both engines answer this the same way because a vendor is a vendor; only the
 * model differs, so this takes the record and reads it through a small shim.
 */
class MedicalClearanceMessage
{
    /** Nobody need do anything — the clearance stands. */
    public const OWNER_NONE = 'none';

    /** The vendor must act; `action` says what. */
    public const OWNER_VENDOR = 'vendor';

    /** With the quality team. The vendor waits, and is told to. */
    public const OWNER_QUALITY = 'quality_team';

    /**
     * Build the state, sentence, owner and next action for one worker.
     *
     * @param  Model|null  $medical  the latest medical record, if any
     * @param  string  $pendingLabel  the tenant's own wording for "pending"
     * @return array{status:string, message:string, owner:string, action:string|null}
     */
    public static function for(?Model $medical, string $pendingLabel): array
    {
        if (! $medical) {
            return self::row('missing',
                $pendingLabel.' — no examination has been recorded for this worker.',
                self::OWNER_VENDOR,
                'Record the examination in Step 2 (Medical), or upload the certificate you already hold.');
        }

        $qc = $medical->qc_status ?? null;

        if ($qc === MedicalQcStatus::REJECTED) {
            return self::row('rejected',
                'The quality team rejected this medical'.self::because($medical).' A fresh examination is required.',
                self::OWNER_VENDOR,
                'Arrange a re-examination and upload the new certificate.');
        }

        if ($qc === MedicalQcStatus::HOLD) {
            return self::row('hold',
                $pendingLabel.' — the quality team has a question about the certificate'.self::because($medical),
                self::OWNER_VENDOR,
                'Answer the query on this worker\'s medical record; the check resumes as soon as you reply.');
        }

        if ($qc === MedicalQcStatus::PENDING) {
            return self::row('pending',
                $pendingLabel.' — the certificate was submitted'.self::submittedAgo($medical)
                    .' and is with the quality team.',
                self::OWNER_QUALITY,
                null);   // deliberately nothing: see the class docblock
        }

        if (! $medical->isPassing()) {
            $outcome = trim((string) ($medical->fitness_status ?? ''));

            return self::row('unfit',
                $outcome !== ''
                    ? "The examination result is \"{$outcome}\", so this worker cannot be cleared for site."
                    : 'The examination result is not Fit, so this worker cannot be cleared for site.',
                self::OWNER_VENDOR,
                'A new examination is required before an entry badge can be issued.');
        }

        if ($medical->isExpired()) {
            $on = $medical->expiry_date ? ' on '.$medical->expiry_date->format('d M Y') : '';

            return self::row('expired',
                "The medical certificate expired{$on}.",
                self::OWNER_VENDOR,
                'Upload a current certificate, or record a fresh examination.');
        }

        return self::row('approved', 'Medical clearance is complete.', self::OWNER_NONE, null);
    }

    /**
     * The quality team's own words, when they left any.
     *
     * A refusal that quotes its reason can be acted on; one that does not sends
     * the vendor back to ask what was wrong.
     */
    private static function because(Model $medical): string
    {
        $note = trim((string) ($medical->qc_note ?? ''));
        if ($note !== '') {
            return ': "'.rtrim($note, '.').'".';
        }

        $code = trim((string) ($medical->qc_reason_code ?? ''));
        if ($code !== '') {
            return ' ('.str_replace('_', ' ', $code).').';
        }

        return '.';
    }

    /**
     * How long it has been waiting.
     *
     * "Submitted 6 days ago" is the difference between a queue that is moving
     * and one that has been forgotten — and it is the fact a vendor needs before
     * deciding whether chasing is reasonable.
     */
    private static function submittedAgo(Model $medical): string
    {
        $at = $medical->updated_at ?? $medical->created_at ?? null;

        return $at ? ' '.$at->diffForHumans() : '';
    }

    /** @return array{status:string, message:string, owner:string, action:string|null} */
    private static function row(string $status, string $message, string $owner, ?string $action): array
    {
        return compact('status', 'message', 'owner', 'action');
    }
}
