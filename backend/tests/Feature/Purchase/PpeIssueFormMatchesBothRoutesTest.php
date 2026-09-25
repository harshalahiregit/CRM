<?php

namespace Tests\Feature\Purchase;

use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * One PPE issue form, two routes, and they must both take what it sends.
 *
 * The Statutory PPE step is a single React component. `/app/purchase/...`
 * renders it for staff and `/purchase-portal/...` renders it for the vendor,
 * and the two posted to endpoints that had never agreed on what to call the
 * item:
 *
 *   admin   product_id         qty nullable   issued_at
 *   portal  inventory_item_id  qty required   issued_date
 *
 * So the form worked for staff and answered every vendor with "The inventory
 * item id field is required." — naming a field their screen does not have, and
 * cannot show an error against, so the message had nowhere to land.
 *
 * purchasePortalApi translates the body now. This states the contract both
 * ends have to keep: what the form sends must satisfy the admin route, and
 * what the adapter produces must satisfy the portal route. Changing either
 * route's field names breaks this rather than a vendor's afternoon.
 *
 * Rules are read off the controllers, so the test follows them if they move.
 */
class PpeIssueFormMatchesBothRoutesTest extends TestCase
{
    /** Exactly what IssuePpeForm's submit() builds. */
    private const FORM_BODY = [
        'product_id' => 7,
        'qty' => 2,
        'size' => 'L',
        'notes' => 'Night shift',
    ];

    /** What purchasePortalApi.issuePpe turns that into. */
    private const PORTAL_BODY = [
        'inventory_item_id' => 7,
        'qty' => 2,
        'size' => 'L',
        'notes' => 'Night shift',
    ];

    /**
     * The validate([...]) array from a controller method, read from source.
     *
     * Reflection cannot reach it — the rules are a literal inside the method —
     * and mirroring them by hand in a test is how a test ends up asserting
     * against a contract that no longer exists.
     *
     * @return array<string, string>
     */
    private function rulesOf(string $file, string $method): array
    {
        $src = file_get_contents(base_path($file));
        $this->assertIsString($src, "{$file} could not be read");

        $at = strpos($src, "function {$method}(");
        $this->assertNotFalse($at, "{$method}() is gone from {$file} — update this test to follow it");

        $from = strpos($src, '$request->validate([', $at);
        $this->assertNotFalse($from, "{$method}() no longer validates inline");

        $body = substr($src, $from, strpos($src, ']);', $from) - $from);

        preg_match_all("/'([a-z_]+)'\s*=>\s*'([^']*)'/", $body, $m, PREG_SET_ORDER);
        $rules = [];
        foreach ($m as $row) {
            $rules[$row[1]] = $row[2];
        }

        $this->assertNotEmpty($rules, "no rules parsed out of {$method}()");

        return $rules;
    }

    private function adminRules(): array
    {
        return $this->rulesOf(
            'app/Http/Controllers/Api/Purchase/PurchaseWorkforceAdminController.php',
            'issuePpe'
        );
    }

    private function portalRules(): array
    {
        return $this->rulesOf(
            'app/Http/Controllers/Api/Portal/PurchasePortalWorkforceController.php',
            'issueWorkerPpe'
        );
    }

    public function test_the_form_body_satisfies_the_admin_route(): void
    {
        $v = Validator::make(self::FORM_BODY, $this->adminRules());

        $this->assertFalse($v->fails(), 'staff cannot issue PPE: '.implode(' | ', $v->errors()->all()));
    }

    public function test_the_translated_body_satisfies_the_portal_route(): void
    {
        $v = Validator::make(self::PORTAL_BODY, $this->portalRules());

        $this->assertFalse($v->fails(), 'a vendor cannot issue PPE: '.implode(' | ', $v->errors()->all()));
    }

    /**
     * And the untranslated body must NOT satisfy the portal route.
     *
     * Without this the pair above would still pass if somebody deleted the
     * translation and widened the portal rules to accept anything — the two
     * green ticks would be measuring nothing. This says the adapter is load
     * bearing: remove it and a vendor is refused again.
     */
    public function test_the_raw_form_body_is_what_the_portal_route_refuses(): void
    {
        $v = Validator::make(self::FORM_BODY, $this->portalRules());

        $this->assertTrue(
            $v->fails(),
            'the portal route now accepts product_id directly — if that is deliberate, '
            .'drop the translation in purchasePortalApi.issuePpe rather than leaving both'
        );
    }
}
