<?php

namespace Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

/**
 * Purchase forms must post `purchase_vendor_id`, never `vendor_id`.
 *
 * Purchase moved off the shared Vendor Master onto its own purchase_vendors
 * table; every FormRequest whitelists `purchase_vendor_id`, and the `vendor_id`
 * column no longer exists on the purchase tables at all. Five form pages were
 * still sending the old key, and because controllers persist
 * $request->validated() — which returns only keys that carry a rule — the key
 * was stripped before validation. The failure was split and both halves were
 * bad: contracts and quotations have a `required` rule so the form was
 * unsubmittable behind a bare "Validation failed", while orders, requests,
 * invoices and debit notes have `nullable`, so the record saved with a NULL
 * vendor and no error whatsoever. Reopening one for edit then read
 * `r.vendor_id` off a column that does not exist, so the picker was always
 * blank.
 *
 * Nothing about that is visible in a test run or a build — the payload is
 * well-formed JSON either way — so this reads the source instead.
 *
 * Only PAYLOAD keys are checked, and the pattern is deliberately narrow, because
 * three other uses of the name are correct and must not be flagged:
 *
 *  - local form state may still be called `vendor_id`;
 *  - report screens read a `vendor_id` the analytics endpoints really do return;
 *  - PurchaseWorkforce sends `?vendor_id=` as a QUERY PARAM, and
 *    PurchaseWorkforceAdminController::index reads exactly that name before
 *    mapping it onto the purchase_vendor_id column. Renaming that would break
 *    the filter rather than fix anything.
 */
class PurchaseVendorKeyTest extends TestCase
{
    /** Pages that build a payload containing a vendor. */
    private const FORM_PAGES = [
        'PurchaseContracts.jsx',
        'PurchaseOrders.jsx',
        'PurchaseRequests.jsx',
        'PurchaseInvoices.jsx',
        'PurchaseDebitNotes.jsx',
        'PurchaseRfqDetail.jsx',
    ];

    private function pagesDir(): string
    {
        return __DIR__.'/../../../../frontend/src/modules/purchase/pages';
    }

    public function test_no_purchase_form_posts_the_old_vendor_key(): void
    {
        $offenders = [];

        foreach (glob($this->pagesDir().'/*.jsx') as $path) {
            foreach (file($path) as $i => $line) {
                // A payload key: `vendor_id:` fed from the form object or the
                // picker's own state. Not `purchase_vendor_id`, and not a bare
                // `vendor_id` read off a report row.
                if (preg_match('/(?<![a-z_])vendor_id:\s*(f\.vendor_id|form\.vendor_id|Number\(\s*(f\.vendor_id|vendorId))/', $line)) {
                    $offenders[] = sprintf('%s:%d  %s', basename($path), $i + 1, trim($line));
                }
            }
        }

        $this->assertSame([], $offenders, sprintf(
            "These send `vendor_id`, which every Purchase FormRequest strips because it\n".
            "whitelists `purchase_vendor_id`. The column `vendor_id` no longer exists on\n".
            "the purchase tables either. Consequence depends on the rule: `required`\n".
            "gives a bare \"Validation failed\", `nullable` SAVES A RECORD WITH NO VENDOR\n".
            "and reports success.\n\nRename the payload key (local form state can keep its own name):\n\n  %s\n",
            implode("\n  ", $offenders)
        ));
    }

    public function test_every_vendor_form_page_still_sends_a_vendor(): void
    {
        // The inverse guard: "fixed" by deleting the key would pass the test
        // above while quietly dropping the vendor for good.
        $missing = [];

        foreach (self::FORM_PAGES as $page) {
            $path = $this->pagesDir().'/'.$page;
            if (! is_file($path)) {
                $missing[] = $page.' (file not found)';
                continue;
            }
            if (! str_contains(file_get_contents($path), 'purchase_vendor_id')) {
                $missing[] = $page.' sends no purchase_vendor_id at all';
            }
        }

        $this->assertSame([], $missing, sprintf(
            "These pages build a vendor-bearing payload but never mention\n".
            "purchase_vendor_id, so the vendor is not being sent:\n\n  %s\n",
            implode("\n  ", $missing)
        ));
    }

    public function test_no_purchase_form_reads_back_the_dropped_column(): void
    {
        $offenders = [];

        foreach (self::FORM_PAGES as $page) {
            $path = $this->pagesDir().'/'.$page;
            if (! is_file($path)) {
                continue;
            }
            foreach (file($path) as $i => $line) {
                // Repopulating an edit form from the server row. `r.vendor_id`
                // is always undefined now, so the picker silently resets.
                if (preg_match('/(?<![a-z_])vendor_id:\s*[a-z]\.vendor_id(?!\w)/', $line)) {
                    $offenders[] = sprintf('%s:%d  %s', basename($path), $i + 1, trim($line));
                }
            }
        }

        $this->assertSame([], $offenders, sprintf(
            "These read `vendor_id` off a purchase record. That column was dropped, so\n".
            "the value is always undefined and reopening the form shows an empty vendor\n".
            "picker. Read `purchase_vendor_id` instead:\n\n  %s\n",
            implode("\n  ", $offenders)
        ));
    }
}
