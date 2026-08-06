<?php

namespace Tests\Feature\Email;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Email\EmailTemplateService;
use App\Support\Email\EmailTemplateRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * HTTP-layer tests for /api/settings/email-templates. The request pipeline has its
 * own failure modes (empty-string coercion, route-key matching on dotted keys,
 * auth, tenancy) that a service-level suite cannot see.
 */
class EmailTemplateApiTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private const OTHER = 999;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::TENANT, self::OTHER] as $id) {
            (new Tenant())->forceFill([
                'id' => $id, 'name' => 'Tenant '.$id, 'slug' => 'tenant-'.$id,
                'subdomain' => 'tenant'.$id, 'status' => 'active',
            ])->save();
        }
    }

    protected function tearDown(): void
    {
        EmailTemplateRegistry::flush();
        parent::tearDown();
    }

    private function admin(int $tenantId = self::TENANT): User
    {
        return User::create([
            'tenant_id' => $tenantId, 'name' => 'Admin', 'email' => 'a'.uniqid().'@test.com',
            'password' => bcrypt('secret'), 'role' => 'admin', 'status' => 'active',
        ]);
    }

    /* ── Listing ─────────────────────────────────────────────────────────── */

    public function test_index_returns_templates_categories_and_merge_fields(): void
    {
        Sanctum::actingAs($this->admin());

        $r = $this->getJson('/api/settings/email-templates')->assertOk();

        $this->assertNotEmpty($r->json('templates'));
        $this->assertNotEmpty($r->json('categories'));
        $this->assertNotEmpty($r->json('merge_fields'));
    }

    public function test_index_can_filter_by_category_and_search(): void
    {
        Sanctum::actingAs($this->admin());

        $finance = $this->getJson('/api/settings/email-templates?category=finance')->assertOk()->json('templates');
        $this->assertNotEmpty($finance);
        foreach ($finance as $t) {
            $this->assertSame('finance', $t['category']);
        }

        $this->assertNotEmpty($this->getJson('/api/settings/email-templates?search=invoice')->assertOk()->json('templates'));
    }

    /** Template keys contain dots — the route must match them, not 404. */
    public function test_dotted_template_keys_resolve(): void
    {
        Sanctum::actingAs($this->admin());

        $this->getJson('/api/settings/email-templates/finance.invoice')
            ->assertOk()
            ->assertJsonPath('key', 'finance.invoice');
    }

    public function test_unknown_key_404s(): void
    {
        Sanctum::actingAs($this->admin());

        $this->getJson('/api/settings/email-templates/nope.nope')->assertNotFound();
    }

    /* ── Update ──────────────────────────────────────────────────────────── */

    public function test_update_saves_a_customisation(): void
    {
        Sanctum::actingAs($this->admin());

        $this->putJson('/api/settings/email-templates/system.test', [
            'name' => 'My Test Mail', 'subject' => 'Hello {{customer.name}}',
            'body_html' => '<p>Hi {{customer.name}}</p>', 'body_text' => '', 'description' => 'mine', 'enabled' => true,
        ])
            ->assertOk()
            ->assertJsonPath('customised', true)
            ->assertJsonPath('version', 1)
            ->assertJsonPath('subject', 'Hello {{customer.name}}');
    }

    /** Cleared fields arrive as null (ConvertEmptyStringsToNull) — must not 500. */
    public function test_update_tolerates_cleared_fields(): void
    {
        Sanctum::actingAs($this->admin());

        $this->putJson('/api/settings/email-templates/system.test', [
            'subject' => 'S', 'body_html' => '<p>x</p>', 'body_text' => null, 'description' => null,
        ])->assertOk();
    }

    public function test_update_rejects_an_empty_subject(): void
    {
        Sanctum::actingAs($this->admin());

        $this->putJson('/api/settings/email-templates/system.test', ['subject' => ''])
            ->assertStatus(422);
    }

    /**
     * An unknown merge field is a warning, not a blocker — the UI shows it as an
     * advisory, so the API must not make the template permanently unsaveable.
     */
    public function test_update_allows_unknown_merge_fields_but_warns(): void
    {
        Sanctum::actingAs($this->admin());

        $this->putJson('/api/settings/email-templates/system.test', [
            'subject' => 'Hi {{not.a.field}}', 'body_html' => '<p>x</p>',
        ])->assertOk();

        $this->postJson('/api/settings/email-templates/validate', [
            'subject' => 'Hi {{not.a.field}}', 'body_html' => '<p>x</p>',
        ])
            ->assertOk()
            ->assertJsonPath('valid', true)
            ->assertJsonPath('warnings.merge_fields', fn ($m) => str_contains($m, 'not.a.field'));
    }

    public function test_update_rejects_a_body_that_sanitises_to_nothing(): void
    {
        Sanctum::actingAs($this->admin());

        $this->putJson('/api/settings/email-templates/system.test', [
            'subject' => 'S', 'body_html' => '<style>p{color:red}</style>', 'body_text' => '',
        ])->assertStatus(422);
    }

    public function test_saving_preserves_merge_field_links(): void
    {
        Sanctum::actingAs($this->admin());

        $r = $this->putJson('/api/settings/email-templates/auth.password_reset', [
            'subject' => 'Reset your password',
        ])->assertOk();

        $this->assertStringContainsString('{{url.reset}}', $r->json('body_html'));
    }

    /** Array members in filters/draft must 422, not 500. */
    public function test_malformed_input_is_rejected_not_fatal(): void
    {
        Sanctum::actingAs($this->admin());

        $this->getJson('/api/settings/email-templates?search[]=x')->assertStatus(422);
        $this->postJson('/api/settings/email-templates/system.test/preview', [
            'draft' => ['subject' => ['an', 'array']],
        ])->assertStatus(422);
    }

    public function test_update_strips_dangerous_html(): void
    {
        Sanctum::actingAs($this->admin());

        $r = $this->putJson('/api/settings/email-templates/system.test', [
            'subject' => 'S', 'body_html' => '<p>safe</p><script>alert(1)</script>',
        ])->assertOk();

        $this->assertStringNotContainsString('<script', $r->json('body_html'));
    }

    /* ── Preview / validate ──────────────────────────────────────────────── */

    public function test_preview_renders_without_saving_or_sending(): void
    {
        Sanctum::actingAs($this->admin());

        $r = $this->postJson('/api/settings/email-templates/finance.invoice/preview')->assertOk();

        $this->assertNotEmpty($r->json('subject'));
        $this->assertNotEmpty($r->json('html'));
        $this->assertDatabaseCount('email_templates', 0);
    }

    public function test_preview_accepts_an_unsaved_draft(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/settings/email-templates/system.test/preview', [
            'draft' => ['subject' => 'Draft for {{customer.name}}'],
        ])
            ->assertOk()
            ->assertJsonPath('subject', 'Draft for Acme Industries');

        $this->assertDatabaseCount('email_templates', 0);
    }

    public function test_validate_endpoint_reports_problems_without_saving(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/settings/email-templates/validate', [
            'subject' => '', 'body_html' => '',
        ])
            ->assertOk()
            ->assertJsonPath('valid', false)
            ->assertJsonPath('errors.subject', fn ($m) => is_string($m));

        $this->assertDatabaseCount('email_templates', 0);
    }

    /* ── Restore ─────────────────────────────────────────────────────────── */

    public function test_restore_returns_the_shipped_default(): void
    {
        Sanctum::actingAs($this->admin());
        app(EmailTemplateService::class)->update(self::TENANT, 'system.test', ['subject' => 'Custom']);

        $this->postJson('/api/settings/email-templates/system.test/restore')
            ->assertOk()
            ->assertJsonPath('customised', false)
            ->assertJsonPath('subject', EmailTemplateRegistry::get('system.test')['subject']);

        $this->assertDatabaseCount('email_templates', 0);
    }

    /* ── Tenancy & authorisation ─────────────────────────────────────────── */

    public function test_customisations_are_isolated_per_tenant(): void
    {
        Sanctum::actingAs($this->admin(self::TENANT));
        $this->putJson('/api/settings/email-templates/system.test', ['subject' => 'Tenant One'])->assertOk();

        Sanctum::actingAs($this->admin(self::OTHER));
        $this->getJson('/api/settings/email-templates/system.test')
            ->assertOk()
            ->assertJsonPath('customised', false)
            ->assertJsonPath('subject', EmailTemplateRegistry::get('system.test')['subject']);
    }

    public function test_endpoints_require_authentication(): void
    {
        $this->getJson('/api/settings/email-templates')->assertUnauthorized();
    }

    public function test_non_admin_is_forbidden(): void
    {
        $staff = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Staff', 'email' => 's'.uniqid().'@test.com',
            'password' => bcrypt('secret'), 'role' => 'staff', 'status' => 'active',
        ]);
        Sanctum::actingAs($staff);

        $this->getJson('/api/settings/email-templates')->assertForbidden();
    }
}
