<?php

namespace App\Support\Sales;

use App\Services\Numbering\DocumentNumberServiceInterface;
use Illuminate\Support\Facades\Log;

/**
 * Bridge between the Sales documents and the central Document Numbering Engine
 * (owner: Harshal).
 *
 * The engine is deliberately OPT-IN — DocumentTypeRegistry::defaults() ships
 * `enabled => false` with the note "modules keep their own numbering until
 * switched on". So this helper asks the engine first and falls back to the
 * module's own allocator when the type is switched off (or the engine is
 * unavailable for any reason). Consequences:
 *
 *   • Nothing changes for an existing workspace until an admin enables the type
 *     in Settings -> Document Numbering, so this is a no-op deployment.
 *   • Once enabled, the admin's configured format/prefix/reset rule takes effect
 *     and allocation becomes atomic (row lock + retry) instead of count-based.
 *   • The fallback is never allowed to break a save: any engine error is logged
 *     and the local allocator still returns a number.
 */
class DocumentNumber
{
    /**
     * @param  callable():string $fallback  the module's own allocator
     */
    public static function allocate(string $documentType, int $tenantId, callable $fallback): string
    {
        try {
            return app(DocumentNumberServiceInterface::class)->generate($tenantId, $documentType);
        } catch (\Throwable $e) {
            // Expected and silent for the common case: numbering for this type is
            // simply not enabled yet. Anything else is worth a breadcrumb, but
            // still must not stop the document being created.
            if (! str_contains($e->getMessage(), 'not enabled')) {
                Log::channel('sales')->warning('Document numbering engine unavailable, used local allocator', [
                    'document_type' => $documentType,
                    'tenant_id'     => $tenantId,
                    'error'         => $e->getMessage(),
                ]);
            }

            return $fallback();
        }
    }
}
