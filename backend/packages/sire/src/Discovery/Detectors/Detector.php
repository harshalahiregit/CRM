<?php

namespace Sire\Discovery\Detectors;

/**
 * SIRE — one area of the host to inspect.
 *
 * Every detector is READ-ONLY. It may read the schema, the container, config and
 * composer.json. It may not write a file, run a migration, dispatch an event,
 * flush a cache, or touch a row. Discovery runs against production databases;
 * it has to be the kind of thing you can run without asking.
 *
 * A detector that throws is caught and recorded as "detector failed" rather than
 * aborting the run. Discovery is a survey, and one unreadable area should not
 * cost the developer the other twelve.
 *
 * @return array<string, \Sire\Discovery\Finding> keyed by dotted path
 */
interface Detector
{
    /** Short name, used in output and error messages. */
    public function name(): string;

    /** @return array<string, \Sire\Discovery\Finding> */
    public function detect(): array;
}
