<?php

namespace Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

/**
 * One record, five screens, two answers 5h30m apart.
 *
 * Attendance is stored in UTC on purpose — config/app.php says so, and warns
 * that setting the app timezone to Asia/Kolkata "to fix attendance times" broke
 * numbering and localisation instead. Presentation converts. The Attendance
 * Register did; the dashboard card, the top-bar punch widget, the attendance
 * report and both correction screens took a substring of the ISO string, which
 * is the UTC clock face with the date cut off. A punch made at 10:53 IST read
 * 05:23 on the dashboard.
 *
 * The correction queue was the worst of them, and the least visible: it puts
 * "now" beside "asked for", where now is a stored TIMESTAMP and asked-for is a
 * `time` column the employee typed. Slicing both compared a UTC clock face
 * against a local one, so a request to move a punch two minutes looked like a
 * five-hour move and a request that changed nothing never showed "(no change)".
 *
 * These tests hold the shape of the fix — one shared helper, used everywhere a
 * stored timestamp is rendered, and NOT used to "convert" the bare time values,
 * which would be the same bug pointing the other way.
 *
 * Source-level because the frontend has no test runner; the same approach as
 * HrActionVisibilityTest and BannedPatternsTest beside this file.
 */
class AttendanceTimeFormattingTest extends TestCase
{
    private const SRC = __DIR__.'/../../../../frontend/src';

    private function read(string $path): string
    {
        $full = self::SRC.'/'.$path;
        $this->assertFileExists($full, "{$path} has moved — update this test to follow it.");

        return file_get_contents($full);
    }

    /** Every screen that renders a stored attendance timestamp. */
    private const CONSUMERS = [
        'modules/hr/components/MyAttendanceCard.jsx',
        'components/layout/HeaderPunch.jsx',
        'modules/hr/pages/AttendanceReports.jsx',
        'modules/hr/pages/MyCorrections.jsx',
        'modules/hr/pages/Corrections.jsx',
    ];

    /* ── the helper ───────────────────────────────────────────────────── */

    public function test_the_shared_helper_exists_and_is_exported(): void
    {
        $c = $this->read('modules/hr/constants.js');

        $this->assertStringContainsString('export const hrTime', $c);
        $this->assertStringContainsString('export const hrTimeEquals', $c);
    }

    /**
     * It must distinguish the two shapes rather than converting everything.
     *
     * A bare "09:15" carries no date and no zone; parsing it as an instant
     * would invent both and move the value.
     */
    public function test_the_helper_tells_timestamps_from_bare_times(): void
    {
        $c = $this->read('modules/hr/constants.js');

        $this->assertStringContainsString('const isInstant', $c,
            'The helper must test the shape before deciding to convert.');
        $this->assertMatchesRegularExpression('/\\\\d\{4\}-\\\\d\{2\}-\\\\d\{2\}\[T /', $c,
            'The shape test should recognise a leading calendar date.');
        $this->assertStringContainsString('toLocaleTimeString', $c,
            'Timestamps must be localised, not sliced.');
    }

    /* ── adoption ─────────────────────────────────────────────────────── */

    public function test_every_attendance_screen_uses_the_shared_helper(): void
    {
        foreach (self::CONSUMERS as $file) {
            $this->assertStringContainsString('hrTime', $this->read($file),
                "{$file} renders stored attendance times and must use the shared helper.");
        }
    }

    /**
     * The substring is gone from every one of them.
     *
     * slice(11, 16) on an ISO string is exactly the bug: it reads the UTC hour
     * and minute and discards the offset that would have corrected them.
     */
    public function test_no_attendance_screen_slices_a_timestamp_any_more(): void
    {
        foreach (self::CONSUMERS as $file) {
            $this->assertDoesNotMatchRegularExpression(
                '/slice\(11, ?16\)/',
                $this->read($file),
                "{$file} still takes the UTC substring — that is the 5h30m bug."
            );
        }
    }

    /**
     * The Register is the screen that was always right. It must keep converting,
     * so the others have something to agree WITH.
     */
    public function test_the_attendance_register_still_converts(): void
    {
        $src = $this->read('modules/hr/pages/Attendance.jsx');

        $this->assertStringContainsString('toLocaleTimeString', $src);
        $this->assertStringContainsString('hour12: false', str_replace('hour12:false', 'hour12: false', $src));
    }

    /* ── what must NOT be converted ───────────────────────────────────── */

