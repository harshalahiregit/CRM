<?php

namespace Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

/**
 * D-54 — a test fixture must not draw a unique identifier at random.
 *
 * The Transport suite used to fail about one run in five. Fifteen files built
 * vehicles as `'MH12AB'.random_int(1000, 9999)` — 9,000 values against
 * UNIQUE(tenant_id, registration_normalized) — so two draws inside one test
 * method collided. It surfaced inside TransportVehicleService, which is not
 * where the cause was, and a rare failure gets re-run rather than fixed.
 *
 * All fifteen now use TestCase::uniqueSeq(), which cannot repeat. This stops
 * the pattern coming back, because the next person adding a vehicle fixture
 * will copy whichever line they happen to look at.
 *
 * SCOPE: Transport only. The same defect exists in SangoeTrack with a 90-value
 * space (hr_leave_types.code), and that is reported, not policed here — it is
 * not this section's to enforce.
 */
class TransportFixtureIdentifiersTest extends TestCase
{
    private const DIR = __DIR__.'/../../Feature/Transport';

    /** Columns whose uniqueness a random draw can violate. */
    private const UNIQUE_FIXTURE_FIELDS = [
        'registration_number',
        'licence_number',
        'container_number',
        'driver_code',
        'hr_employee_id',
    ];

    public function test_no_transport_fixture_draws_a_unique_identifier_at_random(): void
    {
        $offenders = [];

        foreach (glob(self::DIR.'/*.php') as $file) {
            foreach (file($file) as $i => $line) {
                if (! preg_match('/\b(random_int|mt_rand|rand)\s*\(/', $line)) {
                    continue;
                }

                foreach (self::UNIQUE_FIXTURE_FIELDS as $field) {
                    if (str_contains($line, $field)) {
                        $offenders[] = sprintf('%s:%d  %s', basename($file), $i + 1, trim($line));
                    }
                }
            }
        }

        $this->assertSame([], $offenders, sprintf(
            "These fixtures draw a UNIQUE identifier at random, which is D-54 returning:\n\n  %s\n\n"
            ."Use TestCase::uniqueSeq() instead — it is monotonic for the life of the process and "
            ."cannot collide. Widening the range is not a fix: it makes the failure rarer, and a "
            .'rare failure gets re-run rather than fixed.',
            implode("\n  ", $offenders),
        ));
    }

    public function test_the_scanner_is_actually_reading_the_fixtures(): void
    {
        // Without this, a wrong path would leave the test above passing forever
        // while checking nothing.
        $files = glob(self::DIR.'/*.php');
        $this->assertGreaterThanOrEqual(30, count($files), 'the Transport test directory was not found');

        $usingSeq = 0;
        foreach ($files as $f) {
            if (str_contains(file_get_contents($f), 'uniqueSeq(')) {
                $usingSeq++;
            }
        }

        $this->assertGreaterThanOrEqual(15, $usingSeq,
            'the fixtures that were converted to uniqueSeq() have gone — either the files moved '
            .'or the D-54 fix was reverted');
    }
}
