<?php

namespace App\Support\Numbering\Placeholders;

use App\Support\Numbering\NumberingContext;

/**
 * One placeholder token. Implement this (or use CallablePlaceholderResolver) and
 * register it with PlaceholderRegistry to add a token — the engine never changes.
 */
interface PlaceholderResolverInterface
{
    /** The bare token name, WITHOUT braces — e.g. 'YYYY'. */
    public function token(): string;

    /** Human description, surfaced by the API so the UI can document tokens. */
    public function description(): string;

    public function resolve(NumberingContext $context): string;
}
