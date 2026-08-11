<?php

namespace App\Services\Sales;

use App\Exceptions\BusinessException;
use App\Exceptions\UnauthorizedTenantException;
use App\Models\Sales\Estimate;
use App\Models\Sales\SalesDocumentTemplate;
use App\Models\Sales\Proposal;
use App\Models\Sales\SalesInvoice;
use App\Models\Sales\SalesLineItem;
use App\Support\HtmlSanitizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Templates for the documents that have a line-items grid: invoices, estimates
 * and proposals. Credit notes are excluded — that form has no grid, it derives a
 * single line from a reason and an amount, so there is nothing to template.
 *
 * Everything is tenant-scoped the same way the rest of Sales is: reads go through
 * `forTenant()`, and every write path asserts the record belongs to the caller's
 * tenant before touching it — a template is copied into real financial documents,
 * so a leak here would be a leak of pricing.
 */
class SalesDocumentTemplateService
{
    /** Document model for each type, used by save-from-document. */
    private const MODELS = [
        'invoice'     => SalesInvoice::class,
        'estimate'    => Estimate::class,
        // Proposal templates carry content + terms but never pricing, so a
        // proposal's line items are templated here instead.
        'proposal'    => Proposal::class,
    ];

    public function list(int $tenantId, ?string $docType = null)
    {
        return SalesDocumentTemplate::forTenant($tenantId)
            ->ofType($docType)
            ->with('lineItems')
            ->withCount('lineItems as items_count')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    public function show(SalesDocumentTemplate $template, int $tenantId): SalesDocumentTemplate
    {
        $this->assertTenant($template, $tenantId);

        return $template->load('lineItems');
    }

    public function create(array $data, int $tenantId, int $userId): SalesDocumentTemplate
    {
        return DB::transaction(function () use ($data, $tenantId, $userId) {
            $items = $data['line_items'] ?? [];
            unset($data['line_items']);

            $data = HtmlSanitizer::cleanFields($data, ['terms', 'adminnote', 'clientnote']);

            $template = SalesDocumentTemplate::create([
                ...$data,
                'tenant_id'  => $tenantId,
                'created_by' => $userId,
            ]);

            $this->syncLineItems($template, $items);

            Log::channel('sales')->info('Sales document template created', [
                'template_id' => $template->id, 'doc_type' => $template->doc_type, 'tenant_id' => $tenantId,
            ]);

            return $template->load('lineItems');
        });
    }

    public function update(SalesDocumentTemplate $template, array $data, int $tenantId): SalesDocumentTemplate
    {
        $this->assertTenant($template, $tenantId);

        return DB::transaction(function () use ($template, $data, $tenantId) {
            // Distinguish "no line_items key" (leave them alone) from an explicit
            // empty array (the user removed every row).
            $hasItems = array_key_exists('line_items', $data);
            $items = $data['line_items'] ?? [];
            unset($data['line_items']);

            // doc_type is what a template IS; changing it would silently move a
            // template between pickers and invalidate its unique name slot.
            unset($data['doc_type']);

            $data = HtmlSanitizer::cleanFields($data, ['terms', 'adminnote', 'clientnote']);
            $template->update($data);

            if ($hasItems) {
                $this->syncLineItems($template, $items);
            }

            Log::channel('sales')->info('Sales document template updated', [
                'template_id' => $template->id, 'tenant_id' => $tenantId,
            ]);

            return $template->fresh()->load('lineItems');
        });
    }

    public function delete(SalesDocumentTemplate $template, int $tenantId): void
    {
        $this->assertTenant($template, $tenantId);

        DB::transaction(function () use ($template) {
            // Soft-deleting the template would strand its line items as live rows
            // pointing at a hidden parent, so they go with it.
            $template->lineItems()->delete();
            $template->delete();
        });

        Log::channel('sales')->info('Sales document template deleted', [
            'template_id' => $template->id, 'tenant_id' => $tenantId,
        ]);
    }

    public function duplicate(SalesDocumentTemplate $template, int $tenantId, int $userId): SalesDocumentTemplate
    {
        $this->assertTenant($template, $tenantId);

        return DB::transaction(function () use ($template, $tenantId, $userId) {
            $copy = SalesDocumentTemplate::create([
                'tenant_id'      => $tenantId,
                'created_by'     => $userId,
                'doc_type'       => $template->doc_type,
                'name'           => $this->uniqueName($template->name, $template->doc_type, $tenantId),
                'description'    => $template->description,
                'terms'          => $template->terms,
                'adminnote'      => $template->adminnote,
                'clientnote'     => $template->clientnote,
                'currency'       => $template->currency,
                'discount_type'  => $template->discount_type,
                'discount_mode'  => $template->discount_mode,
                'discount_value' => $template->discount_value,
                'sort_order'     => $template->sort_order,
            ]);

            $this->syncLineItems($copy, $template->lineItems->map(fn ($i) => [
                'item_id'       => $i->item_id,
                'item_name'     => $i->item_name,
                'description'   => $i->description,
                'hsn_sac_code'  => $i->hsn_sac_code,
                'qty'           => $i->qty,
                'unit'          => $i->unit,
                'rate'          => $i->rate,
                'tax'           => $i->tax,
                'taxes'         => $i->taxes,
                'discount'      => $i->discount,
                'discount_mode' => $i->discount_mode,
            ])->all());

            return $copy->load('lineItems');
        });
    }

    /**
     * Turn an existing invoice / estimate / proposal into a template.
     *
     * The common way a template gets made: someone builds the document once, then
     * keeps it. Only reusable parts are copied — never the client, dates, numbers
     * or payment state.
     */
    public function saveFromDocument(string $docType, int $documentId, array $data, int $tenantId, int $userId): SalesDocumentTemplate
    {
        $model = self::MODELS[$docType] ?? null;
        if (! $model) {
            throw new BusinessException("Unknown document type \"{$docType}\".");
        }

        $doc = $model::find($documentId);
        if (! $doc) {
            throw new BusinessException('That document no longer exists.');
        }
        $this->assertTenant($doc, $tenantId);

        $items = SalesLineItem::where('lineable_type', $model)
            ->where('lineable_id', $doc->id)
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($i) => [
                'item_id'       => $i->item_id,
                'item_name'     => $i->item_name,
                'description'   => $i->description,
                'hsn_sac_code'  => $i->hsn_sac_code,
                'qty'           => $i->qty,
                'unit'          => $i->unit,
                'rate'          => $i->rate,
                'tax'           => $i->tax,
                'taxes'         => $i->taxes,
                'discount'      => $i->discount,
                'discount_mode' => $i->discount_mode,
            ])->all();

        return $this->create([
            'doc_type'       => $docType,
            'name'           => $this->uniqueName($data['name'] ?? ($doc->number ?? 'Template'), $docType, $tenantId),
            'description'    => $data['description'] ?? null,
            'terms'          => $doc->terms ?? null,
            'adminnote'      => $doc->adminnote ?? null,
            'clientnote'     => $doc->clientnote ?? null,
            'currency'       => $doc->currency ?? null,
            'discount_type'  => $doc->discount_type ?? null,
            'discount_mode'  => $doc->discount_mode ?? null,
            'discount_value' => $doc->discount_value ?? 0,
            'line_items'     => $items,
        ], $tenantId, $userId);
    }

