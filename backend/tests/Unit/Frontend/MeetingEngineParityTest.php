<?php

namespace Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

/**
 * One meeting UI, two engines — and nothing to stop the second one going stale.
 *
 * The shared meeting screens run over both the shared engine and Purchase's.
 * `meetingEngineApi` resolves per call: under /app/tpv it forwards to
 * kickoffApi, under /app/purchase to purchaseKickoffApi, which is a hand-written
 * adapter that renames and re-nests Purchase's own calls.
 *
 * The adapter is a LIST. Adding a method to the shared client and forgetting the
 * adapter compiles cleanly, builds cleanly, passes every test, and then throws
 * "api.<name> is not a function" in the browser — but only for a user who is in
 * the Purchase module, which is why it reaches people rather than developers.
 *
 * That has now happened twice in one change: markOwnAttendance and
 * attendanceRegister were both added to the shared client and both missing from
 * the adapter. This is the check that would have caught them.
 *
 * It reads the two files as text rather than executing them, because there is no
 * JavaScript test runner in this repo — the same approach BannedPatternsTest
 * takes for the .jsx files.
 */
class MeetingEngineParityTest extends TestCase
{
    private const SERVICES = __DIR__.'/../../../../frontend/src/services';

    /**
     * Methods the shared client has that the Purchase adapter deliberately does
     * not, with the reason. A ratchet, like the banned-pattern counts: this list
     * may shrink, and anything added to it is a decision somebody has to write
     * down rather than a gap that appeared.
     */
    private const DELIBERATELY_ABSENT = [
        // Called only from the Projects module's own page, which imports
        // kickoffApi directly rather than through the engine proxy. Purchase
        // has no project link to list meetings for.
        'projectMeetings' => 'Projects-module only; Purchase meetings have no project link',
        // The shared engine's multi-subject meetings. Purchase meetings belong
        // to exactly one vendor.
        'subjects' => 'shared engine only — a Purchase meeting has one subject',
        // No caller in the shared meeting UI.
        'momData' => 'unused by the shared meeting screens',
    ];

    public function test_every_shared_meeting_method_reaches_the_purchase_engine(): void
    {
        $shared = $this->topLevelKeys('kickoffApi.js', 'kickoffApi');
        $adapter = $this->topLevelKeys('purchaseKickoffApi.js', 'purchaseKickoffApi');

        $missing = array_values(array_diff($shared, $adapter, array_keys(self::DELIBERATELY_ABSENT)));

        $this->assertSame([], $missing,
            "\n  These exist on the shared meeting client and NOT on the Purchase adapter.\n"
            ."  A user in /app/purchase will get \"api.<name> is not a function\":\n    "
            .implode("\n    ", $missing)
            ."\n  Add them to purchaseKickoffApi, or list them in DELIBERATELY_ABSENT with a reason.\n");
    }

    /** The exemption list may only shrink — an entry that no longer applies is noise. */
    public function test_the_exemption_list_is_not_stale(): void
    {
        $shared = $this->topLevelKeys('kickoffApi.js', 'kickoffApi');
        $adapter = $this->topLevelKeys('purchaseKickoffApi.js', 'purchaseKickoffApi');

        $stale = [];
        foreach (self::DELIBERATELY_ABSENT as $method => $why) {
            if (! in_array($method, $shared, true)) {
                $stale[] = "{$method} is no longer on the shared client — drop it from DELIBERATELY_ABSENT";
            } elseif (in_array($method, $adapter, true)) {
                $stale[] = "{$method} IS on the Purchase adapter now — drop it from DELIBERATELY_ABSENT";
            }
        }

        $this->assertSame([], $stale, "\n  ".implode("\n  ", $stale)."\n");
    }

    /**
     * The keys of one exported object literal.
     *
     * Deliberately only the TOP level: a nested group (agenda, registers) is a
     * whole object and its own shape, and this check is about the flat method
     * surface the meeting pages call through.
     */
    private function topLevelKeys(string $file, string $object): array
    {
        $path = self::SERVICES.'/'.$file;
        $this->assertFileExists($path, "{$file} has moved — this guard needs updating with it");

        $source = file_get_contents($path);
        $start = strpos($source, "export const {$object} = {");
        $this->assertNotFalse($start, "Could not find `export const {$object}` in {$file}");

        // The object literal ends at the first closing brace in column 0.
        $end = strpos($source, "\n}", $start);
        $this->assertNotFalse($end, "Could not find the end of {$object} in {$file}");

        preg_match_all('/^  ([A-Za-z_][\w]*)\s*:/m', substr($source, $start, $end - $start), $m);

        $keys = array_values(array_unique($m[1]));
        $this->assertNotEmpty($keys, "Parsed no methods out of {$object} — the guard has gone blind");

        return $keys;
    }
}
