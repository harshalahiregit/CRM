<?php

namespace App\Services\Numbering;

use App\Models\Numbering\DocumentNumberConfig;
use Carbon\CarbonInterface;

/**
 * The single source of truth for document numbers.
 *
 * Every module MUST allocate through this contract — no module may implement its
 * own numbering. Database access lives entirely behind the implementation.
 *
 * SEQUENCE POLICY (deliberate, see DatabaseDocumentNumberService for rationale):
 *   • generate()/reserve() CONSUME a number; preview() never does.
 *   • Numbers are never reused and gaps are permitted.
 */
interface DocumentNumberServiceInterface
{
    /** Render the number that WOULD be issued next. Never consumes. */
    public function preview(int $tenantId, string $documentType, array $context = [], ?CarbonInterface $at = null): string;

    /** Atomically allocate and render the next number. Consumes. */
    public function generate(int $tenantId, string $documentType, array $context = [], ?CarbonInterface $at = null): string;

    /**
     * Allocate a number plus its metadata (sequence, period key) for callers that
     * need to record the allocation. Consumes.
     *
     * @return array{number:string, sequence:int, period_key:string, document_type:string}
     */
    public function reserve(int $tenantId, string $documentType, array $context = [], ?CarbonInterface $at = null): array;

    /** Open a new sequence period (non-destructive). Optionally change the start. */
    public function reset(int $tenantId, string $documentType, ?int $startingNumber = null): DocumentNumberConfig;

    /**
     * Validate a proposed configuration without saving it.
     *
     * @return array{valid:bool, errors:array<string,string>, preview:?string}
     */
    public function validate(array $config): array;

    /** The next sequence integer for a period, allocated atomically. */
    public function nextSequence(int $tenantId, string $documentType, string $periodKey, int $startingNumber = 1): int;
}
