<?php

namespace Tests\Feature\Contract;

use App\Models\Customer\Client;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Contract\ContractDocumentService;
use App\Support\FrontendUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Every link a contract hands out must point at the deployment, not localhost.
 *
 * Reported live: the signing link came back as http://localhost:5173/... on
 * https://crm.nexforeconsulting.com. The cause is a Laravel trap rather than a
 * missing setting -- `php artisan config:cache` stops loading .env altogether,
 * so `env('FRONTEND_URL', 'http://localhost:5173')` at a call site resolves to
 * the literal default in production however the .env is written. Four call
 * sites in this module did exactly that.
 *
 * This is invisible to every other kind of check: the endpoint answers 200, the
 * URL is well-formed, and the failure happens on somebody else's machine when
 * they click it. The verify URL is worse -- it is baked into the QR code on
 * every page of every contract PDF, so it goes out on paper.
 */
class ContractLinksUseTheRealDomainTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;
    private const LIVE = 'https://crm.nexforeconsulting.com';

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'Sangoe OS', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();

        // What a cached production config looks like: the value is in config,
        // and env() is not consulted because .env was never loaded.
        config(['app.frontend_url' => self::LIVE]);
    }

    private function admin(): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'a-'.Str::random(6).'@t.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function contractId(): int
    {
        $c = Client::create(['tenant_id' => self::TENANT, 'company' => 'Northwind Traders']);

        return (int) $this->postJson('/api/contracts', [
            'title' => 'AMC', 'party_type' => 'customer', 'party_id' => $c->id,
            'pages' => [['title' => 'Scope', 'content' => '<p>As agreed.</p>']],
        ])->assertSuccessful()->json('id');
    }

    public function test_the_signing_link_points_at_the_deployment(): void
    {
        Sanctum::actingAs($this->admin());
        $id = $this->contractId();

        $body = $this->getJson("/api/contracts/{$id}/signing-link")->assertOk()->json();

        // This is the link a staff member copies and pastes to the counterparty.
        $this->assertStringStartsWith(self::LIVE.'/contracts/sign/', $body['url']);
        $this->assertStringNotContainsString('localhost', $body['url']);
        $this->assertFalse($body['is_local'], 'the link is live, so it must not be flagged as a dev fallback');
    }

    public function test_the_verification_qr_points_at_the_deployment(): void
    {
        Sanctum::actingAs($this->admin());
        $id = $this->contractId();

        $body = $this->getJson("/api/contracts/{$id}/signing-link")->assertOk()->json();

        // The same string is encoded into the QR on every printed page. A
        // localhost value here cannot be recalled once the PDF is signed.
        $this->assertStringStartsWith(self::LIVE.'/contracts/verify/', $body['verify_url']);
        $this->assertStringNotContainsString('localhost', $body['verify_url']);
    }

    public function test_a_dev_box_still_gets_its_dev_link(): void
    {
        // Nothing configured at all -- a developer's machine.
        config(['app.frontend_url' => null, 'app.url' => null]);

        $this->assertStringContainsString('localhost', FrontendUrl::base());
        $this->assertTrue(FrontendUrl::isDevFallback());
    }

    public function test_app_url_carries_a_single_origin_deployment(): void
    {
        // The common production shape: API and SPA on one host, only APP_URL
        // set. FRONTEND_URL has to be genuinely absent for this to be the
        // question being asked -- an operator who sets it means it, and it is
        // supposed to win. (Which is its own warning: a deployment whose .env
        // still carries the dev FRONTEND_URL=http://localhost:5173 gets
        // localhost links no matter what APP_URL says. Unset it, don't fight it.)
        $repo = \Illuminate\Support\Env::getRepository();
        $had = $repo->get('FRONTEND_URL');
        $repo->clear('FRONTEND_URL');

        try {
            config(['app.frontend_url' => null, 'app.url' => self::LIVE]);

            $this->assertSame(self::LIVE, FrontendUrl::base());
            $this->assertFalse(FrontendUrl::isDevFallback());
        } finally {
            if ($had !== null) {
                $repo->set('FRONTEND_URL', $had);
            }
        }
    }

    public function test_an_explicit_setting_still_wins_over_app_url(): void
    {
        // The dev-machine shape, and the reason the order is what it is: the
        // API is on 127.0.0.1:8000 and the SPA on localhost:5173, so APP_URL is
        // not where a person's browser should be sent.
        config(['app.frontend_url' => 'http://localhost:5173', 'app.url' => self::LIVE]);

        $this->assertSame('http://localhost:5173', FrontendUrl::base());
    }

    public function test_the_document_service_agrees_with_the_endpoint(): void
    {
        Sanctum::actingAs($this->admin());
        $id = $this->contractId();

        $contract = \App\Models\Contract\Contract::findOrFail($id);
        $fromService = app(ContractDocumentService::class)->verifyUrl($contract);
        $fromApi = $this->getJson("/api/contracts/{$id}/signing-link")->json('verify_url');

        // Two resolvers drifting apart is how this class of bug started: the
        // same deployment produced links on different hosts depending on which
        // feature built them.
        $this->assertSame($fromApi, $fromService);
    }

    /**
     * The ratchet.
     *
     * The fix is only durable if the next person does not reach for env() at a
     * call site, which is the obvious thing to write and reads perfectly well.
     */
    public function test_no_contract_code_resolves_the_frontend_url_itself(): void
    {
        $offenders = [];

        foreach ([app_path('Http/Controllers/Api/Contract'), app_path('Services/Contract')] as $dir) {
            foreach (glob($dir.'/*.php') as $file) {
                if (str_contains(file_get_contents($file), "env('FRONTEND_URL'")) {
                    $offenders[] = basename($file);
                }
            }
        }

        $this->assertSame([], $offenders,
            'these read FRONTEND_URL directly, which returns the localhost default '
            .'under config:cache — use App\Support\FrontendUrl instead: '.implode(', ', $offenders));
    }
}
