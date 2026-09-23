<?php

namespace Tests\Feature\Purchase;

use App\Models\Numbering\DocumentNumberConfig;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Tenant;
use App\Services\Purchase\PurchaseVendorService;
use App\Support\Numbering\DocumentTypeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The vendor code follows the numbering settings, because there were some.
 *
 * `purchase_vendor` has been a configurable type in Settings → Numbering all
 * along — prefix, padding, date parts, reset rule. The generator hard-coded
 * 'PV-0001' and read none of it, so the honest answer to "how do I set the
 * vendor code number?" was: you can, the screen saves it, and nothing reads it.
 *
 * The other half matters just as much. Numbering is opt-in and most workspaces
 * have never turned this type on — they must keep getting PV-0001 rather than a
 * failure to create a vendor.
 */
class VendorCodeFollowsNumberingTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private PurchaseVendorService $service;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();

        $this->service = app(PurchaseVendorService::class);
    }

    private function configure(array $overrides = []): void
    {
        DocumentNumberConfig::updateOrCreate(
            ['tenant_id' => self::TENANT, 'document_type' => 'purchase_vendor'],
            array_merge([
                // minimum_digits is the width — `padding` exists on the model
                // too but is not what the formatter reads.
                'enabled' => true, 'format' => '{PREFIX}-{NEXT}', 'prefix' => 'VEND',
                'minimum_digits' => 4, 'reset_rule' => 'never', 'starting_number' => 1,
            ], $overrides),
        );
    }

    public function test_the_type_is_in_the_registry_so_the_settings_screen_offers_it(): void
    {
        // If this ever disappears the settings screen silently loses the field
        // and the code below quietly falls back forever.
        $this->assertArrayHasKey('purchase_vendor', DocumentTypeRegistry::all());
    }

    public function test_a_configured_format_is_what_the_vendor_gets(): void
    {
        $this->configure(['prefix' => 'SUPP', 'minimum_digits' => 5]);

        $code = $this->service->nextVendorCode(self::TENANT);

        // Both halves of the setting reach the code: the prefix that was typed,
        // and the width it was asked to pad to.
        $this->assertMatchesRegularExpression('/^SUPP-\d{5,}$/', $code);
    }

    public function test_two_vendors_never_get_the_same_code(): void
    {
        $this->configure();

        $first = $this->service->nextVendorCode(self::TENANT);
        $second = $this->service->nextVendorCode(self::TENANT);

        $this->assertNotSame($first, $second);
    }

    public function test_a_code_already_taken_is_skipped_rather_than_reused(): void
    {
        $this->configure(['starting_number' => 1]);

        // A soft-deleted vendor still occupies its code and the column is
        // unique, so the generated number is checked, not trusted.
        PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Old',
            'purchase_vendor_code' => 'VEND-0001',
            'email' => 'old@t.local', 'status' => 'Active',
        ])->delete();

        $code = $this->service->nextVendorCode(self::TENANT);

        $this->assertNotSame('VEND-0001', $code);
    }

    /* ── the workspaces that never configured anything ──────────── */

    public function test_an_unconfigured_workspace_still_gets_a_code(): void
    {
        // Numbering is opt-in. generate() throws for a disabled type, and left
        // unhandled that turns "create a vendor" into a 422 for every workspace
        // that never visited the settings screen.
        $code = $this->service->nextVendorCode(self::TENANT);

        $this->assertSame('PV-0001', $code);
    }

    public function test_the_fallback_counts_past_the_vendors_already_there(): void
    {
        foreach (['PV-0001', 'PV-0002'] as $i => $taken) {
            PurchaseVendor::create([
                'tenant_id' => self::TENANT, 'company_name' => "V{$i}",
                'purchase_vendor_code' => $taken,
                'email' => "v{$i}@t.local", 'status' => 'Active',
            ]);
        }

        $this->assertSame('PV-0003', $this->service->nextVendorCode(self::TENANT));
    }

    public function test_turning_numbering_on_changes_what_new_vendors_get(): void
    {
        $this->assertSame('PV-0001', $this->service->nextVendorCode(self::TENANT));

        $this->configure(['prefix' => 'ACME']);

        // The whole point of the issue: the setting now has an effect.
        $this->assertStringStartsWith('ACME-', $this->service->nextVendorCode(self::TENANT));
    }

    public function test_each_tenant_numbers_independently(): void
    {
        (new Tenant())->forceFill([
            'id' => 2, 'name' => 'T2', 'slug' => 't2', 'subdomain' => 't2', 'status' => 'active',
        ])->save();

        $this->configure(['prefix' => 'ONE']);

        $this->assertStringStartsWith('ONE-', $this->service->nextVendorCode(self::TENANT));
        // Tenant 2 configured nothing, so it keeps the default scheme.
        $this->assertSame('PV-0001', $this->service->nextVendorCode(2));
    }
}
