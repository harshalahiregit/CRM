<?php

namespace Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

/**
 * A time the user picks must reach the API as an INSTANT, never as a wall clock.
 *
 * ── THE BUG THIS EXISTS FOR ──────────────────────────────────────────────
 * `fromLocalInput()` turned a `datetime-local` value into "2026-09-20 14:00:00"
 * — no zone, no offset. The API runs in UTC, so Carbon read that as 14:00 UTC.
 * A dispatcher in IST who typed 2pm got 7:30pm back.
 *
 * Worse, the transit panel DEFAULTS that field to now. "Now" in IST is five and
 * a half hours in the future in UTC, so the server refused it — and both
 * "Record departure" and "Record delivery" failed on the very first click, for
 * every user not sitting on UTC. The two headline actions of Block 3 could not
 * be performed at all.
 *
 * ── WHY NO TEST CAUGHT IT ────────────────────────────────────────────────
 * Every service test builds its times on the SERVER, where there is no
 * conversion to get wrong. The suite was green, 1311 tests, while the feature
 * did not work. It was found by clicking the button.
 *
 * ── WHY A PHP TEST FOR A JAVASCRIPT PROBLEM ──────────────────────────────
 * The same reason TransportLinkTargetsTest is one: this suite is what runs. It
 * reads the file as text, which is enough — what matters is a single literal.
 */
class TransportDateTimeContractTest extends TestCase
{
    /** Same anchor as TransportLinkTargetsTest — no app is booted here. */
    private const FRONTEND = __DIR__.'/../../../../frontend/src';

    private function constants(): string
    {
        $path = self::FRONTEND.'/modules/transport/constants.js';
        $this->assertFileExists($path, 'transport constants.js has moved — update this guard');

        return file_get_contents($path);
    }

    public function test_from_local_input_sends_a_zoned_instant(): void
    {
        $src = $this->constants();

        preg_match('/export const fromLocalInput = .*?\n\}/s', $src, $m);
        $this->assertNotEmpty($m, 'fromLocalInput has been renamed or removed');

        $body = $m[0];

        $this->assertStringContainsString(
            'toISOString()',
            $body,
            "fromLocalInput must return an ISO-8601 instant (toISOString), which carries the\n"
            ."browser's offset. Anything zoneless is read as UTC by the API and silently shifts\n"
            .'every time a user picks. See the class docblock.',
        );
    }

    public function test_it_never_goes_back_to_a_zoneless_wall_clock(): void
    {
        $src = $this->constants();

        preg_match('/export const fromLocalInput = .*?\n\}/s', $src, $m);
        $body = $m[0] ?? '';

        // The exact shape of the bug: swapping the T for a space and bolting
        // seconds on the end. It looks harmless and it is not.
        $this->assertDoesNotMatchRegularExpression(
            "/replace\(\s*['\"]T['\"]\s*,\s*['\"] ['\"]\s*\)/",
            $body,
            'fromLocalInput is building a zoneless wall-clock string again. That is the exact '
            .'bug that made Record departure and Record delivery impossible outside UTC.',
        );
    }

    public function test_the_transit_panel_lets_the_server_stamp_its_own_now(): void
    {
        $path = self::FRONTEND.'/modules/transport/components/JourneyPanel.jsx';
        $this->assertFileExists($path);
        $src = file_get_contents($path);

        // When the person has not chosen a time, send nothing. Sending our idea
        // of "now" means a browser a few seconds ahead of the API gets "that is
        // in the future" on the default click.
        $this->assertMatchesRegularExpression(
            '/edited\s*\?\s*fromLocalInput\(when\)\s*:\s*null/',
            $src,
            'JourneyPanel must send null when the time field is untouched, so the server uses '
            .'its own clock and no skew can refuse the default action.',
        );
    }
}
