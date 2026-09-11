<?php

namespace Tests\Feature\Shared;

use App\Models\Purchase\PurchaseDocument;
use App\Models\Vendor\VendorDocument;
use App\Services\Purchase\PurchaseDocumentService;
use App\Services\Vendor\VendorDocumentService;
use Tests\TestCase;

/**
 * The document catalogs the two vendor screens render must name types the two
 * backends actually accept.
 *
 * `documentCatalog.js` supplies the label, the category and the required
 * default for every row of the Documents tab — on the admin workspace and in
 * the vendor's own portal, for both engines. It is a frontend file listing
 * backend type keys, which is exactly the kind of pairing that rots quietly.
 *
 * It had rotted. The TPV catalog listed `company_pan`, `pf_no`, `esic_no`,
 * `bocw_registration`, `udyam_certificate`, `other` and `subcontractor_decl`.
 * The TPV backend has never accepted any of the seven — `allowedTypes()`
 * rejects each with "Unknown document type" — while the five requirements it
 * does issue (pan, pf, esic, bocw, udyam) were types the catalog had never
 * heard of, so they were appended unlabelled under "Other Documents". A
 * standard TPV vendor saw sixteen rows for eleven documents, seven of them
 * dead on upload.
 *
 * Nothing caught it because nothing compared the two sides. This does.
 */
class DocumentCatalogMatchesTheEnginesTest extends TestCase
{
    private function catalog(string $export): array
    {
        $path = base_path('../frontend/src/components/vendor/documentCatalog.js');
        $this->assertFileExists($path, 'documentCatalog.js has moved — this guard needs repointing');

        $src = (string) file_get_contents($path);

        $start = strpos($src, "export const {$export} = [");
        $this->assertNotFalse($start, "{$export} is gone from documentCatalog.js");

        $end = strpos($src, "\n]", $start);
        preg_match_all("/type:\s*'([^']+)'/", substr($src, $start, $end - $start), $m);

        $this->assertNotEmpty($m[1], "{$export} lists no types");

        return $m[1];
    }

    public function test_every_tpv_catalog_type_is_one_the_tpv_backend_accepts(): void
    {
        $allowed = VendorDocumentService::allowedTypes();

        foreach ($this->catalog('TPV_DOC_CATALOG') as $type) {
            $this->assertContains($type, $allowed,
                "TPV_DOC_CATALOG offers '{$type}', which VendorDocumentService::allowedTypes() rejects — "
                .'that row can never be filled: uploading against it throws "Unknown document type"');
        }
    }

    public function test_every_purchase_catalog_type_is_one_the_purchase_backend_accepts(): void
    {
        $allowed = PurchaseDocumentService::allowedTypes();

        foreach ($this->catalog('PURCHASE_DOC_CATALOG') as $type) {
            $this->assertContains($type, $allowed,
                "PURCHASE_DOC_CATALOG offers '{$type}', which PurchaseDocumentService::allowedTypes() rejects");
        }
    }

    /**
     * The other direction: a requirement with no catalog row arrives as a type
     * the panel has never seen and lands in "Other Documents", away from the
     * section it belongs to.
     */
    public function test_the_catalogs_cover_every_type_each_engine_can_require(): void
    {
        $cases = [
            'TPV' => [
                $this->catalog('TPV_DOC_CATALOG'),
                array_unique([...VendorDocument::STANDARD_SET, ...VendorDocument::TEMPORARY_SET]),
            ],
            'Purchase' => [
                $this->catalog('PURCHASE_DOC_CATALOG'),
                array_unique([...PurchaseDocument::STANDARD_SET, ...PurchaseDocument::TEMPORARY_SET]),
            ],
        ];

        foreach ($cases as $engine => [$catalog, $required]) {
            $missing = array_values(array_diff($required, $catalog));

            $this->assertSame([], $missing,
                "the {$engine} catalog has no row for ".implode(', ', $missing)
                .' — those requirements render unlabelled under Other Documents');
        }
    }

    /**
     * A document the vendor supplied must render as supplied.
     *
     * The checklist answers in two buckets and both carry real uploads:
     * `required` is what this vendor was asked for, `extras` is everything else
     * they actually sent. The panel merged `extras` only when the catalog had
     * never heard of the type and dropped it otherwise — and since every type a
     * vendor can upload is one the engine knows, and therefore one the catalog
     * lists, "otherwise" was the normal case. PV-0002's LOI/WO/PO was uploaded
     * through the portal and approved by an admin, and both the admin tab and
     * the vendor's own portal drew it as "Not Uploaded".
     */
    public function test_the_panel_merges_extras_into_the_catalog_rows(): void
    {
        $path = base_path('../frontend/src/components/vendor/VendorDocumentsPanel.jsx');
        $this->assertFileExists($path);

        $src = (string) file_get_contents($path);

        $start = strpos($src, 'const rows = useMemo(');
        $this->assertNotFalse($start, 'the row merge has moved — this guard needs repointing');

        $body = substr($src, $start, 2400);

        $this->assertStringContainsString('extraByType', $body,
            'VendorDocumentsPanel no longer indexes checklist.extras by type, so a document the vendor '
            .'uploaded outside their required set renders as "Not Uploaded"');
        $this->assertStringContainsString('extraByType.get(def.type)', $body,
            'a catalog row no longer falls back to the extras bucket — an uploaded optional document '
            .'will show as missing');
    }
}
