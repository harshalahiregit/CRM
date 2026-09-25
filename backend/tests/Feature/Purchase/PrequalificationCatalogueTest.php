<?php

namespace Tests\Feature\Purchase;

use App\Models\Purchase\PurchaseVendor;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Purchase\PurchasePrequalificationService;
use App\Support\Purchase\PrequalificationCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The prequalification questionnaire is a thing an admin can edit.
 *
 * It was 280 lines of config/purchase_prequalification.php, identical for every
 * workspace and changeable only with a deploy. Two reported issues, one cause:
 * "admin should be able to set the pre-qualification questions", and "the form
 * has too many drop-downs — how do I set the drop-down pointers from settings?"
 * Every drop-down on that form IS a question in this file.
 *
 * The shipped questionnaire stays the default, so nothing changes for a
 * workspace that never edits it.
 */
class PrequalificationCatalogueTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private User $admin;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();

        $this->admin = $this->person('admin');
        $this->staff = $this->person('staff');
    }

    private function person(string $role): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => ucfirst($role), 'role' => $role,
            'email' => $role.'-'.Str::random(6).'@t.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    /** A minimal but valid questionnaire. */
    private function sections(array $overrides = []): array
    {
        return array_merge([
            'safety' => [
                'label' => 'Safety record',
                'questions' => [
                    'incidents' => [
                        'label' => 'Lost-time incidents last year',
                        'options' => [
                            'none' => ['label' => 'None', 'points' => 3],
                            'some' => ['label' => 'One or more', 'points' => 0],
                        ],
                    ],
                ],
            ],
        ], $overrides);
    }

    private function save(array $sections, ?User $as = null)
    {
        Sanctum::actingAs($as ?? $this->admin);

        return $this->putJson('/api/purchase/settings/prequalification', ['sections' => $sections]);
    }

    /* ── the point ──────────────────────────────────────────────── */

    public function test_an_admin_can_replace_the_questionnaire(): void
    {
        $this->save($this->sections())->assertOk();

        $stored = PrequalificationCatalogue::forTenant(self::TENANT);

        $this->assertSame(['safety'], array_keys($stored));
        $this->assertSame('Lost-time incidents last year', $stored['safety']['questions']['incidents']['label']);
    }

    public function test_an_untouched_workspace_still_gets_the_shipped_questionnaire(): void
    {
        // Nothing is migrated and nothing changes until somebody chooses to
        // change it.
        $this->assertSame(
            config('purchase_prequalification.sections'),
            PrequalificationCatalogue::forTenant(self::TENANT),
        );
        $this->assertFalse(PrequalificationCatalogue::isCustomised(self::TENANT));
    }

    public function test_the_screen_is_told_what_the_standard_questionnaire_is(): void
    {
        Sanctum::actingAs($this->admin);
        $body = $this->getJson('/api/purchase/settings/prequalification')->assertOk()->json('data');

        // So "back to standard" can show what it would mean rather than asking
        // blind.
        $this->assertNotEmpty($body['defaults']);
        $this->assertFalse($body['customised']);
    }

    public function test_resetting_goes_back_to_the_shipped_questionnaire(): void
    {
        $this->save($this->sections())->assertOk();
        $this->assertTrue(PrequalificationCatalogue::isCustomised(self::TENANT));

        Sanctum::actingAs($this->admin);
        $this->postJson('/api/purchase/settings/prequalification/reset')->assertOk();

        $this->assertFalse(PrequalificationCatalogue::isCustomised(self::TENANT));
        $this->assertSame(config('purchase_prequalification.sections'), PrequalificationCatalogue::forTenant(self::TENANT));
    }

    public function test_each_workspace_keeps_its_own_questionnaire(): void
    {
        (new Tenant())->forceFill([
            'id' => 2, 'name' => 'T2', 'slug' => 't2', 'subdomain' => 't2', 'status' => 'active',
        ])->save();

        $this->save($this->sections())->assertOk();

        $this->assertSame(['safety'], array_keys(PrequalificationCatalogue::forTenant(self::TENANT)));
        $this->assertSame(config('purchase_prequalification.sections'), PrequalificationCatalogue::forTenant(2));
    }

    /* ── scoring follows the questionnaire ──────────────────────── */

    public function test_a_vendor_is_scored_against_its_own_workspaces_questions(): void
    {
        $this->save($this->sections())->assertOk();

        $vendor = PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Southgate',
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(5)),
            'email' => 'sg@t.local', 'status' => 'Active',
        ]);

        $svc = app(PurchasePrequalificationService::class);
        $svc->assess($vendor, ['incidents' => 'none'], null, $this->admin);

        // 3 of a maximum of 3 — scored out of THIS questionnaire, not out of a
        // maximum that still counts the questions the workspace removed.
        $this->assertSame(100, (int) $vendor->fresh()->qualification_score);
    }

    public function test_an_answer_to_a_removed_question_is_dropped(): void
    {
        $this->save($this->sections())->assertOk();

        $vendor = PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Southgate',
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(5)),
            'email' => 'sg2@t.local', 'status' => 'Active',
        ]);

        app(PurchasePrequalificationService::class)
            ->assess($vendor, ['incidents' => 'none', 'turnover' => 'over_5x'], null, $this->admin);

        // 'turnover' is a shipped question this workspace removed. A stale form
        // must not be able to put points back on the board.
        $this->assertSame(['incidents' => 'none'], $vendor->fresh()->qualification_responses);
    }

    /* ── what a bad questionnaire would do to every score ───────── */

    public function test_a_question_with_one_answer_is_refused(): void
    {
        $bad = $this->sections();
        $bad['safety']['questions']['incidents']['options'] = ['none' => ['label' => 'None', 'points' => 3]];

        // One option scores the same for everybody — it cannot change an
        // outcome, it only lengthens the form.
        $this->save($bad)->assertStatus(422);
    }

    public function test_a_question_whose_answers_all_score_the_same_is_refused(): void
    {
        $bad = $this->sections();
        $bad['safety']['questions']['incidents']['options'] = [
            'a' => ['label' => 'Yes', 'points' => 2],
            'b' => ['label' => 'No', 'points' => 2],
        ];

        $this->save($bad)->assertStatus(422);
    }

    public function test_a_section_with_no_questions_is_refused(): void
    {
        // The score is a sum divided by the maximum, so an empty section is a
        // division by zero waiting to happen.
        $this->save(['empty' => ['label' => 'Nothing here', 'questions' => []]])->assertStatus(422);
    }

    public function test_a_non_numeric_score_is_refused(): void
    {
        $bad = $this->sections();
        $bad['safety']['questions']['incidents']['options']['none']['points'] = 'three';

        $this->save($bad)->assertStatus(422);
    }

    public function test_an_empty_questionnaire_is_refused(): void
    {
        $this->save([])->assertStatus(422);
    }

    public function test_keys_are_normalised_rather_than_taken_as_typed(): void
    {
        $this->save([
            'Safety Record ' => [
                'label' => 'Safety record',
                'questions' => [
                    'Lost Time!' => [
                        'label' => 'Lost-time incidents',
                        'options' => [
                            'None At All' => ['label' => 'None', 'points' => 3],
                            'some'        => ['label' => 'Some', 'points' => 0],
                        ],
                    ],
                ],
            ],
        ])->assertOk();

        $stored = PrequalificationCatalogue::forTenant(self::TENANT);

        // Answers are stored against these keys and compared later — "Turn Over"
        // and "turn_over" would otherwise be two questions holding one answer.
        $this->assertSame(['safety_record'], array_keys($stored));
        $this->assertSame(['lost_time'], array_keys($stored['safety_record']['questions']));
        $this->assertSame(['none_at_all', 'some'], array_keys($stored['safety_record']['questions']['lost_time']['options']));
    }

    /* ── who may change it ──────────────────────────────────────── */

    public function test_only_an_admin_may_change_the_questionnaire(): void
    {
        // A manager may assess a vendor against the questionnaire; deciding what
        // the questionnaire IS is a different thing.
        $this->save($this->sections(), $this->staff)->assertStatus(403);

        Sanctum::actingAs($this->staff);
        $this->getJson('/api/purchase/settings/prequalification')->assertStatus(403);
        $this->postJson('/api/purchase/settings/prequalification/reset')->assertStatus(403);
    }
}
