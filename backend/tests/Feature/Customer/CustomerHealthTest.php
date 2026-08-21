<?php

namespace Tests\Feature\Customer;

use App\Models\Customer\Client;
use App\Models\Customer\ClientContract;
use App\Models\Sales\SalesInvoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Customer\CustomerHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The scoring rule that matters: the final figure is the weighted average of
 * ONLY the parameters that had data, so with two measurable they are worth 50
 * each and with four, 25 each. Silence must never read as excellence.
 */
class CustomerHealthTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $t;
    private User $u;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = Tenant::create([
            'name' => 'Acme', 'slug' => 'acme', 'subdomain' => 'acme',
            'plan' => 'professional', 'status' => 'active',
        ]);
        $this->u = User::create([
            'tenant_id' => $this->t->id, 'name' => 'A', 'email' => 'a@x.test',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'active',
        ]);
    }

    private function client(array $attr = []): Client
    {
        return Client::create(array_merge([
            'tenant_id' => $this->t->id, 'company' => 'Co', 'active' => true,
        ], $attr));
    }

    private function invoice(Client $c, array $a): SalesInvoice
    {
        $inv = SalesInvoice::create(array_merge([
            'tenant_id' => $this->t->id, 'client_id' => $c->id,
            'date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(),
            'total' => 1000, 'status' => 'Unpaid', 'created_by' => $this->u->id,
        ], $a));
        // The creating hook forces balance = total; apply the real state after.
        if (array_key_exists('balance', $a)) {
            $inv->update(['balance' => $a['balance'], 'paid' => $a['paid'] ?? 0]);
        }

        return $inv;
    }

    private function svc(): CustomerHealthService
    {
        return app(CustomerHealthService::class);
    }

    /** A customer nobody has transacted with is "not enough data", never 100. */
    public function test_a_brand_new_customer_scores_nothing_rather_than_perfect(): void
    {
        $r = $this->svc()->score($this->client());

        $this->assertNull($r['score']);
        $this->assertSame('Not enough data', $r['status']);
        $this->assertSame(0, $r['measured']);
    }

    /** The core rule: two measurable parameters are worth 50 points each. */
    public function test_each_measurable_parameter_is_worth_an_equal_share_of_100(): void
    {
        $c = $this->client();
        $this->invoice($c, ['number' => 'I1', 'balance' => 0, 'paid' => 1000, 'status' => 'Paid']);
        ClientContract::create(['tenant_id' => $this->t->id, 'client_id' => $c->id,
            'subject' => 'MSA', 'status' => 'Active', 'end_date' => now()->addYear()->toDateString()]);

        $r = $this->svc()->score($c);
        $measurable = collect($r['breakdown'])->where('available', true);

        $this->assertSame(2, $r['measured'], 'payment behaviour + contract status');
        foreach ($measurable as $row) {
            $this->assertEqualsWithDelta(50.0, $row['worth'], 0.1, "{$row['key']} should be worth 50");
        }
        // Both perfect, so the whole score is perfect.
        $this->assertSame(100, $r['score']);
    }

    /** And with a third measurable, each share drops to a third. */
    public function test_the_share_shrinks_as_more_parameters_become_measurable(): void
    {
        $c = $this->client();
        $this->invoice($c, ['number' => 'I1', 'balance' => 0, 'paid' => 1000, 'status' => 'Paid']);
        ClientContract::create(['tenant_id' => $this->t->id, 'client_id' => $c->id,
            'subject' => 'MSA', 'status' => 'Active', 'end_date' => now()->addYear()->toDateString()]);
        \DB::table('tickets')->insert([
            'tenant_id' => $this->t->id, 'customer_id' => $c->id, 'subject' => 'T',
            'status' => 'closed', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $r = $this->svc()->score($c);
        $worths = collect($r['breakdown'])->where('available', true)->pluck('worth');

        $this->assertGreaterThanOrEqual(3, $r['measured']);
        $this->assertEqualsWithDelta(100.0, $worths->sum(), 0.5, 'shares must always total 100');
    }

    /** A poor parameter drags the average by exactly its share. */
    public function test_a_failing_parameter_costs_its_share(): void
    {
        $c = $this->client();
        // Both invoices overdue -> payment behaviour scores 0.
        $this->invoice($c, ['number' => 'I1', 'due_date' => now()->subDays(40)->toDateString(), 'balance' => 1000]);
        $this->invoice($c, ['number' => 'I2', 'due_date' => now()->subDays(20)->toDateString(), 'balance' => 1000]);
        ClientContract::create(['tenant_id' => $this->t->id, 'client_id' => $c->id,
            'subject' => 'MSA', 'status' => 'Active', 'end_date' => now()->addYear()->toDateString()]);

        $r = $this->svc()->score($c);

        // payment 0 (worth 50) + contract 100 (worth 50) = 50
        $this->assertSame(2, $r['measured']);
        $this->assertEqualsWithDelta(50, $r['score'], 1);
        // Band is the score's meaning; status is qualified while only two of
        // ten parameters could be measured.
        $this->assertSame('At Risk', $r['band']);
        $this->assertTrue($r['provisional']);
    }

    /** A churned customer looks perfect on every signal — lifecycle says otherwise. */
    public function test_a_churned_customer_cannot_read_as_healthy(): void
    {
        $c = $this->client(['lifecycle_status' => 'Churned']);
        $this->invoice($c, ['number' => 'I1', 'balance' => 0, 'paid' => 1000, 'status' => 'Paid']);
        ClientContract::create(['tenant_id' => $this->t->id, 'client_id' => $c->id,
            'subject' => 'MSA', 'status' => 'Active', 'end_date' => now()->addYear()->toDateString()]);

        $r = $this->svc()->score($c);

        $this->assertLessThanOrEqual(20, $r['score']);
        $this->assertSame('Critical', $r['band']);
    }

    public function test_bands_match_the_document(): void
    {
        $svc = $this->svc();
        $this->assertSame('Healthy',  $svc->band(85)['status']);
        $this->assertSame('Watch',    $svc->band(84)['status']);
        $this->assertSame('Watch',    $svc->band(70)['status']);
        $this->assertSame('At Risk',  $svc->band(69)['status']);
        $this->assertSame('At Risk',  $svc->band(50)['status']);
        $this->assertSame('Critical', $svc->band(49)['status']);
    }

    /** Payment behaviour must read against the agreed terms, not a guess. */
    public function test_unmeasurable_parameters_are_excluded_not_scored_as_full_marks(): void
    {
        $c = $this->client();
        // Only a contract — everything else has nothing to measure.
        ClientContract::create(['tenant_id' => $this->t->id, 'client_id' => $c->id,
            'subject' => 'MSA', 'status' => 'Active', 'end_date' => now()->addYear()->toDateString()]);

        $r = $this->svc()->score($c);
        $unavailable = collect($r['breakdown'])->where('available', false);

        $this->assertSame(1, $r['measured']);
        $this->assertGreaterThan(0, $unavailable->count());
        foreach ($unavailable as $row) {
            $this->assertNull($row['score'], "{$row['key']} must not be scored");
            $this->assertNull($row['worth'], "{$row['key']} must not take a share");
        }
    }

    /**
     * A score built from one signal must not present as confidently as one
     * built from nine — otherwise the least-known customer looks the healthiest.
     */
    public function test_a_score_from_too_few_signals_is_marked_provisional(): void
    {
        $c = $this->client();
        ClientContract::create(['tenant_id' => $this->t->id, 'client_id' => $c->id,
            'subject' => 'MSA', 'status' => 'Active', 'end_date' => now()->addYear()->toDateString()]);

        $r = $this->svc()->score($c);

        $this->assertSame(100, $r['score'], 'the average over what is measurable is still 100');
        $this->assertSame(1, $r['measured']);
        $this->assertTrue($r['provisional']);
        $this->assertSame('Provisional', $r['status'], 'must not claim "Healthy" off one signal');
        $this->assertSame('Healthy', $r['band'], 'the band is still available underneath');
    }

    public function test_enough_signals_gives_a_confident_status(): void
    {
        $c = $this->client();
        $this->invoice($c, ['number' => 'I1', 'balance' => 0, 'paid' => 1000, 'status' => 'Paid']);
        ClientContract::create(['tenant_id' => $this->t->id, 'client_id' => $c->id,
            'subject' => 'MSA', 'status' => 'Active', 'end_date' => now()->addYear()->toDateString()]);
        \DB::table('tickets')->insert([
            'tenant_id' => $this->t->id, 'customer_id' => $c->id, 'subject' => 'T',
            'status' => 'closed', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $r = $this->svc()->score($c);

        $this->assertGreaterThanOrEqual(3, $r['measured']);
        $this->assertFalse($r['provisional']);
        $this->assertSame($r['band'], $r['status']);
    }
}
