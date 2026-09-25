<?php

namespace Tests\Feature\Sales;

use App\Models\Sales\Lead;
use App\Models\Sales\LeadQuestionnaire;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The lead questionnaire round trip — SIR-000035.
 *
 * Five routes have existed since leads shipped and no code had ever called any
 * of them: the API client wired all five, and not one React component used it.
 * So the endpoints were not merely untested, they were unexercised — nothing had
 * ever proved a response could be recorded and read back at all.
 *
 * That is why this walks the whole path over HTTP rather than asserting against
 * the service. The screen's job is to build a questionnaire, send answers, and
 * show them on the lead; if any of those three fails the feature is still
 * invisible, which is exactly the state it was already in.
 */
class LeadQuestionnaireFlowTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'Tenant 1', 'slug' => 'lead-q-t',
            'subdomain' => 'leadq', 'status' => 'active',
        ])->save();

        $this->actor = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Rep', 'email' => 'rep'.uniqid().'@test.com',
            'password' => bcrypt('secret'), 'role' => 'admin', 'status' => 'active',
        ]);

        Sanctum::actingAs($this->actor);
    }

    private function lead(): Lead
    {
        return Lead::create([
            'tenant_id'  => self::TENANT,
            'created_by' => $this->actor->id,
            'name'       => 'Meridian Textiles',
        ]);
    }

    /** Built through the API, because that is the door the screen uses. */
    private function questionnaire(): array
    {
        return $this->postJson('/api/sales/lead-questionnaires', [
            'title'  => 'Site survey',
            'fields' => [
                ['label' => 'Warehouse size (sq ft)', 'field_type' => 'number', 'is_required' => true],
                ['label' => 'Loading bay?',           'field_type' => 'select', 'options' => ['Yes', 'No']],
            ],
        ])->assertCreated()->json();
    }

    public function test_a_questionnaire_comes_back_with_its_fields(): void
    {
        $this->questionnaire();

        // The dialog cannot render a form without the fields, and the list
        // endpoint is the only place it gets them.
        $this->getJson('/api/sales/lead-questionnaires')
            ->assertOk()
            ->assertJsonPath('0.title', 'Site survey')
            ->assertJsonCount(2, '0.fields');
    }

    public function test_a_response_is_recorded_and_read_back_on_the_lead(): void
    {
        $lead = $this->lead();
        $q    = $this->questionnaire();

        $this->postJson("/api/sales/leads/{$lead->id}/questionnaire-response", [
            'questionnaire_id' => $q['id'],
            'answers'          => ['Warehouse size (sq ft)' => '12000', 'Loading bay?' => 'Yes'],
        ])->assertCreated();

        // Keyed by label on purpose — the tab prints the key as the question.
        $this->getJson("/api/sales/leads/{$lead->id}")
            ->assertOk()
            ->assertJsonPath('questionnaire_responses.0.answers.Warehouse size (sq ft)', '12000')
            ->assertJsonPath('questionnaire_responses.0.questionnaire.title', 'Site survey');
    }

    /**
     * The endpoint is updateOrCreate on (tenant, questionnaire, lead), so a
     * second submission amends rather than appends. The dialog seeds itself from
     * the existing answers because of this: without that, correcting one field
     * would blank every other answer already recorded.
     */
    public function test_submitting_again_amends_the_same_response(): void
    {
        $lead = $this->lead();
        $q    = $this->questionnaire();

        $send = fn (array $answers) => $this->postJson(
            "/api/sales/leads/{$lead->id}/questionnaire-response",
            ['questionnaire_id' => $q['id'], 'answers' => $answers],
        )->assertCreated();

        $send(['Warehouse size (sq ft)' => '12000', 'Loading bay?' => 'Yes']);
        $send(['Warehouse size (sq ft)' => '14500', 'Loading bay?' => 'Yes']);

        $this->getJson("/api/sales/leads/{$lead->id}")
            ->assertOk()
            ->assertJsonCount(1, 'questionnaire_responses')
            ->assertJsonPath('questionnaire_responses.0.answers.Warehouse size (sq ft)', '14500');
    }

    public function test_a_response_needs_a_questionnaire_that_exists(): void
    {
        $lead = $this->lead();

        $this->postJson("/api/sales/leads/{$lead->id}/questionnaire-response", [
            'questionnaire_id' => 9999,
            'answers'          => ['anything' => 'at all'],
        ])->assertStatus(422);
    }

    /**
     * Tenant isolation on the door that was just opened.
     *
     * This test failed when first written: the rule was `exists:` against the
     * whole table, so tenant 1 could bind tenant 2's questionnaire to its own
     * lead. Nothing looked wrong afterwards — the response row carries the right
     * tenant_id — except that the lead profile eager-loads the questionnaire and
     * would render the other tenant's form title straight back.
     *
     * Reachable for the first time in the same change, so it is closed in the
     * same change.
     */
    public function test_another_tenants_questionnaire_cannot_be_recorded(): void
    {
        (new Tenant())->forceFill([
            'id' => 2, 'name' => 'Tenant 2', 'slug' => 'lead-q-t2',
            'subdomain' => 'leadq2', 'status' => 'active',
        ])->save();

        $outsider = User::create([
            'tenant_id' => 2, 'name' => 'Outsider', 'email' => 'out'.uniqid().'@test.com',
            'password' => bcrypt('secret'), 'role' => 'admin', 'status' => 'active',
        ]);

        $foreign = LeadQuestionnaire::create([
            'tenant_id'  => 2,
            'created_by' => $outsider->id,
            'title'      => 'Someone else’s form',
            'is_active'  => true,
        ]);

        $lead = $this->lead();

        $this->postJson("/api/sales/leads/{$lead->id}/questionnaire-response", [
            'questionnaire_id' => $foreign->id,
            'answers'          => ['x' => 'y'],
        ])->assertStatus(422)->assertJsonValidationErrors('questionnaire_id');

        // And nothing was written on the way to being refused.
        $this->getJson("/api/sales/leads/{$lead->id}")
            ->assertOk()
            ->assertJsonCount(0, 'questionnaire_responses');
    }
}
