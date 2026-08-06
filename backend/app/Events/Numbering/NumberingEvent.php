<?php

namespace App\Events\Numbering;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Base for the engine's extension points. Listeners can observe (and, for the
 * "before" events, inspect) every allocation and reset without the engine
 * depending on any module.
 *
 * Concrete events: BeforeGenerate, AfterGenerate, BeforeReset, AfterReset.
 */
abstract class NumberingEvent
{
    use Dispatchable;

    public function __construct(
        public readonly int $tenantId,
        public readonly string $documentType,
        public readonly array $payload = [],
    ) {
    }
}