    /**
     * Replace a template's line items.
     *
     * Mirrors the documents' own syncLineItems, including the prelude that
     * normalizes the tax shape and resolves a % discount to an amount — a template
     * row has to be identical to a document row, or applying it would change the
     * numbers.
     */
    private function syncLineItems(SalesDocumentTemplate $template, array $items): void
    {
        SalesLineItem::where('lineable_type', SalesDocumentTemplate::class)
                     ->where('lineable_id', $template->id)
                     ->delete();

        foreach ($items as $idx => $item) {
            if (empty($item['item_name'])) {
                continue; // blank trailing row from the form grid
            }

            $taxInfo = SalesLineItem::normalizeTaxes($item);
            $item['tax'] = $taxInfo['tax'];
            $item['qty'] = $item['qty'] ?? 1;
            $item['rate'] = $item['rate'] ?? 0;
            $item['discount'] = SalesLineItem::discountAmount($item);

            SalesLineItem::create([
                'lineable_type' => SalesDocumentTemplate::class,
                'lineable_id'   => $template->id,
                'item_id'       => $item['item_id'] ?? null,
                'item_name'     => $item['item_name'],
                'description'   => $item['description'] ?? null,
                'hsn_sac_code'  => $item['hsn_sac_code'] ?? null,
                'qty'           => $item['qty'],
                'unit'          => $item['unit'] ?? 'pcs',
                'rate'          => $item['rate'],
                'tax'           => $taxInfo['tax'],
                'taxes'         => $taxInfo['taxes'],
                'discount'      => $item['discount'],
                'discount_mode' => $item['discount_mode'] ?? 'fixed',
                'total'         => SalesLineItem::computeTotal($item),
                'sort_order'    => $idx,
            ]);
        }
    }

    /** "Standard" → "Standard (copy)" → "(copy 2)", unique per tenant AND type. */
    private function uniqueName(string $name, string $docType, int $tenantId): string
    {
        $exists = fn ($n) => SalesDocumentTemplate::forTenant($tenantId)
            ->where('doc_type', $docType)->where('name', $n)->exists();

        if (! $exists($name)) {
            return $name;
        }

        $base = preg_replace('/ \(copy(?: \d+)?\)$/', '', $name);
        $candidate = "{$base} (copy)";
        for ($i = 2; $exists($candidate); $i++) {
            $candidate = "{$base} (copy {$i})";
        }

        return $candidate;
    }

    private function assertTenant(Model $record, int $tenantId): void
    {
        if ((int) $record->tenant_id !== $tenantId) {
            throw new UnauthorizedTenantException();
        }
    }
}
