<?php

namespace App\Providers;

use App\Support\Numbering\DocumentTypeRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the Sales module's document types with the central Document
 * Numbering Engine (owner: Harshal).
 *
 * Done here rather than by editing DocumentTypeRegistry::TYPES because that
 * class explicitly supports it: "Modules may also register types at runtime via
 * register() (e.g. from a service provider) without touching this file." Keeping
 * our types out of his file means neither side has to merge the other's edits.
 *
 * The engine's registry already ships `estimate` (EST, {PREFIX}-{YYYY}-{NEXT},
 * 3 digits, yearly) — byte-identical to what the Estimate model already emits —
 * so it is reused as-is rather than redeclared. Only the two types it lacks are
 * added, and both mirror the format the models produce today so switching the
 * engine on cannot change the shape of an existing workspace's references.
 *
 * Definition tuple: [label, module, format, prefix, minimum_digits, reset_rule]
 */
class SalesNumberingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // PROP-2026-038  (Proposal::booted())
        DocumentTypeRegistry::register('proposal', [
            'Proposal', 'Sales', '{PREFIX}-{YYYY}-{NEXT}', 'PROP', 3, 'yearly',
        ]);

        // PI-2026-001 — the proforma half of the Estimate model's EST-/PI- split.
        DocumentTypeRegistry::register('proforma_invoice', [
            'Proforma Invoice', 'Sales', '{PREFIX}-{YYYY}-{NEXT}', 'PI', 3, 'yearly',
        ]);
    }
}
