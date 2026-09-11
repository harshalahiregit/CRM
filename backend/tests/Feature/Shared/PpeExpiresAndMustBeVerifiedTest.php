<?php

namespace Tests\Feature\Shared;

use App\Support\Shared\PpeReplacement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Two PPE settings that did nothing.
 *
 * `replacement_frequency_days` and `verification_required` have been on both
 * engines' requirement matrices since the matrix was built — settable on the
 * screen, validated on the way in, saved, and returned in every API payload —
 * and nothing in the backend has ever read either of them.
 *
 * A helmet issued three years ago satisfied the badge check exactly as well as
 * one issued this morning. A rule saying "a fall-arrest harness must be checked
 * before use" was met by handing the harness over and walking away; there was
 * not even a column to record a check in. Both are the kind of setting that is
 * worse than absent, because the screen says the control exists.
 *
 * These pin the rule itself. The services then read it through one shared
 * class, so a worker's gear cannot be expired on one side of the site and
 * current on the other.
 */
class PpeExpiresAndMustBeVerifiedTest extends TestCase
{
    use RefreshDatabase;

    public function test_gear_with_no_interval_never_expires(): void
    {
        $this->assertFalse(PpeReplacement::isExpired('2019-01-01', null),
            'most rules set no interval, and a blank field must never start blocking badges that '
            .'were fine yesterday');
        $this->assertFalse(PpeReplacement::isExpired('2019-01-01', 0));
        $this->assertNull(PpeReplacement::dueOn('2019-01-01', null));
    }

    public function test_gear_that_was_never_issued_has_no_due_date(): void
    {
        $this->assertNull(PpeReplacement::dueOn(null, 180));
        $this->assertFalse(PpeReplacement::isExpired(null, 180));
    }

    public function test_gear_inside_its_interval_still_counts(): void
    {
        $issued = now()->subDays(30);

        $this->assertFalse(PpeReplacement::isExpired($issued, 180));
        $this->assertSame(150, PpeReplacement::daysRemaining($issued, 180));
    }

    public function test_gear_past_its_interval_does_not(): void
    {
        $issued = now()->subDays(200);

        $this->assertTrue(PpeReplacement::isExpired($issued, 180),
            'a helmet issued 200 days ago against a 180-day rule is overdue and must stop counting');
        $this->assertSame(-20, PpeReplacement::daysRemaining($issued, 180));
    }

    /**
     * Due today is not yet overdue.
     *
     * Turning someone away at the gate on the morning their interval lands
     * would be a surprise nobody configured; they have the day to swap it.
     */
    public function test_the_day_it_falls_due_is_still_good(): void
    {
        $issued = now()->subDays(180);

        $this->assertSame(
            now()->startOfDay()->toDateString(),
            PpeReplacement::dueOn($issued, 180)->toDateString(),
        );
        $this->assertFalse(PpeReplacement::isExpired($issued, 180));
        $this->assertTrue(PpeReplacement::isExpired(now()->subDays(181), 180),
            'the day after it falls due, it is overdue');
    }

    /** Both engines must read the interval, and read it the same way. */
    public function test_both_engines_consult_the_replacement_rule(): void
    {
        foreach ([
            'app/Services/Tpv/PpeInventoryService.php',
            'app/Services/Purchase/PurchasePpeService.php',
        ] as $file) {
            $src = (string) file_get_contents(base_path($file));

            $this->assertStringContainsString('PpeReplacement::isExpired', $src,
                "{$file} no longer checks whether issued gear is past its replacement date — the "
                .'interval becomes a setting that does nothing again');
        }
    }

    /**
     * Expired and unverified gear must reach the BADGE, not merely the screen.
     *
     * `missingMandatoryFor` is what the badge and the site gate read. Showing an
     * expiry on a compliance table while still issuing the badge would be the
     * same bug wearing a nicer hat.
     */
    public function test_the_badge_check_is_what_reads_it(): void
    {
        foreach ([
            'app/Services/Tpv/PpeInventoryService.php',
            'app/Services/Purchase/PurchasePpeService.php',
        ] as $file) {
            $src = (string) file_get_contents(base_path($file));

            $start = strpos($src, 'function missingMandatoryFor');
            $this->assertNotFalse($start, "missingMandatoryFor has moved in {$file}");

            $body = substr($src, $start, 700);

            $this->assertStringContainsString('issueSatisfies', $body,
                "{$file}'s badge check no longer asks whether the held item actually satisfies its "
                .'rule — expired or unverified gear would pass the gate again');
        }
    }

    /** A verification flag needs somewhere to record a verification. */
    public function test_there_is_somewhere_to_record_a_verification(): void
    {
        foreach (['tpv_worker_ppe_issues', 'purchase_worker_ppe_issues'] as $table) {
            foreach (['verified_at', 'verified_by', 'verification_notes'] as $column) {
                $this->assertTrue(
                    \Illuminate\Support\Facades\Schema::hasColumn($table, $column),
                    "{$table}.{$column} is missing — verification_required cannot be satisfied "
                    .'without it, which is why nothing read the flag for so long',
                );
            }
        }
    }

    /** And a way for a person to do it, on both engines. */
    public function test_both_engines_expose_a_verify_action(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->map(fn ($r) => $r->uri())
            ->filter(fn ($u) => str_contains($u, 'ppe/issues') && str_ends_with($u, 'verify'))
            ->values();

        $this->assertCount(2, $routes,
            'both engines must offer a verify action, or the flag is satisfiable on one side only: '
            .$routes->implode(', '));
    }
}
