<?php

namespace App\Support\Transport;

use App\Services\Numbering\DocumentNumberServiceInterface;
use Illuminate\Support\Facades\Log;

/**
 * Bridge between Transport documents and the central Document Numbering Engine.
 *
 * The engine is deliberately OPT-IN per tenant — DatabaseDocumentNumberService:
 * "A document type is only served once a config row exists AND is enabled. Until
 * then isEnabled() returns false and the owning module keeps its existing
 * numbering." So a workspace that has not switched Transport numbering on in
 * Settings would otherwise be unable to create an order at all.
 *
 * Same shape as App\Support\Sales\DocumentNumber, deliberately not reused from
 * it: that class logs to the `sales` channel, and a Transport numbering problem
 * appearing in Sales' log is exactly the kind of misfiling that makes a channel
 * per module pointless. The allocation itself still goes through the engine
 * whenever it is enabled — this is a fallback, not a second implementation.
 */
class TransportDocumentNumber
{
    /**
     * @param  callable():string  $fallback  the module's own allocator
     */
    public static function allocate(string $documentType, int $tenantId, callable $fallback): string
    {
        try {
            return app(DocumentNumberServiceInterface::class)->generate($tenantId, $documentType);
        } catch (\Throwable $e) {
            // Expected and silent for the common case: the type is simply not
            // switched on yet. Anything else is worth a breadcrumb, but must
            // never stop the document being created.
            if (! str_contains($e->getMessage(), 'not enabled')) {
                Log::channel('transport')->warning('Document numbering engine unavailable, used local allocator', [
                    'document_type' => $documentType,
                    'tenant_id'     => $tenantId,
                    'error'         => $e->getMessage(),
                ]);
            }

            return $fallback();
        }
    }
}
