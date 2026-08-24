<?php

namespace Tests\Feature\Customer;

use App\Models\Customer\Client;
use App\Models\Customer\ClientFeedback;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Customer\CustomerExperienceService;
use App\Services\Customer\CustomerHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * §10 — CSAT and NPS.
 *
 * The whole risk here is arithmetic. CSAT is a mean on a five-point scale; NPS
 * is promoters minus detractors as a percentage of respondents and runs from
 * -100 to +100. Treating either as the other produces a number that looks
 * plausible and is wrong, which is worse than showing nothing.
 */
class CustomerExperienceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Acme', 'slug' => 'acme', 'subdomain' => 'acme',
            'plan' => 'professional', 'status' => 'active',
        ]);
        $this->client = Client::create([
            'tenant_id' => $this->tenant->id, 'company' => 'Widget Ltd', 'active' => true,
        ]);
        Sanctum::actingAs(User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Admin', 'email' => 'a@x.test',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'active',
        ]));
    }

    private function feedback(string $metric, int $score): ClientFeedback
    {
        return ClientFeedback::create([
            'tenant_id' => $this->tenant->id, 'client_id' => $this->client->id,
            'metric' => $metric, 'score' => $score, 'responded_at' => now(),
        ]);
    }

    private function experience(): CustomerExperienceService
    {
        return app(CustomerExperienceService::class);
    }

    public function test_csat_is_the_mean_on_a_five_point_scale(): void
    {
        foreach ([5, 4, 3] as $s) {
            $this->feedback(ClientFeedback::CSAT, $s);
        }

        $csat = $this->experience()->forClient($this->client)['csat'];

        $this->assertSame(4.0, $csat['average']);
        $this->assertSame(80.0, $csat['percent']);
        // "Satisfied" is the share answering 4 or 5 — two of the three.
        $this->assertSame(66.7, $csat['satisfied']);
    }

    public function test_nps_is_promoters_minus_detractors_not_an_average(): void
    {
        // 2 promoters (9,10), 1 passive (7), 2 detractors (6,3) of 5.
        foreach ([10, 9, 7, 6, 3] as $s) {
            $this->feedback(ClientFeedback::NPS, $s);
        }

        $nps = $this->experience()->forClient($this->client)['nps'];

        $this->assertSame(2, $nps['promoters']);
        $this->assertSame(1, $nps['passives']);
        $this->assertSame(2, $nps['detractors']);
        // (2 - 2) / 5 = 0, NOT the mean of the scores (7).
        $this->assertSame(0.0, (float) $nps['score']);
    }

    public function test_a_wholly_negative_nps_is_minus_one_hundred_not_zero(): void
    {
        foreach ([0, 1, 2] as $s) {
            $this->feedback(ClientFeedback::NPS, $s);
        }

        $nps = $this->experience()->forClient($this->client)['nps'];

        $this->assertSame(-100.0, (float) $nps['score']);
        // Normalising must shift the midpoint, not clamp: -100 and 0 are
        // different states and must not both read as 0.
        $this->assertSame(0.0, (float) $nps['normalised']);
        $this->assertSame('Critical', $nps['band']);
    }

    public function test_a_neutral_nps_normalises_to_the_midpoint(): void
    {
        foreach ([9, 0] as $s) {           // 1 promoter, 1 detractor → 0
            $this->feedback(ClientFeedback::NPS, $s);
        }

        $this->assertSame(50.0, (float) $this->experience()->forClient($this->client)['nps']['normalised']);
    }

    public function test_a_csat_score_above_its_scale_is_refused(): void
    {
        // 9 is a valid NPS and an impossible CSAT. Validating both against 0-10
        // would accept it and skew every average built on it.
        $this->postJson("/api/customers/{$this->client->id}/feedback", [
            'metric' => 'CSAT', 'score' => 9, 'responded_at' => now()->toDateTimeString(),
        ])->assertStatus(422)->assertJsonValidationErrors('score');

        $this->postJson("/api/customers/{$this->client->id}/feedback", [
            'metric' => 'NPS', 'score' => 9, 'responded_at' => now()->toDateTimeString(),
        ])->assertCreated();
    }

    public function test_the_two_metrics_are_weighted_equally_for_health(): void
    {
        $this->feedback(ClientFeedback::CSAT, 5);   // 100%
        $this->feedback(ClientFeedback::NPS, 10);   // score +100 → normalised 100

        $signal = $this->experience()->healthSignal($this->client);

        $this->assertSame(100.0, $signal['score']);
        $this->assertStringContainsString('CSAT 5/5', $signal['detail']);
        $this->assertStringContainsString('NPS 100', $signal['detail']);
    }

    public function test_feedback_becomes_a_measurable_health_parameter(): void
    {
        $before = collect(app(CustomerHealthService::class)->score($this->client)['breakdown'])
            ->firstWhere('key', 'customer_feedback');
        $this->assertFalse($before['available']);

        $this->feedback(ClientFeedback::CSAT, 4);

        $after = collect(app(CustomerHealthService::class)->score($this->client->fresh())['breakdown'])
            ->firstWhere('key', 'customer_feedback');

        $this->assertTrue($after['available']);
        $this->assertSame(80.0, $after['score']);
    }

    // ── §10's other four items ────────────────────────────────────────────

    public function test_complaints_are_summarised_beside_the_scores(): void
    {
        $base = ['tenant_id' => $this->tenant->id, 'client_id' => $this->client->id, 'kind' => 'Complaint'];
        \App\Models\Customer\ClientComplaint::create($base + [
            'subject' => 'Late', 'severity' => 'Medium', 'status' => 'Resolved',
            'raised_at' => now()->subDays(10), 'resolved_at' => now()->subDays(9),
        ]);
        // array_merge, not `+`: PHP's array union keeps the LEFT side for a
        // duplicate key, so `$base + ['kind' => 'Escalation']` silently left
        // kind as 'Complaint' and this test never created an escalation at all.
        \App\Models\Customer\ClientComplaint::create(array_merge($base, [
            'kind' => 'Escalation', 'subject' => 'Repeated', 'severity' => 'High',
            'status' => 'Investigating', 'raised_at' => now()->subDays(2),
        ]));

        $c = $this->experience()->forClient($this->client)['complaints'];

        // A 4.5 CSAT beside two open escalations tells a different story from
        // a 4.5 on its own — which is why this belongs on the same screen.
        $this->assertSame(2, $c['total']);
        $this->assertSame(1, $c['open']);
        $this->assertSame(1, $c['escalations']);
        $this->assertSame(1, $c['severe']);
    }

    public function test_resolution_time_reports_tickets_and_complaints_separately(): void
    {
        \App\Models\Customer\ClientComplaint::create([
            'tenant_id' => $this->tenant->id, 'client_id' => $this->client->id,
            'kind' => 'Complaint', 'subject' => 'x',
            'raised_at' => '2026-08-01 09:00:00', 'resolved_at' => '2026-08-02 09:00:00',
        ]);

        $r = $this->experience()->forClient($this->client)['resolution'];

        // Kept apart deliberately: tickets measure day-to-day responsiveness,
        // complaints measure how long something that went properly wrong took
        // to put right. Averaged together, the second hides behind the first.
        $this->assertSame(24.0, $r['complaints']['average_hours']);
        $this->assertSame(1, $r['complaints']['resolved']);
        $this->assertNull($r['tickets'], 'no tickets exist, so there is nothing honest to report');
    }

    public function test_service_quality_is_null_when_nothing_is_measurable(): void
    {
        // "We have not measured this" and "this is poor" are different answers.
        $this->assertNull($this->experience()->forClient($this->client)['service_quality']);
    }

    public function test_service_quality_reuses_the_health_parameters(): void
    {
        \App\Models\Customer\ClientComplaint::create([
            'tenant_id' => $this->tenant->id, 'client_id' => $this->client->id,
            'kind' => 'Complaint', 'subject' => 'x', 'severity' => 'Critical',
            'status' => 'Open', 'raised_at' => now()->subDays(5),
        ]);

        $sq = $this->experience()->forClient($this->client->fresh())['service_quality'];
        $this->assertNotNull($sq);

        // It must agree with Health rather than compute a second opinion —
        // two screens disagreeing about one customer is worse than one number.
        $health = collect(app(CustomerHealthService::class)->score($this->client->fresh())['breakdown'])
            ->keyBy('key');
        $expected = collect(['service_performance', 'complaint_frequency'])
            ->map(fn ($k) => $health->get($k))
            ->filter(fn ($p) => $p && $p['available'])
            ->avg('score');

        $this->assertSame(round($expected, 1), $sq['score']);
        // And the inputs travel with it, so the figure is auditable.
        $this->assertNotEmpty($sq['inputs']);
        $this->assertArrayHasKey('detail', $sq['inputs'][0]);
    }

    public function test_the_index_returns_rows_with_their_summary(): void
    {
        $this->feedback(ClientFeedback::CSAT, 4);
        $this->feedback(ClientFeedback::NPS, 9);

        $this->getJson("/api/customers/{$this->client->id}/feedback")
            ->assertOk()
            ->assertJsonStructure(['rows', 'summary' => ['csat', 'nps', 'responses']]);
    }
}
