<?php

namespace Tests\Feature\Numbering;

use App\Models\Numbering\DocumentNumberConfig;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Numbering\DatabaseDocumentNumberService;
use App\Support\Numbering\DocumentTypeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * HTTP-layer tests for /api/settings/numbering.
 *
 * These exist because the service-level suite could not see controller/request
 * defects: the very first Save from the shipped UI used to 500 (empty-string
 * suffix -> ConvertEmptyStringsToNull -> NOT NULL column). Anything that goes
 * through the request pipeline belongs here.
 */
class DocumentNumberingApiTest extends TestCase
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
        DocumentTypeRegistry::flush();
        parent::tearDown();
    }

    private function admin(int $tenantId = self::TENANT): User
    {
        return User::create([
            'tenant_id' => $tenantId, 'name' => 'Admin', 'email' => 'a'.uniqid().'@test.com',
            'password' => bcrypt('secret'), 'role' => 'admin', 'status' => 'active',
        ]);
    }

    /** Exactly what the settings form posts for an untouched invoice config. */
    private function uiPayload(array $overrides = []): array
    {
        return array_merge([
            'format' => '{PREFIX}-{YYYY}-{NEXT}', 'prefix' => 'INV', 'suffix' => '',
            'minimum_digits' => 3, 'padding' => '0', 'starting_number' => 1,
            'reset_rule' => 'yearly', 'enabled' => true, 'locked' => false,
            'manual_override' => false, 'decrement_on_delete' => false,
        ], $overrides);
    }

    /* ── The regression that the service tests could not catch ───────────── */

    /**
     * The shipped form always submits suffix:"" — Laravel's global
     * ConvertEmptyStringsToNull turns that into null, and every column is NOT NULL.
     * This save must succeed, not 500.
     */
    public function test_saving_the_default_form_payload_succeeds(): void
    {
        Sanctum::actingAs($this->admin());

        $this->putJson('/api/settings/numbering/invoice', $this->uiPayload())
            ->assertOk()
            ->assertJsonPath('document_type', 'invoice')
            ->assertJsonPath('suffix', '')
            ->assertJsonPath('enabled', true);

        $this->assertDatabaseHas('document_number_configs', [
            'tenant_id' => self::TENANT, 'document_type' => 'invoice', 'suffix' => '', 'prefix' => 'INV',
        ]);
    }

    public function test_explicit_nulls_fall_back_to_defaults_instead_of_failing(): void
    {
        Sanctum::actingAs($this->admin());

        $this->putJson('/api/settings/numbering/invoice', $this->uiPayload([
            'suffix' => null, 'prefix' => null, 'padding' => null,
        ]))->assertOk()->assertJsonPath('suffix', '')->assertJsonPath('padding', '0');
    }

    /** An omitted field must keep the validated/previewed value, not a column default. */
    public function test_partial_payload_persists_the_validated_values(): void
    {
        Sanctum::actingAs($this->admin());

        $this->putJson('/api/settings/numbering/invoice', ['format' => '{PREFIX}-{YYYY}-{NEXT}', 'enabled' => true])
            ->assertOk()
            ->assertJsonPath('prefix', 'INV')          // registry default, not ''
            ->assertJsonPath('minimum_digits', 3)      // registry default, not 4
            ->assertJsonPath('reset_rule', 'yearly');  // registry default, not 'never'
    }

    /* ── Validation ──────────────────────────────────────────────────────── */

    public function test_digit_padding_is_rejected(): void
    {
        Sanctum::actingAs($this->admin());

        // pad '1' would make sequence 111 and 1111 both render "1111".
        $this->putJson('/api/settings/numbering/invoice', $this->uiPayload(['padding' => '1']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('padding');
    }

    public function test_format_without_next_is_rejected(): void
    {
        Sanctum::actingAs($this->admin());

        $this->putJson('/api/settings/numbering/invoice', $this->uiPayload(['format' => '{PREFIX}-{YYYY}']))
            ->assertStatus(422)
            ->assertJsonPath('errors.format', fn ($m) => str_contains($m, '{NEXT}'));
    }

    public function test_validate_endpoint_reports_errors_without_saving(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/settings/numbering/validate', [
            'document_type' => 'invoice', 'format' => '{PREFIX}-{NOPE}-{NEXT}',
        ])->assertOk()->assertJsonPath('valid', false);

        $this->assertDatabaseCount('document_number_configs', 0);
    }

    /* ── Locking ─────────────────────────────────────────────────────────── */

    public function test_locked_config_rejects_edits(): void
    {
        Sanctum::actingAs($this->admin());
        DocumentNumberConfig::create(array_merge(DocumentTypeRegistry::defaults('invoice'),
            ['tenant_id' => self::TENANT, 'enabled' => true, 'locked' => true]));

        $this->putJson('/api/settings/numbering/invoice', $this->uiPayload(['locked' => true, 'prefix' => 'HACK']))
            ->assertStatus(422);
    }

    /** Unlock-and-edit in one request must not slip past the lock. */
    public function test_locked_config_cannot_be_unlocked_and_edited_in_one_request(): void
    {
        Sanctum::actingAs($this->admin());
        DocumentNumberConfig::create(array_merge(DocumentTypeRegistry::defaults('invoice'),
            ['tenant_id' => self::TENANT, 'enabled' => true, 'locked' => true]));

        $this->putJson('/api/settings/numbering/invoice', $this->uiPayload(['locked' => false, 'prefix' => 'HACK']))
            ->assertStatus(422);

        $this->assertDatabaseHas('document_number_configs', ['document_type' => 'invoice', 'prefix' => 'INV', 'locked' => true]);
    }

    public function test_locked_config_can_be_unlocked_on_its_own(): void
    {
        Sanctum::actingAs($this->admin());
        DocumentNumberConfig::create(array_merge(DocumentTypeRegistry::defaults('invoice'),
            ['tenant_id' => self::TENANT, 'enabled' => true, 'locked' => true]));

        $this->putJson('/api/settings/numbering/invoice', $this->uiPayload(['locked' => false]))
            ->assertOk()->assertJsonPath('locked', false);
    }

    /* ── Reset-rule change cannot silently re-mint numbers ───────────────── */

    public function test_reset_rule_cannot_change_once_numbers_are_issued(): void
    {
        Sanctum::actingAs($this->admin());
        DocumentNumberConfig::create(array_merge(DocumentTypeRegistry::defaults('invoice'),
            ['tenant_id' => self::TENANT, 'enabled' => true]));

        app(DatabaseDocumentNumberService::class)->generate(self::TENANT, 'invoice');

        $this->putJson('/api/settings/numbering/invoice', $this->uiPayload(['reset_rule' => 'monthly']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('reset_rule');
    }

    public function test_reset_rule_may_change_before_any_number_is_issued(): void
    {
        Sanctum::actingAs($this->admin());

        $this->putJson('/api/settings/numbering/invoice', $this->uiPayload(['reset_rule' => 'monthly']))
            ->assertOk()->assertJsonPath('reset_rule', 'monthly');
    }

    /* ── Preview / reset / listing ───────────────────────────────────────── */

    public function test_preview_endpoint_does_not_consume_a_number(): void
    {
        Sanctum::actingAs($this->admin());
        DocumentNumberConfig::create(array_merge(DocumentTypeRegistry::defaults('invoice'),
            ['tenant_id' => self::TENANT, 'enabled' => true]));

        $first = $this->postJson('/api/settings/numbering/invoice/preview')->assertOk()->json('preview');
        $second = $this->postJson('/api/settings/numbering/invoice/preview')->assertOk()->json('preview');

        $this->assertSame($first, $second);
        $this->assertDatabaseCount('document_number_sequences', 0);
    }

    public function test_reset_endpoint_opens_a_new_period(): void
    {
        Sanctum::actingAs($this->admin());
        DocumentNumberConfig::create(array_merge(DocumentTypeRegistry::defaults('invoice'),
            ['tenant_id' => self::TENANT, 'enabled' => true]));
        app(DatabaseDocumentNumberService::class)->generate(self::TENANT, 'invoice');

        $this->postJson('/api/settings/numbering/invoice/reset', ['starting_number' => 700])
            ->assertOk()->assertJsonPath('epoch', 1)->assertJsonPath('starting_number', 700);
    }

    public function test_index_lists_the_catalogue_with_placeholders_and_rules(): void
    {
        Sanctum::actingAs($this->admin());

        $r = $this->getJson('/api/settings/numbering')->assertOk();
        $this->assertNotEmpty($r->json('types'));
        $this->assertNotEmpty($r->json('reset_rules'));
        $this->assertNotEmpty($r->json('placeholders'));
    }

    public function test_unknown_document_type_404s(): void
    {
        Sanctum::actingAs($this->admin());

        $this->getJson('/api/settings/numbering/not_a_type')->assertNotFound();
    }

    /* ── Tenancy & authorisation ─────────────────────────────────────────── */

    public function test_configuration_is_isolated_per_tenant(): void
    {
        Sanctum::actingAs($this->admin(self::TENANT));
        $this->putJson('/api/settings/numbering/invoice', $this->uiPayload(['prefix' => 'AAA']))->assertOk();

        Sanctum::actingAs($this->admin(self::OTHER));
        $this->getJson('/api/settings/numbering/invoice')
            ->assertOk()
            ->assertJsonPath('prefix', 'INV')        // registry default, not tenant 1's
            ->assertJsonPath('configured', false);
    }

    public function test_endpoints_require_authentication(): void
    {
        $this->getJson('/api/settings/numbering')->assertUnauthorized();
    }

    public function test_non_admin_is_forbidden(): void
    {
        $staff = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Staff', 'email' => 's'.uniqid().'@test.com',
            'password' => bcrypt('secret'), 'role' => 'staff', 'status' => 'active',
        ]);
        Sanctum::actingAs($staff);

        $this->getJson('/api/settings/numbering')->assertForbidden();
    }
}
