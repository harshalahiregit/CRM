<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised by any AIProviderInterface implementation when a completion cannot be
 * produced (missing/invalid key, provider HTTP error, empty response). Callers
 * translate this into a clean user-facing error — never fake content.
 */
class AIException extends RuntimeException
{
}
