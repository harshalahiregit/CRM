<?php

namespace Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

/**
 * One shift, four doors, one meaning.
 *
 * A punch can start from the header pill, the attendance card on the main
 * dashboard, the same card on the HR dashboard, or the SangoeTrack phone app.
 * They all move ONE row — hr_attendance is unique on (tenant, employee, date) —
 * so what they record has to agree.
 *
 * It did not. HeaderPunch read the workspace policy, asked for GPS, opened the
 * camera when a selfie was required and wrote a verification note.
 * MyAttendanceCard called checkIn() with nothing at all. The same person, on the
 * same day, left a fully evidenced punch or a bare one depending purely on which
 * control they happened to click — and a workspace that REQUIRED a selfie could
 * be walked past by using the dashboard instead of the top bar.
 *
 * Nobody notices that until an attendance record is questioned and half the
 * evidence is missing.
 *
 * Source-level because the frontend has no test runner — the same approach as
 * AttendanceTimeFormattingTest and BannedPatternsTest beside this file.
 */
class PunchSurfacesAgreeTest extends TestCase
{
    private const SRC = __DIR__.'/../../../../frontend/src';

    /** Every web control that can start or end a shift. */
    private const SURFACES = [
        'components/layout/HeaderPunch.jsx',
        'modules/hr/components/MyAttendanceCard.jsx',
    ];

    private function read(string $path): string
    {
        $full = self::SRC.'/'.$path;
        $this->assertFileExists($full, "{$path} has moved — update this test to follow it.");

        return file_get_contents($full);
    }

    /* ── one implementation ───────────────────────────────────────────── */

    public function test_every_punch_surface_goes_through_the_shared_hook(): void
    {
        foreach (self::SURFACES as $file) {
            $this->assertStringContainsString('usePunch', $this->read($file),
                "{$file} starts a shift and must punch through usePunch, or it will drift again.");
        }
    }

    /**
     * The defect itself: calling the endpoint directly skips the policy, the
     * location, the selfie and the note in one go.
     */
    public function test_no_surface_calls_the_punch_endpoints_directly(): void
    {
        foreach (self::SURFACES as $file) {
            $src = $this->read($file);

            foreach (['attendance.me.checkIn', 'attendance.me.checkOut'] as $call) {
                $this->assertStringNotContainsString($call, $src,
                    "{$file} calls {$call} itself — that is the bare punch, with no evidence attached.");
            }
        }
    }

    /* ── what the shared hook must actually do ────────────────────────── */

    public function test_the_shared_hook_carries_the_evidence(): void
    {
        $hook = $this->read('modules/hr/hooks/usePunch.js');

        foreach (['getLocation', 'buildNote', 'web_punch_require_selfie', 'web_punch_require_location'] as $needle) {
            $this->assertStringContainsString($needle, $hook,
                "usePunch must still gather {$needle} — it is the only thing doing so now.");
        }
    }

    /**
     * The camera stays with the components on purpose: a dialog belongs to
     * whoever can render one. Both must therefore offer it, or the surface
     * without it silently becomes the way to avoid the photo.
     */
    public function test_both_surfaces_can_open_the_camera(): void
    {
        foreach (self::SURFACES as $file) {
            $src = $this->read($file);

            $this->assertStringContainsString('SelfieCapture', $src,
                "{$file} cannot ask for a selfie, so a workspace requiring one can be walked past here.");
            $this->assertStringContainsString('needsSelfie', $src,
                "{$file} must read needsSelfie rather than deciding for itself.");
        }
    }

    /* ── and that a punch anywhere shows up everywhere ────────────────── */

    /**
     * The header and both dashboards read one query key, so a punch on any of
     * them lands on the others immediately. The phone app cannot do that — it
     * writes to the API and the browser is never told — which is what the focus
     * refetch and the interval are for. There are no websockets here
     * (BROADCAST_CONNECTION is `log`), so this is the live-ness the stack has.
     */
    public function test_the_shared_query_keeps_itself_current(): void
    {
        $hook = $this->read('modules/hr/hooks/useMyAttendanceToday.js');

        $this->assertStringContainsString('refetchOnWindowFocus: true', $hook,
            'A punch made on the phone must be picked up when the tab is looked at again.');
        $this->assertStringContainsString('refetchInterval', $hook,
            'A dashboard left open must notice a punch made elsewhere.');
    }

    public function test_the_surfaces_share_one_query_key(): void
    {
        foreach (self::SURFACES as $file) {
            $this->assertStringContainsString('useMyAttendanceToday', $this->read($file),
                "{$file} must read the shared attendance query, not fetch its own copy.");
        }
    }
}
