<?php

namespace Tests\Feature\Shared;

use App\Models\Purchase\PurchaseDocument;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Vendor\Vendor;
use App\Models\Vendor\VendorDocument;
use App\Services\Purchase\PurchaseDocumentService;
use App\Services\Vendor\VendorDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Promotion from temporary to permanent silently raises the paperwork.
 *
 * The required set is derived from `vendor_type`, and converting a vendor
 * rewrites it: three documents become eleven. The vendor submitted, passed and
 * was activated against the temporary set — nothing is wrong with their file —
 * but the checklist drops from complete to 18% overnight, and until now nothing
 * anywhere said why.
 *
 * PV-0002 is the worked example. Registered temporary on 2 Sep, filed exactly
 * the temporary set (insurance, GST, LOI) on 7 Sep, approved at 3 of 3, then
 * converted on 11 Sep. The panel read "2 of 11 mandatory documents approved
 * (18%)" — having also stopped counting the LOI, which the permanent set does
 * not ask for — beside a table showing three green Approved rows. Nobody told
 * the vendor they now owed nine more, so nobody uploaded them, and an Active
 * vendor sat at 18% compliance indefinitely.
 *
 * `conversionContext()` is what the checklist and the conversion e-mail both
 * read to say so.
 */
class ConversionRaisesTheDocumentBarTest extends TestCase
{
    use RefreshDatabase;

    /** The eight types a temporary vendor is never asked for. */
    private function raisedBy(array $standard, array $temporary): array
    {
        return array_values(array_diff($standard, $temporary));
    }

    public function test_promotion_really_does_raise_the_bar_on_both_engines(): void
    {
        $this->assertGreaterThan(
            count(PurchaseDocument::TEMPORARY_SET),
            count(PurchaseDocument::STANDARD_SET),
            'if these ever match, this whole class of surprise is gone and this guard can go',
        );

        $this->assertSame(
            $this->raisedBy(VendorDocument::STANDARD_SET, VendorDocument::TEMPORARY_SET),
            $this->raisedBy(PurchaseDocument::STANDARD_SET, PurchaseDocument::TEMPORARY_SET),
            'the two engines raise the bar by different documents — a vendor working under both '
            .'would owe different paperwork for the same promotion',
        );
    }

    public function test_a_vendor_who_was_never_converted_gets_no_conversion_notice(): void
    {
        $this->assertSame([], PurchaseDocumentService::conversionContext(
            null, PurchaseDocument::STANDARD_SET, [],
        ), 'a vendor who was always permanent has nothing to explain');

        $this->assertSame([], VendorDocumentService::conversionContext(
            null, VendorDocument::STANDARD_SET, [],
        ));
    }

    /**
     * The exact PV-0002 case: converted, holding the three temporary documents,
     * owing the nine the permanent set adds.
     */
    public function test_it_names_what_promotion_newly_demands(): void
    {
        $held = PurchaseDocument::TEMPORARY_SET;   // insurance_wcp, gst, loi_wo_po

        $ctx = PurchaseDocumentService::conversionContext(
            now(), PurchaseDocument::STANDARD_SET, $held,
        );

        $this->assertArrayHasKey('converted_at', $ctx);
        $this->assertNotEmpty($ctx['newly_required'],
            'a promoted vendor holding only the temporary set owes the difference — saying nothing '
            .'leaves them non-compliant on documents nobody asked for');

        // Only what promotion added, and only what is still outstanding.
        foreach ($ctx['newly_required'] as $type) {
            $this->assertNotContains($type, PurchaseDocument::TEMPORARY_SET,
                "{$type} was already required of a temporary vendor — promotion did not add it");
            $this->assertNotContains($type, $held,
                "{$type} is already on file and must not be asked for again");
            $this->assertContains($type, PurchaseDocument::STANDARD_SET);
        }
    }

    public function test_a_promoted_vendor_who_filed_everything_is_asked_for_nothing(): void
    {
        $ctx = PurchaseDocumentService::conversionContext(
            now(), PurchaseDocument::STANDARD_SET, PurchaseDocument::STANDARD_SET,
        );

        $this->assertSame([], $ctx['newly_required'],
            'a vendor holding the full permanent set is complete — promotion asks them for nothing');
    }

    /** The checklist endpoint carries it, so the panel can draw the explanation. */
    public function test_the_purchase_checklist_carries_the_conversion_context(): void
    {
        $vendor = PurchaseVendor::create([
            'tenant_id'                 => 1,
            'purchase_vendor_code'      => 'PV-9001',
            'company_name'              => 'Promoted Supplies',
            'email'                     => 'promoted@example.test',
            'status'                    => 'Active',
            'vendor_type'               => 'standard',
            'converted_to_permanent_at' => now(),
        ]);

        $checklist = app(PurchaseDocumentService::class)->checklist($vendor);

        $this->assertArrayHasKey('converted_at', $checklist,
            'without this the panel cannot explain why a complete vendor now reads 18%');
        $this->assertSame(
            $this->raisedBy(PurchaseDocument::STANDARD_SET, PurchaseDocument::TEMPORARY_SET),
            $checklist['newly_required'],
            'a converted vendor holding nothing owes every document promotion added',
        );
    }

    public function test_the_tpv_checklist_carries_it_too(): void
    {
        $vendor = Vendor::create([
            'tenant_id'                 => 1,
            'company_name'              => 'Promoted Contractors',
            'status'                    => 'Active',
            'vendor_type'               => 'standard',
            'converted_to_permanent_at' => now(),
        ]);

        $checklist = app(VendorDocumentService::class)->checklist($vendor);

        $this->assertArrayHasKey('converted_at', $checklist);
        $this->assertSame(
            $this->raisedBy(VendorDocument::STANDARD_SET, VendorDocument::TEMPORARY_SET),
            $checklist['newly_required'],
        );
    }

    /**
     * The vendor learns from the e-mail or not at all — the portal has no other
     * announcement, and the checklist only shows a number that went down.
     */
    public function test_both_conversion_notices_name_the_new_documents(): void
    {
        $purchase = (string) file_get_contents(
            app_path('Services/Purchase/PurchaseActivationNotifier.php'),
        );
        $this->assertStringContainsString('conversionContext', $purchase,
            'the Purchase conversion e-mail no longer lists what promotion newly requires');

        $blade = (string) file_get_contents(
            resource_path('views/emails/purchase/converted_to_permanent.blade.php'),
        );
        $this->assertStringContainsString('newlyRequired', $blade,
            'the conversion e-mail template dropped the outstanding-documents list');

        $tpv = (string) file_get_contents(app_path('Services/Tpv/TpvAccessService.php'));
        $this->assertStringContainsString('conversionContext', $tpv,
            'the TPV conversion notice no longer lists what promotion newly requires');
    }
}
