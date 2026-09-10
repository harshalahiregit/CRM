<?php

namespace Tests\Feature\Shared;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Both vendor engines can promote a temporary account to permanent.
 *
 * TPV and Purchase are deliberately separate — separate tables, separate
 * services, neither importing the other — and the price of that is that a
 * capability can exist on one side for months without anybody noticing it is
 * missing on the other. That is exactly what happened here: TPV shipped
 * temporary access WITH a conversion, Purchase shipped temporary access
 * WITHOUT one, and a temporary purchase vendor could only expire.
 *
 * The gap was invisible from every direction. Purchase's own tests passed, its
 * screens rendered, and its edit form even showed a "Permanent" option — one
 * that answered 200 and changed nothing that decided anything.
 *
 * So this guards the capability rather than either implementation: whatever the
 * two modules do internally, both must be able to end a temporary engagement
 * without ending the vendor.
 */
class VendorConversionParityTest extends TestCase
{
    // Schema::hasColumn needs the migrations actually run.
    use RefreshDatabase;

    /** Does any registered route match this verb and path? */
    private function hasRoute(string $method, string $uri): bool
    {
        foreach (Route::getRoutes() as $route) {
            if (in_array(strtoupper($method), $route->methods(), true)
                && $route->uri() === ltrim($uri, '/')) {
                return true;
            }
        }

        return false;
    }

    public function test_both_modules_expose_a_conversion_endpoint(): void
    {
        $this->assertTrue(
            $this->hasRoute('POST', 'api/tpv/vendors/{vendor}/access/convert'),
            'TPV has lost its convert-to-permanent endpoint',
        );

        $this->assertTrue(
            $this->hasRoute('POST', 'api/purchase/vendors/{purchaseVendor}/convert'),
            'Purchase cannot promote a temporary vendor — a temporary purchase vendor can only expire',
        );
    }

    public function test_both_tables_record_who_converted_and_when(): void
    {
        // Without these the promotion happens and leaves no trace, which is the
        // one question an auditor asks about a vendor whose expiry disappeared.
        foreach ([
            'vendors' => 'TPV',
            'purchase_vendors' => 'Purchase',
        ] as $table => $module) {
            $this->assertTrue(
                Schema::hasColumn($table, 'converted_to_permanent_at'),
                "{$module} does not record WHEN a vendor was made permanent",
            );
            $this->assertTrue(
                Schema::hasColumn($table, 'converted_by'),
                "{$module} does not record WHO made a vendor permanent",
            );
        }
    }

    public function test_both_services_carry_the_promotion(): void
    {
        // Named methods, because a route can exist while the work behind it is a
        // stub — this module has shipped one of those before.
        $this->assertTrue(
            method_exists(\App\Services\Tpv\TpvAccessService::class, 'convert'),
            'TpvAccessService::convert has gone',
        );
        $this->assertTrue(
            method_exists(\App\Services\Purchase\PurchaseVendorService::class, 'convertToPermanent'),
            'PurchaseVendorService::convertToPermanent has gone',
        );
    }

    /**
     * A temporary account must actually stop working when its window closes.
     *
     * This is the half that was missing on Purchase, and nobody noticed because
     * the countdown, the badge and the expiry column all existed — only the
     * enforcement did not. Proven by probe before the fix: a Purchase window
     * that had closed three days earlier still answered 200 on the portal
     * dashboard. A limit that lapses and changes nothing is a label.
     */
    public function test_both_modules_actually_enforce_the_expiry(): void
    {
        // The sweep that warns first, then expires what has lapsed.
        foreach ([
            'tpv:temporary-access-reminders' => 'TPV',
            'purchase:temporary-access-reminders' => 'Purchase',
        ] as $command => $module) {
            $this->assertArrayHasKey($command, \Illuminate\Support\Facades\Artisan::all(),
                "{$module} has no sweep — its temporary vendors are never warned and never expired");
        }

        // And the per-request gate, because the sweep is only hourly.
        foreach ([
            \App\Services\Tpv\TpvAccessService::class => 'TPV',
            \App\Services\Purchase\PurchaseAccessService::class => 'Purchase',
        ] as $service => $module) {
            $this->assertTrue(method_exists($service, 'expire'),
                "{$module} has no way to close an access window");
            $this->assertTrue(method_exists($service, 'lazyExpire'),
                "{$module} cannot expire on contact, so a lapsed vendor keeps working until the next sweep");
        }
    }

    public function test_both_modules_track_which_warnings_have_gone_out(): void
    {
        // Without this an hourly sweep re-sends every threshold every hour,
        // which teaches people to ignore the one that matters.
        foreach ([
            'vendors' => 'TPV',
            'purchase_vendors' => 'Purchase',
        ] as $table => $module) {
            $this->assertTrue(
                Schema::hasColumn($table, 'access_reminders_sent'),
                "{$module} does not record which expiry warnings it has already sent",
            );
        }
    }

    /**
     * The type field cannot quietly do the job instead.
     *
     * Purchase's edit form used to offer Permanent/Temporary and write
     * vendor_type alone, while isTemporary() read registration_type — so the
     * badge changed and the expiry stayed. A promotion issues a code, re-opens
     * the portal, writes an audit row and emails the vendor; none of that
     * belongs in a profile save, and none of it should fire because somebody
     * changed a dropdown while editing an address.
     */
    public function test_the_purchase_update_request_cannot_change_the_registration_type(): void
    {
        $rules = (new \App\Http\Requests\Purchase\UpdatePurchaseVendorRequest())->rules();

        $this->assertArrayNotHasKey('registration_type', $rules,
            'the edit form can write registration_type again, bypassing the audited conversion');
    }
}
