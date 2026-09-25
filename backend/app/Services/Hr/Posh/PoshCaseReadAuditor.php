<?php

namespace App\Services\Hr\Posh;

use App\Models\Hr\HrPoshCase;
use App\Models\Hr\HrPoshCaseRead;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Recording that somebody read a case.
 *
 * On a harassment file, WHO OPENED IT is itself part of the record. Nothing
 * else in this product logs a read — AuditLogService is only ever called on
 * writes — so this is new capability rather than a wrapper over something
 * existing.
 *
 * INSERT ONLY. There is no update method and no delete method, here or on the
 * model, because the value of the table is that it cannot be tidied up
 * afterwards.
 *
 * SUCCESSFUL READS ONLY. A refused attempt did not read anything, and putting
 * it here would make the table mean two different things. Refusals are
 * security events and belong on the ordinary audit trail, where they are
 * visible without implying content was served.
 *
 * Deliberately NOT audit_logs. Every audit browser in the product queries that
 * table; a POSH read appearing there would surface who opened a harassment
 * file on a screen built for approvals and onboarding.
 */
class PoshCaseReadAuditor
{
    public const SURFACE_SHOW = 'case.show';
    public const SURFACE_MEMBERS = 'case.members';
    public const SURFACE_THREAD = 'case.thread';
    public const SURFACE_ATTACHMENTS = 'case.attachment';
    public const SURFACE_DOWNLOAD = 'case.attachment.download';
    public const SURFACE_INQUIRY = 'case.inquiry';
    public const SURFACE_FINDINGS = 'case.findings';

    // The complainant's own link. Kept distinct from the case.* surfaces so
    // "who on the committee opened this file" and "the complainant checked on
    // it" never have to be told apart by guessing.
    public const SURFACE_PORTAL_SHOW = 'portal.show';
    public const SURFACE_PORTAL_THREAD = 'portal.thread';

    /**
     * Write one read.
     *
     * Called AFTER authorisation has succeeded, never before — a row written
     * first would claim a read that the next line refused.
     *
     * Never throws. A failure to record must not fail the request that was
     * legitimately authorised, and swallowing is the same choice the
     * notification path makes for the same reason. The failure is logged so a
     * gap in the trail is discoverable rather than silent.
     */
    public function record(HrPoshCase $case, ?User $actor, string $surface, ?string $ip = null): void
    {
        $this->write($case, $actor?->id, $actor?->name ?: 'unknown', $surface, $ip);
    }

    /**
     * A read by somebody who is not a User at all.
     *
     * The token complainant the nullable actor_id column was created for. They
     * hold no account and no membership, so there is no id to record and the
     * label carries the whole identity. The label comes from the token row and
     * is the case reference — never the complainant's name, and never any part
     * of the token.
     */
    public function recordAnonymous(HrPoshCase $case, string $label, string $surface, ?string $ip = null): void
    {
        $this->write($case, null, $label, $surface, $ip);
    }

    private function write(HrPoshCase $case, ?int $actorId, string $label, string $surface, ?string $ip): void
    {
        try {
            HrPoshCaseRead::create([
                'tenant_id'   => $case->tenant_id,
                'case_id'     => $case->id,
                'actor_id'    => $actorId,
                // Always written, so the trail is never anonymous even when
                // the reader has no account.
                'actor_label' => $label,
                'surface'     => $surface,
                'ip'          => $ip,
                'read_at'     => now(),
            ]);
        } catch (\Throwable $e) {
            Log::channel('hr')->warning('POSH read audit failed: '.$e->getMessage(), [
                'case_id' => $case->id, 'surface' => $surface,
            ]);
        }
    }
}
