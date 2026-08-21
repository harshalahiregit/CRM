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

    public function test_the_index_returns_rows_with_their_summary(): void
    {
        $this->feedback(ClientFeedback::CSAT, 4);
        $this->feedback(ClientFeedback::NPS, 9);

        $this->getJson("/api/customers/{$this->client->id}/feedback")
            ->assertOk()
            ->assertJsonStructure(['rows', 'summary' => ['csat', 'nps', 'responses']]);
    }
}
