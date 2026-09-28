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

    public function test_every_datetime_field_in_the_module_is_converted_before_it_is_sent(): void
    {
        // THIS IS THE TEST THAT WOULD HAVE CAUGHT THE SIBLING. Fixing
        // fromLocalInput fixed the two panels that CALLED it, and left
        // TransportOrderForm — which had a datetime-local and called nothing —
        // still shipping a raw wall clock. It was found by sweeping, which is
        // the expensive way to find the second instance of a bug you just fixed.
        //
        // A file that offers a datetime-local must also convert one.
        $offenders = [];

        foreach ($this->moduleFiles() as $path) {
            // Comments stripped first. The guard flagged TransportOrders.jsx on
            // its first run because a comment there EXPLAINS this bug — the same
            // trap the D-63 seeder guard and the D-106 caller scan both fell
            // into. A guard that cannot tell code from prose gets weakened by
            // whoever it wrongly accuses.
            //
            // `[^\n]*` and not `.*$` with /s. With the s flag `.` matches
            // newlines, so `//.*$` runs greedily from the FIRST comment to the
            // last line of the file and strips everything after it — the guard
            // then scans a nearly empty string and passes on anything. That was
            // true here and in TripClosureTest until it was measured.
            $src = preg_replace('#//[^\n]*|/\*.*?\*/#s', '', file_get_contents($path));

            // IN SCOPE TWO WAYS, and the second was a blind spot.
            //
            //   1. the file renders a datetime-local directly;
            //   2. the file CONSUMES a field config that declares one, like
            //      DispatchPanel — it renders `type={field.type}` out of
            //      DISPATCH_FIELDS and never writes the literal itself.
            //
            // Only (1) was checked at first. Deleting DispatchPanel's
            // conversion also deleted its last mention of the literal, so the
            // file dropped out of scope and the guard stayed green over a real
            // regression. Found by breaking this guard a SECOND way, in a
            // second file — which is the rule that came out of the first blind
            // spot, applied to the guard that came out of it.
            $inScope = preg_match('/type=\s*[{"\']\s*["\']?datetime-local/', $src) === 1;

            foreach ($this->datetimeFieldConfigs() as $config) {
                if ($inScope) {
                    break;
                }
                $inScope = preg_match('/\b'.preg_quote($config, '/').'\b/', $src) === 1;
            }

            if (! $inScope) {
                continue;
            }

            // constants.js DEFINES the converter rather than calling it.
            if (str_ends_with($path, 'constants.js')) {
                continue;
            }

            if (! str_contains($src, 'fromLocalInput')) {
                $offenders[] = str_replace(self::FRONTEND.'/', '', $path);
            }
        }

        $this->assertSame([], $offenders, sprintf(
            "These files render a datetime-local and never convert it before sending:\n  %s\n\n"
            ."A raw datetime-local value is a wall clock with no zone. The API is UTC, so it is "
            ."read as UTC and the time silently shifts by the user's offset — or, if the field "
            .'defaults to now, the action is refused as "in the future". Use fromLocalInput().',
            implode("\n  ", $offenders),
        ));
    }

    /**
     * Exported field lists in constants.js that declare a datetime-local field.
     *
     * Read from the source, so a new config is covered without anyone having to
     * remember this test exists.
     *
     * Walked line by line and NOT matched with one array-spanning regex. The
     * first attempt used `/export const ([A-Z_]+)\s*=\s*\[(.*?)\n\]/s` and
     * reported PRETRIP_CATEGORY_ORDER, a list of plain strings with no fields
     * in it: the lazy capture ran past its own closing bracket into the next
     * declaration. Same family of mistake as the /s comment-stripper this whole
     * test exists because of — a pattern matching more than its author pictured.
     *
     * @return array<int,string>
     */
    private function datetimeFieldConfigs(): array
    {
        $current = null;
        $out = [];

        foreach (explode("\n", $this->constants()) as $line) {
            if (preg_match('/^export const ([A-Z][A-Z0-9_]*)\s*=\s*\[/', $line, $m) === 1) {
                $current = $m[1];
            } elseif (str_starts_with($line, ']')) {
                $current = null;
            } elseif ($current !== null && str_contains($line, 'datetime-local')) {
                $out[$current] = true;
                $current = null;
            }
        }

        return array_keys($out);
    }

    /** @return array<int,string> every js/jsx file in the transport module */
    private function moduleFiles(): array
    {
        $dir = self::FRONTEND.'/modules/transport';
        $out = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        foreach ($it as $file) {
            if (! $file->isDir() && in_array($file->getExtension(), ['js', 'jsx'], true)) {
                $out[] = $file->getPathname();
            }
        }

        return $out;
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
