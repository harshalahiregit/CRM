<?php

namespace App\Services\SangoeTrack;

use RuntimeException;

/**
 * Any failure talking to SangoeTrack. Distinct from BusinessException so the
 * sync can tell "the remote is unreachable / misconfigured" (abort the run)
 * apart from "this one employee is ineligible" (skip and carry on).
 */
class SangoeTrackException extends RuntimeException
{
}
