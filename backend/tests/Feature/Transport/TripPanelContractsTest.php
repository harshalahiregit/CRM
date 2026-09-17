<?php

namespace Tests\Feature\Transport;

use App\Models\Tenant;
use App\Models\Transport\TransportOrder;
use App\Models\Transport\TransportTrip;
use App\Models\User;
use App\Support\Transport\TripStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The shapes the trip-detail panels destructure.
 *
 * AdvancesPanel, CostsPanel and TripDocumentsPanel each read specific keys out
 * of their endpoint and render money and verdicts from them. Rename a key on the
 * server and nothing fails — the panel quietly shows "—" where a figure used to
 * be, or an empty table on a trip that has rows. That is the failure this file
 * exists to make loud.
 *
 * Each test asserts the CONTRACT, not the values: the keys the panel reaches
 * for, and that server-computed totals arrive as strings rather than floats.
 * The strings matter — these are bcmath sums of a DECIMAL column, and a float
 * crossing the wire is the drift Step 13's FIN-06 blocks a release for.
 */
class TripPanelContractsTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'Alpha', 'slug' => 'alpha',
            'subdomain' => 'alpha', 'status' => 'active',
        ])->save();

        Sanctum::actingAs(User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'admin-'.Str::random(6).'@test.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]));
    }

    private function trip(?string $status = null): TransportTrip
    {
        $order = TransportOrder::create([
            'tenant_id' => self::TENANT, 'order_number' => 'TO-'.Str::random(8), 'customer_id' => 1,
            'pickup_location' => ['address' => 'JNPT'], 'delivery_location' => ['address' => 'Bhiwandi'],
            'required_at' => now()->addDays(2), 'service_type' => 'Container Haulage',
        ]);

        $trip = TransportTrip::create([
            'tenant_id' => self::TENANT, 'order_id' => $order->id,
            'trip_number' => 'TRP-'.Str::random(8), 'customer_id' => 1,
        ]);
        $trip->forceFill([
            'status' => $status ?? TripStatus::APPROVED, 'approved_freight' => '100000.00',
        ])->save();

        return $trip->fresh();
    }

    /** AdvancesPanel reads exposure.limit / .committed / .remaining / .currency. */
    public function test_the_advances_endpoint_returns_the_exposure_shape_the_panel_reads(): void
    {
        $trip = $this->trip();

        $res = $this->getJson("/api/transport/trips/{$trip->id}/advances")->assertOk();

        $res->assertJsonStructure(['data' => [
            'advances',
            'exposure' => ['limit', 'committed', 'remaining', 'currency'],
        ]]);

        $exposure = $res->json('data.exposure');

        foreach (['limit', 'committed', 'remaining'] as $key) {
            $this->assertIsString($exposure[$key], "exposure.$key must be a string, not a float");
            $this->assertMatchesRegularExpression('/^-?\d+\.\d{2}$/', $exposure[$key]);
        }
    }

    /** CostsPanel reads total, breakdown and known_types. */
    public function test_the_costs_endpoint_returns_the_shape_the_panel_reads(): void
    {
        $trip = $this->trip();

        $this->postJson("/api/transport/trips/{$trip->id}/costs", [
            'cost_type' => 'fuel', 'amount' => '500.00',
        ])->assertStatus(201);

        $res = $this->getJson("/api/transport/trips/{$trip->id}/costs")->assertOk();

        $res->assertJsonStructure(['data' => ['costs', 'total', 'breakdown', 'currency', 'known_types']]);

        $this->assertIsString($res->json('data.total'), 'total must be a string, not a float');
        $this->assertSame('500.00', $res->json('data.total'));
        $this->assertSame('500.00', $res->json('data.breakdown.fuel'));
        $this->assertNotEmpty($res->json('data.known_types'), 'the picker needs its suggestions');

        // The row keys the table renders.
        $row = $res->json('data.costs.0');
        foreach (['id', 'cost_type', 'cost_type_label', 'amount', 'source'] as $key) {
            $this->assertArrayHasKey($key, $row, "costs[].$key is rendered by CostsPanel");
        }
    }

    /** TripDocumentsPanel leads with billing.billable and billing.reason. */
    public function test_the_documents_endpoint_returns_the_billing_verdict_the_panel_leads_with(): void
    {
        $trip = $this->trip();

        $res = $this->getJson("/api/transport/trips/{$trip->id}/documents")->assertOk();

        $res->assertJsonStructure(['data' => [
            'documents',
            'billing' => ['billable', 'reason', 'has_verified_pod', 'waived'],
        ]]);

        // A trip with no POD must say so rather than defaulting to billable —
        // the panel renders this boolean straight into a green tick.
        $this->assertFalse($res->json('data.billing.billable'));
        $this->assertIsString($res->json('data.billing.reason'));
        $this->assertNotSame('', $res->json('data.billing.reason'),
            'the panel shows this sentence; an empty one leaves a blank banner');
    }

    /**
     * Every permission key the panels gate their buttons on must be answerable.
     *
     * The panels read `grants['transport.cost.record']` and friends. A key that
     * the capability endpoint never emits silently hides the button forever,
     * which looks exactly like "the feature was never built".
     */
    public function test_the_capability_endpoint_answers_for_every_key_the_panels_use(): void
    {
        $grants = $this->getJson('/api/transport/permissions')->assertOk()->json('data.grants');

        foreach ([
            'transport.advance.request', 'transport.advance.approve',
            'transport.cost.record', 'transport.cost.retract',
            'transport.pod.submit', 'transport.pod.verify',
        ] as $key) {
            $this->assertArrayHasKey($key, $grants, "$key gates a button in the trip panels");
        }
    }
}
