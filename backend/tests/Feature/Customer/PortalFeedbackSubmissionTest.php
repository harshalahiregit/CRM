<?php

namespace Tests\Feature\Customer;

use App\Models\Customer\Client;
use App\Models\Customer\ClientContact;
use App\Models\Customer\ClientFeedback;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * §10 — the customer answers the survey themselves.
 *
 * CSAT and NPS shipped as a staff-only register: somebody typed in a number
 * they had been told on a call. That is a record of a conversation, not a
 * survey — the person whose opinion it is had no way to give it, and Customer
 * Health was scoring "customer feedback" from second-hand data. The legacy CRM
 * did expose feedback to clients, so this was a regression as well as a gap.
 */
class PortalFeedbackSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;
    private ClientContact $contact;

    protected function setUp(): void
    {
        parent::setUp();

        $t = Tenant::create([
            'name' => 'Acme', 'slug' => 'acme', 'subdomain' => 'acme',
            'plan' => 'professional', 'status' => 'active',
        ]);
        $this->client = Client::create(['tenant_id' => $t->id, 'company' => 'Widget Ltd', 'active' => true]);
        $this->contact = ClientContact::create([
            'tenant_id' => $t->id, 'client_id' => $this->client->id,
            'first_name' => 'Anil', 'email' => 'anil@widget.test', 'active' => true,
            'portal_status' => 'active', 'password' => Hash::make('secret123'),
            'permissions' => [],   // deliberately none — see the test below
        ]);
        Sanctum::actingAs($this->contact, ['*']);
    }

    public function test_a_customer_can_submit_a_csat_score(): void
    {
        $this->postJson('/api/portal/client/feedback', ['metric' => 'CSAT', 'score' => 4])
            ->assertCreated();

        $row = ClientFeedback::first();
        $this->assertSame('CSAT', $row->metric);
        $this->assertSame(4, $row->score);
        $this->assertSame($this->contact->id, $row->client_contact_id);
        // Distinguishable from a score staff typed in after a phone call.
        $this->assertSame('portal', $row->collected_via);
    }

    public function test_a_customer_can_submit_an_nps_score_with_a_comment(): void
    {
        $this->postJson('/api/portal/client/feedback', [
            'metric' => 'NPS', 'score' => 9, 'comments' => 'Responsive team.',
        ])->assertCreated();

        $this->assertSame('Responsive team.', ClientFeedback::first()->comments);
    }

    public function test_the_score_bound_follows_the_metric(): void
    {
        // 9 is a valid NPS and an impossible CSAT. One rule for both would
        // accept it and skew every average built on the column.
        $this->postJson('/api/portal/client/feedback', ['metric' => 'CSAT', 'score' => 9])
            ->assertStatus(422)->assertJsonValidationErrors('score');

        $this->postJson('/api/portal/client/feedback', ['metric' => 'NPS', 'score' => 9])
            ->assertCreated();
    }

    public function test_answering_again_the_same_day_corrects_rather_than_duplicates(): void
    {
        $this->postJson('/api/portal/client/feedback', ['metric' => 'CSAT', 'score' => 2])->assertCreated();
        $this->postJson('/api/portal/client/feedback', ['metric' => 'CSAT', 'score' => 5])->assertOk();

        // A mis-tap is correctable; a bad afternoon cannot bury the average.
        $this->assertSame(1, ClientFeedback::count());
        $this->assertSame(5, ClientFeedback::first()->score);
    }

    public function test_the_two_metrics_are_recorded_separately(): void
    {
        $this->postJson('/api/portal/client/feedback', ['metric' => 'CSAT', 'score' => 4])->assertCreated();
        $this->postJson('/api/portal/client/feedback', ['metric' => 'NPS', 'score' => 8])->assertCreated();

        $this->assertSame(2, ClientFeedback::count());
    }

    public function test_it_needs_no_permission_flag(): void
    {
        // The contact above has permissions = []. Being asked what you think of
        // the service is not a privilege somebody grants you, and gating it
        // would silence exactly the accounts whose opinion matters most.
        $this->postJson('/api/portal/client/feedback', ['metric' => 'CSAT', 'score' => 3])
            ->assertCreated();
    }

    public function test_a_contact_only_sees_their_own_answers(): void
    {
        $colleague = ClientContact::create([
            'tenant_id' => $this->client->tenant_id, 'client_id' => $this->client->id,
            'first_name' => 'Other', 'email' => 'other@widget.test', 'active' => true,
            'portal_status' => 'active', 'password' => Hash::make('x'), 'permissions' => [],
        ]);
        ClientFeedback::create([
            'tenant_id' => $this->client->tenant_id, 'client_id' => $this->client->id,
            'client_contact_id' => $colleague->id, 'metric' => 'NPS', 'score' => 1,
            'collected_via' => 'portal', 'responded_at' => now(),
        ]);

        $this->postJson('/api/portal/client/feedback', ['metric' => 'CSAT', 'score' => 5])->assertCreated();

        $mine = $this->getJson('/api/portal/client/feedback')->assertOk()->json();
        $this->assertCount(1, $mine);
        $this->assertSame('CSAT', $mine[0]['metric']);
    }

    public function test_it_reaches_customer_health(): void
    {
        $this->postJson('/api/portal/client/feedback', ['metric' => 'CSAT', 'score' => 5])->assertCreated();

        $signal = app(\App\Services\Customer\CustomerExperienceService::class)
            ->healthSignal($this->client->fresh());

        // The whole point: a real answer from the customer now moves the score
        // that management reads.
        $this->assertNotNull($signal);
        $this->assertSame(100.0, $signal['score']);
    }

    public function test_an_unauthenticated_request_is_refused(): void
    {
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/portal/client/feedback', ['metric' => 'CSAT', 'score' => 5])
            ->assertStatus(401);
    }
}