    /**
     * requested_check_in and its siblings are `time` columns holding values
     * like "09:15" — already local wall-clock. They are rendered with a plain
     * five-character slice, and that is correct.
     */
    public function test_bare_time_fields_are_left_alone(): void
    {
        $src = $this->read('modules/hr/pages/MyCorrections.jsx');

        $this->assertStringContainsString('slice(0, 5)', $src,
            'The requested_* values are bare times and must not be date-parsed.');
    }

    /* ── the comparison that decides an approval ──────────────────────── */

    /**
     * "Now → asked for" compares a timestamp with a bare time. Both sides have
     * to be normalised before === means anything.
     */
    public function test_the_correction_comparison_normalises_both_sides(): void
    {
        $src = $this->read('modules/hr/pages/Corrections.jsx');

        $this->assertStringContainsString('hrTimeEquals(from, to)', $src,
            'The change/no-change decision must compare normalised values.');
        $this->assertDoesNotMatchRegularExpression(
            '/const same = hhmm\(from\) === hhmm\(to\)/',
            $src,
            'Raw string comparison compared a UTC clock face against a local one.'
        );
    }

    /* ── payroll: the period figure, not the structure snapshot ───────── */

    /**
     * The payroll run list showed the salary STRUCTURE's total_deductions,
     * which is zero for every structure that defines none of its own — that is
     * all of them, because PF, ESIC, PT and TDS are statutory and resolved per
     * period. So the column read ₹0 beside a Net of ₹14,000 while ₹360 had
     * been withheld and ₹13,640 reached the bank.
     *
     * period_deductions was already in the payload; PayrollService calls it
     * "the one to show a human". Nothing in the calculation changed.
     */
    public function test_the_payroll_run_list_shows_period_deductions(): void
    {
        $src = $this->read('modules/hr/pages/Payroll.jsx');

        $this->assertStringContainsString('r.period_deductions', $src,
            'The Deductions column must read the period figure, not the structure snapshot.');
        $this->assertStringContainsString('r.net_payable', $src,
            'Net Payable must remain the take-home column.');
    }

    /**
     * Structure figures keep their own field — this was NOT a search-and-replace
     * of net_salary. A payslip row's net_salary is already netPayable() (see
     * PayslipService), and a salary structure's net_salary is the structural
     * net. Both are correct; only the labels needed to say which is which.
     */
    public function test_structure_values_are_relabelled_not_rewired(): void
    {
        foreach ([
            'modules/hr/components/EmployeeSalarySection.jsx',
            'modules/hr/components/SalarySheet.jsx',
            'modules/hr/pages/EmployeeProfile.jsx',
            'modules/hr/pages/Payroll.jsx',
        ] as $file) {
            $src = $this->read($file);
            $this->assertStringContainsString('Structure Net', $src,
                "{$file} must name the structure figure as such.");

            // The rendered LABEL, not the file — the phrase survives in prose
            // explaining why it is wrong, which is the opposite of the problem.
            $this->assertDoesNotMatchRegularExpression(
                '/(label|k)=("|\')[^"\']*In Hand/',
                $src,
                "{$file} must not label a structure figure as take-home."
            );
        }

        // The underlying field is untouched — the value was never wrong.
        $this->assertStringContainsString('cur.net_salary', $this->read('modules/hr/components/EmployeeSalarySection.jsx'));
    }

    /* ── the undefined-variable crash ─────────────────────────────────── */

    /**
     * Two worker wizards referenced isPortal in a component that never received
     * it — a ReferenceError the moment the Doctor Details block rendered. Each
     * file already had a convention for carrying the flag; the fix follows the
     * file rather than introducing a third way.
     */
    public function test_the_worker_wizards_define_is_portal_where_they_use_it(): void
    {
        $purchase = $this->read('modules/purchase/pages/PurchaseWorkerWizard.jsx');
        $tpv      = $this->read('modules/tpv/pages/TpvWorkerWizard.jsx');

        // Purchase reads the hook per component.
        $this->assertSame(
            3,
            substr_count($purchase, 'portal: isPortal'),
            'Every component in PurchaseWorkerWizard that uses isPortal must destructure it.'
        );

        // TPV computes it once and passes it down, the way it passes api.
        $this->assertStringContainsString('onNext, api, isPortal }', $tpv,
            'Step2Medical must receive isPortal as a prop.');
        $this->assertStringContainsString('isPortal={isPortal}', $tpv,
            'The call site must pass it.');
    }
}
