<?php

namespace Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

/**
 * One panel, two modules, two api clients — and nothing keeping them level.
 *
 * The vendor workspace renders shared components (VendorCustomersPanel above
 * all) for both TPV and Purchase, handing each its own api client. The panel
 * calls customers.list, .create, .search and .link. TPV defined four; Purchase
 * defined two.
 *
 * Calling a member that does not exist throws a TypeError INSIDE the promise
 * chain, so nothing surfaces: no toast, no console error the user sees, no
 * network request. The panel simply sat on "Searching…" for ever. It worked in
 * TPV, which is exactly what stopped anybody noticing.
 *
 * Same failure mode as MeetingEngineParityTest guards for the meeting engines,
 * and the same fix: compare the two clients as text and fail when one drifts.
 */
class VendorPanelParityTest extends TestCase
{
    private const SERVICES = __DIR__.'/../../../../frontend/src/services';

    /**
     * Methods a shared vendor panel calls that BOTH clients must define.
     *
     * Keyed by the nested namespace on `<api>.vendors`.
     */
    private const SHARED = [
        // VendorCustomersPanel — the Customer tab in both workspaces.
        'customers' => ['list', 'create', 'search', 'link'],
    ];

    /** The member names defined inside `<namespace>: { ... }` in a client. */
    private function membersOf(string $file, string $namespace): array
    {
        $src = file_get_contents(self::SERVICES.'/'.$file);

        $this->assertNotFalse($src, "cannot read {$file}");

        $at = strpos($src, $namespace.': {');
        $this->assertNotFalse($at, "{$file} has no `{$namespace}` namespace at all");

        // Walk braces so a nested object cannot end the block early.
        $i = strpos($src, '{', $at);
        $depth = 0;
        $end = $i;
        for ($n = strlen($src); $end < $n; $end++) {
            if ($src[$end] === '{') {
                $depth++;
            } elseif ($src[$end] === '}') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            }
        }

        $block = substr($src, $i, $end - $i);
        preg_match_all('/([a-zA-Z_$][\w$]*)\s*:/', $block, $m);

        return array_values(array_unique($m[1]));
    }

    public function test_the_purchase_client_offers_everything_the_shared_panels_call(): void
    {
        foreach (self::SHARED as $namespace => $required) {
            $purchase = $this->membersOf('purchaseApi.js', $namespace);

            $missing = array_values(array_diff($required, $purchase));

            $this->assertSame([], $missing,
                "\n  purchaseApi.vendors.{$namespace} is missing: ".implode(', ', $missing)
                ."\n  The shared panel calls these. A missing one throws inside the promise"
                ."\n  chain and the panel hangs with no error anywhere.\n");
        }
    }

    public function test_the_tpv_client_offers_them_too(): void
    {
        foreach (self::SHARED as $namespace => $required) {
            $tpv = $this->membersOf('tpvApi.js', $namespace);

            $missing = array_values(array_diff($required, $tpv));

            $this->assertSame([], $missing,
                "tpvApi.vendors.{$namespace} is missing: ".implode(', ', $missing));
        }
    }

    /**
     * And neither client should quietly grow a method the other lacks.
     *
     * Reported as a difference rather than a failure on a fixed list: the point
     * is that one module gaining a capability is the moment to decide whether
     * the other needs it, not six months later when somebody opens the tab.
     */
    public function test_the_two_clients_have_not_drifted_apart(): void
    {
        foreach (array_keys(self::SHARED) as $namespace) {
            $purchase = $this->membersOf('purchaseApi.js', $namespace);
            $tpv = $this->membersOf('tpvApi.js', $namespace);

            $onlyTpv = array_values(array_diff($tpv, $purchase));
            $onlyPurchase = array_values(array_diff($purchase, $tpv));

            $this->assertSame([], $onlyTpv,
                "TPV's vendors.{$namespace} has methods Purchase does not: "
                .implode(', ', $onlyTpv).' — the shared panel will hang on Purchase');

            $this->assertSame([], $onlyPurchase,
                "Purchase's vendors.{$namespace} has methods TPV does not: "
                .implode(', ', $onlyPurchase));
        }
    }
}
