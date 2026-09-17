<?php

namespace App\Services\Hr\Attendance;

use App\Models\Hr\HrAttendance;
use App\Models\Hr\HrEmployee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Where a punch happened, who made it, and — when that cannot be shown — why.
 *
 * One writer for both callers. The phone app (Api\Hrm) and the CRM's own header
 * button (Api\Hr) record the same evidence against the same columns; two
 * implementations would drift, and the register cannot tell which client a row
 * came from.
 *
 * Best effort, always. A punch is NEVER refused because a photo could not be
 * stored or a browser withheld coordinates — somebody who turned up has done
 * their part, and losing a day's attendance over a webcam is not a trade anyone
 * would choose. What changes instead is the verification note: evidence that is
 * missing says so, in words, against that punch.
 */
class PunchEvidence
{
    /**
     * @param 'in'|'out' $side
     */
    public function record(Request $request, HrAttendance $attendance, HrEmployee $employee, string $side): void
    {
        $out = $side === 'out';

        $fields = array_filter([
            ($out ? 'check_out_latitude'  : 'check_in_latitude')  => $request->input('latitude'),
            ($out ? 'check_out_longitude' : 'check_in_longitude') => $request->input('longitude'),
            // Filled when the caller already has a human-readable address. The
            // browser gives coordinates only, so this stays null until a
            // reverse-geocode exists — the column is ready for it.
            ($out ? 'check_out_address'   : 'check_in_address')   => $request->input('address'),
            // Never from the client: the server sees the real address.
            ($out ? 'check_out_ip'        : 'check_in_ip')        => $request->ip(),
        ], fn ($v) => $v !== null && $v !== '');

        if ($request->hasFile('selfie')) {
            try {
                $fields[$out ? 'check_out_selfie' : 'check_in_selfie'] = $request->file('selfie')->store(
                    "hr/attendance/tenant_{$employee->tenant_id}/{$employee->id}",
                    'local',
                );
            } catch (\Throwable $e) {
                Log::channel('hr')->warning('Punch selfie not stored', [
                    'employee' => $employee->id, 'side' => $side, 'error' => $e->getMessage(),
                ]);
            }
        }

        // The note is what makes a blank column readable later. "Not asked",
        // "camera unavailable" and "declined" are three different conversations
        // with an employee, and a NULL is none of them.
        $note = $this->note($request, $fields, $out);
        if ($note !== null) {
            $fields[$out ? 'check_out_verification' : 'check_in_verification'] = $note;
        }

        if ($fields !== []) {
            $attendance->forceFill($fields)->save();
        }
    }

    /**
     * A short, human sentence for the register. The client sends what went
     * wrong (it is the only thing that knows); anything it does not explain is
     * summarised from what actually arrived.
     */
    private function note(Request $request, array $fields, bool $out): ?string
    {
        $given = trim((string) $request->input('verification_note', ''));
        if ($given !== '') {
            return mb_substr($given, 0, 120);
        }

        $hasSelfie = isset($fields[$out ? 'check_out_selfie' : 'check_in_selfie']);
        $hasCoords = isset($fields[$out ? 'check_out_latitude' : 'check_in_latitude']);

        if ($hasSelfie && $hasCoords) {
            return 'Verified — selfie and location';
        }
        if ($hasSelfie) {
            return 'Selfie only — no location';
        }
        if ($hasCoords) {
            return 'Location only — no selfie';
        }

        return null; // nothing captured and nothing explained: leave it blank
    }
}
