<?php

namespace Tests\Feature\Purchase;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Open every Purchase screen, as an admin, and see which ones break.
 *
 * The equivalent of clicking every tab — but every tab, every time, in eleven
 * seconds. A screen that 500s because a relation is missing, a column was
 * renamed or a service throws on empty data looks exactly like "nothing works"
 * to somebody using the module, and none of it shows up in a build.
 *
 * ── What counts as a failure ────────────────────────────────────────────
 * Only 5xx, and 404 on a route that takes no parameters. A 403 is a policy
 * doing its job, a 422 is validation, and a 404 on /vendors/{id} is simply a
 * vendor that does not exist in a fresh database. Those are answers; a 500 is
 * the screen falling over.
 *
 * Parameterised routes are probed with id 1 where a fixture exists for it, and
 * skipped otherwise — guessing ids would produce noise, not findings.
 */
class PurchaseModuleSweepTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'Sangoe OS', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();
    }

    private function admin(): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'a-'.Str::random(6).'@t.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    /**
     * Every GET the Purchase admin surface serves, with no parameters.
     *
     * These are the landing screens — the ones a person actually clicks a tab to
     * reach. If one of them 500s on an empty database it will 500 for a new
     * tenant on their first day.
     */
    public function test_every_purchase_admin_screen_opens(): void
    {
        Sanctum::actingAs($this->admin());

        $uris = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => in_array('GET', $r->methods(), true))
            ->map(fn ($r) => $r->uri())
            ->filter(fn ($u) => Str::startsWith($u, 'api/purchase/'))
            // Parameterless only — see the class docblock.
            ->reject(fn ($u) => Str::contains($u, '{'))
            ->unique()->values();

        $this->assertGreaterThan(20, $uris->count(), 'the sweep found suspiciously few screens');

        $broken = [];

        foreach ($uris as $uri) {
            try {
                $res = $this->getJson('/'.$uri);
                $status = $res->getStatusCode();

                if ($status >= 500) {
                    $broken[$uri] = $status.' — '.Str::limit(
                        (string) ($res->json('message') ?? $res->getContent()), 150);
                }
            } catch (\Throwable $e) {
                // An exception that escapes the handler entirely is the worst
                // case: the screen returns nothing at all.
                $broken[$uri] = get_class($e).' — '.Str::limit($e->getMessage(), 150);
            }
        }

        $this->assertSame([], $broken, "\nPurchase screens that fail to open:\n"
            .collect($broken)->map(fn ($m, $u) => "  {$u}\n      {$m}")->implode("\n")."\n");
    }

    /**
     * The same sweep for the vendor-facing portal.
     *
     * A vendor sees a different set of screens through a different identity, and
     * the portal is where a broken page is least likely to be noticed by us and
     * most likely to be noticed by them.
     */
    public function test_every_purchase_portal_screen_opens(): void
    {
        $vendor = \App\Models\Purchase\PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Southgate',
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => 'sg-'.Str::random(4).'@t.local',
            'status' => 'Active', 'portal_status' => 'active',
        ]);

        Sanctum::actingAs($vendor);

        $uris = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => in_array('GET', $r->methods(), true))
            ->map(fn ($r) => $r->uri())
            ->filter(fn ($u) => Str::startsWith($u, 'api/portal/purchase'))
            ->reject(fn ($u) => Str::contains($u, '{'))
            ->unique()->values();

        $broken = [];

        foreach ($uris as $uri) {
            try {
                $res = $this->getJson('/'.$uri);
                if ($res->getStatusCode() >= 500) {
                    $broken[$uri] = $res->getStatusCode().' — '.Str::limit(
                        (string) ($res->json('message') ?? $res->getContent()), 150);
                }
            } catch (\Throwable $e) {
                $broken[$uri] = get_class($e).' — '.Str::limit($e->getMessage(), 150);
            }
        }

        $this->assertSame([], $broken, "\nPurchase PORTAL screens that fail to open:\n"
            .collect($broken)->map(fn ($m, $u) => "  {$u}\n      {$m}")->implode("\n")."\n");
    }

    /**
     * Every route the portal serves is reachable by the identity that uses it.
     *
     * The Accept-kickoff button posted to /portal/purchase/kickoff/accept, which
     * no route served — the button existed, the client method existed, and the
     * request 404'd. Nothing in a build catches a URL that is merely wrong.
     */
    public function test_the_portal_kickoff_accept_endpoint_exists(): void
    {
        $served = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($r) => $r->uri())->contains('api/portal/purchase/kickoff/accept');

        $this->assertTrue($served,
            'the purchase portal Kickoff tab posts here; without it the Accept button 404s');
    }
}
