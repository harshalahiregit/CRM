<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * A counter that never repeats inside one test run — D-54.
     *
     * Fixtures used to draw identifiers randomly: `random_int(1000, 9999)`
     * against `UNIQUE(tenant_id, registration_normalized)` is 9,000 possible
     * values, so two vehicles built in the same test method could collide and
     * the suite failed roughly once in four full runs.
     *
     * Widening the range would have made that rarer, not impossible, and a
     * rare failure is worse than a frequent one because it gets re-run instead
     * of fixed. This counter cannot collide: it is monotonic for the life of
     * the process, and Laravel's parallel testing gives each process its own
     * database, so processes never share a key space.
     *
     * `$width` keeps the generated value the same shape and length as the
     * random draw it replaced — 4 digits for a registration, 6 for a licence —
     * so no test sees an identifier of a different form than before.
     */
    protected static function uniqueSeq(int $width = 4): string
    {
        static $n = 0;

        return str_pad((string) ++$n, $width, '0', STR_PAD_LEFT);
    }
}
