<?php

namespace App\Domains\Fleet\Integration;

use RuntimeException;

/**
 * The trip-commitment reader could not determine whether an asset is on a trip
 * — the table is there but the read failed (D-204, P1's flag).
 *
 * Distinct from "no trip module installed", which is a real answer (nothing is
 * committed) and returns null. This is "could not tell", and a guard that could
 * not tell must refuse the destructive action rather than assume the asset is
 * free — retiring a truck that is on the road is the failure worth being
 * cautious about.
 */
class TripCommitmentUnavailable extends RuntimeException
{
}
