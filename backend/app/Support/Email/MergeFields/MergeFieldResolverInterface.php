<?php

namespace App\Support\Email\MergeFields;

use App\Support\Email\MergeContext;

/**
 * One merge field. Implement this (or use CallableMergeFieldResolver) and register
 * it with MergeFieldRegistry to add a field — the engine never changes.
 *
 * Resolvers return a RAW value; the registry escapes it when rendering to HTML.
 * A resolver must never return pre-escaped or trusted markup.
 */
interface MergeFieldResolverInterface
{
    /** The token WITHOUT braces, e.g. 'customer.name'. */
    public function token(): string;

    /** Grouping for the merge-field browser, e.g. 'Customer'. */
    public function group(): string;

    /** Human description shown in the UI. */
    public function description(): string;

    public function resolve(MergeContext $context): string;
}
