<?php

namespace Tests\Feature\Customer;

use App\Models\Customer\Client;
use App\Models\Customer\ClientFeedback;
use App\Models\Tenant;
use App\Services\Customer\CustomerExperienceService;
use App\Services\Customer\CustomerHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Health and Customer Experience must not call each other in a cycle.
 *
 * They did. CustomerHealthService::score() delegates its feedback signal to
 * CustomerExperienceService::healthSignal(); that called forClient(), which
 * builds service_quality, which called CustomerHealthService::score() again.
 *
 * Unbounded mutual recursion. It exhausted PHP's 128MB limit and returned a 504
 * on the Customer 360 overview — on a production database whose largest table
 * holds 428 rows, so it was never about data volume. It would do this on an
 * empty database, and it did so from the day the two services met.
 *
 * Each half was reasonable alone: Health delegates feedback to the service that
 * owns it, and Experience reuses Health's signals so the two screens cannot
 * disagree. The cycle only exists in the combination, which is exactly the kind
 * of thing a test has to hold, because reading either file finds nothing wrong.
 */
class HealthDoesNotRecurseTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $t = Tenant::create([
            'name' => 'Acme', 'slug' => 'acme', 'subdomain' => 'acme',
            'plan' => 'professional', 'status' => 'active',
        ]);
        $this->client = Client::create([
            'tenant_id' => $t->id, 'company' => 'Widget Ltd', 'active' => true,
        ]);
    }

    /** Feedback present is what makes healthSignal() do its work rather than bail early. */
    private function withFeedback(): void
    {
        foreach ([['CSAT', 4], ['NPS', 9]] as [$metric, $score]) {
            ClientFeedback::create([
                'tenant_id' => $this->client->tenant_id,
                'client_id' => $this->client->id,
                'metric' => $metric, 'score' => $score,
                'collected_via' => 'portal', 'responded_at' => now(),
            ]);
        }
    }

    public function test_health_score_returns_instead_of_recursing_forever(): void
    {
        $this->withFeedback();

        $started = microtime(true);
        $score = app(CustomerHealthService::class)->score($this->client);
        $elapsed = microtime(true) - $started;

        $this->assertIsArray($score);
        $this->assertArrayHasKey('score', $score);
        $this->assertArrayHasKey('breakdown', $score);

        // The recursive version never got here — it exhausted memory first. The
        // bound is deliberately loose: this asserts "terminates", not "is fast".
        $this->assertLessThan(5.0, $elapsed, 'health score should not take seconds on one client');
    }

    public function test_the_experience_summary_also_returns(): void
    {
        $this->withFeedback();

        // The other entry point into the same cycle.
        $summary = app(CustomerExperienceService::class)->forClient($this->client);

        $this->assertIsArray($summary);
        $this->assertArrayHasKey('service_quality', $summary);
    }

    public function test_the_feedback_signal_still_reports_csat_and_nps(): void
    {
        $this->withFeedback();

        // Breaking the cycle must not have broken what the signal is FOR.
        $signal = app(CustomerExperienceService::class)->healthSignal($this->client);

        $this->assertIsArray($signal);
        $this->assertArrayHasKey('score', $signal);
        $this->assertStringContainsString('CSAT', $signal['detail']);
        $this->assertStringContainsString('NPS', $signal['detail']);
    }

    public function test_no_feedback_still_yields_null_rather_than_a_score(): void
    {
        // A customer who has never been asked has no feedback signal. Scoring
        // that as anything would invent an opinion they never gave.
        $this->assertNull(app(CustomerExperienceService::class)->healthSignal($this->client));
    }

    public function test_the_whole_overview_assembles(): void
    {
        $this->withFeedback();

        $overview = app(\App\Services\Customer\Customer360Service::class)->overview($this->client);

        foreach (['kpis', 'alerts', 'recent', 'health', 'risk'] as $key) {
            $this->assertArrayHasKey($key, $overview, "overview is missing {$key}");
        }
    }
}
