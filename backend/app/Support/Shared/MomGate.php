<?php

namespace App\Support\Shared;

use App\Exceptions\BusinessException;

/**
 * Minutes describe a meeting that happened.
 *
 * Only `submitMomForApproval` ever checked this, and only on the Purchase side.
 * Generating the PDF and uploading a file both went straight through, so a
 * meeting still sitting at Scheduled — or one that was Cancelled — could carry
 * a signed-looking set of minutes recording decisions nobody had made yet. The
 * approval workflow then had a document to approve, which is how a record of a
 * meeting that never took place acquires signatures.
 *
 * One rule, both engines, every entry point: Completed, or nothing.
 */
final class MomGate
{
    /** The only meeting state whose minutes mean anything. */
    public const COMPLETED = 'Completed';

    /**
     * @param  string|null  $status  the meeting's current status
     * @param  string  $what  'generate' | 'upload', for the message
     *
     * @throws BusinessException
     */
    public static function assertCompleted(?string $status, string $what = 'generate'): void
    {
        if ($status === self::COMPLETED) {
            return;
        }

        $verb = $what === 'upload' ? 'Uploading' : 'Generating';
        $state = $status ? strtolower($status) : 'not completed';

        throw new BusinessException(
            "{$verb} minutes needs the meeting marked Completed — this one is {$state}. "
            .'Mark the meeting complete once it has actually taken place, then produce its minutes.',
            422,
        );
    }
}
