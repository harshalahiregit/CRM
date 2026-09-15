<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\Transport\TransportConsignment;
use App\Models\Transport\TransportOrder;
use App\Models\Transport\TransportTrip;
use App\Models\User;
use App\Services\Transport\ConsignmentService;
use App\Support\Transport\OrderStatus;
use App\Support\Transport\TripStatus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Demo data for the Consignments walkthrough.
 *
 * Its own file, NOT DatabaseSeeder — that one is shared by everybody and a
 * transport demo appearing in a colleague's fresh install is somebody else's
 * problem to explain.
 *
 * ── SAFE TO RUN TWICE ────────────────────────────────────────────────────
 * Every row is keyed on a DEMO- prefix and looked up before it is created, so
 * running this again tops the set up rather than duplicating it. It writes
 * nothing outside transport_orders, transport_consignments and transport_trips,
 * and it touches no other tenant than the one it resolves.
 *
 * ── IT GOES THROUGH THE SERVICE, NOT THE MODEL ───────────────────────────
 * Consignments are created via ConsignmentService so the demo exercises the
 * real numbering, the real audit trail and the real refusals. A seeder that
 * wrote rows directly would produce data the application could not have made.
 *
 * Run:  php artisan db:seed --class=TransportConsignmentDemoSeeder
 */
class TransportConsignmentDemoSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::query()->orderBy('id')->first();

        if (! $tenant) {
            $this->command?->warn('No tenant found — run the main seeder first.');

            return;
        }

        $tenantId = (int) $tenant->id;
        $actor    = User::where('tenant_id', $tenantId)->orderBy('id')->first();
        $service  = app(ConsignmentService::class);

        $customerId = (int) (TransportOrder::where('tenant_id', $tenantId)->value('customer_id') ?? 1);

        $orders = [
            ['DEMO-CNM-A', 'Container Haulage', 'JNPT Terminal, Navi Mumbai', 'Bhiwandi Warehouse'],
            ['DEMO-CNM-B', 'Reefer Movement',   'Mundra Port, Gujarat',       'Cold Store, Pune'],
            ['DEMO-CNM-C', 'Break-bulk',        'Hazira Port, Surat',         'Aurangabad Plant'],
        ];

        $made = [];

        foreach ($orders as [$ref, $serviceType, $from, $to]) {
            $order = TransportOrder::where('tenant_id', $tenantId)
                ->where('order_number', 'like', $ref.'%')
                ->first();

            if (! $order) {
                $order = TransportOrder::create([
                    'tenant_id'         => $tenantId,
                    'order_number'      => $ref.'-'.Str::upper(Str::random(4)),
                    'customer_id'       => $customerId,
                    'pickup_location'   => ['address' => $from],
                    'delivery_location' => ['address' => $to],
                    'required_at'       => now()->addDays(random_int(2, 6)),
                    'service_type'      => $serviceType,
                    'created_by'        => $actor?->id,
                ]);
                $order->forceFill(['order_status' => OrderStatus::APPROVED])->save();
            }

            $made[$ref] = $order->fresh();
        }

        // 1. A fully described consignment — the one to open and edit.
        $this->ensure($service, $tenantId, $actor, $made['DEMO-CNM-A'], [
            'customer_reference' => 'PO-88914',
            'cargo_description'  => '2 × 40ft containers, palletised industrial spares',
            'service_type'       => 'Container Haulage',
            'package_count'      => 48,
            'gross_weight_kg'    => 21450.500,
            'volume_cbm'         => 54.200,
            'special_handling'   => 'Seal numbers to be recorded on the LR before gate-out.',
        ]);

        // 2. Break-bulk with no container — STOS-CTD §8's "other cargo references".
        $this->ensure($service, $tenantId, $actor, $made['DEMO-CNM-C'], [
            'customer_reference' => 'BK-2291',
            'cargo_description'  => 'Loose steel coils, 12 pieces, no container',
            'service_type'       => 'Break-bulk',
            'package_count'      => 12,
            'gross_weight_kg'    => 18600.000,
        ]);

        // 3. A bare consignment — proves "Not described" renders honestly.
        $this->ensure($service, $tenantId, $actor, $made['DEMO-CNM-B'], []);

        // 4. One that a trip is already carrying — this is the row whose DELETE
        //    the server refuses, which is the point of the walkthrough.
        $carried = $this->ensure($service, $tenantId, $actor, $made['DEMO-CNM-B'], [
            'customer_reference' => 'RF-5540',
            'cargo_description'  => 'Temperature-controlled pharma, 2–8 °C',
            'service_type'       => 'Reefer Movement',
            'package_count'      => 320,
            'gross_weight_kg'    => 4200.000,
        ], 'carried');

        if ($carried && $carried->trips()->count() === 0) {
            $trip = TransportTrip::create([
                'tenant_id'      => $tenantId,
                'order_id'       => $carried->order_id,
                'consignment_id' => $carried->id,
                'customer_id'    => $customerId,
                'trip_number'    => TransportTrip::nextLocalNumber($tenantId),
                'route'          => 'Mundra → Pune',
                'created_by'     => $actor?->id,
            ]);
            $trip->forceFill(['status' => TripStatus::APPROVED])->save();
        }

        $total = TransportConsignment::where('tenant_id', $tenantId)->count();

        $this->command?->info("Consignment demo ready for tenant #{$tenantId} — {$total} consignment(s).");
        $this->command?->info('Open /app/transport/consignments. The reefer one is carried by a trip; try deleting it.');
    }

    /**
     * Create a consignment through the service unless an equivalent demo row
     * already exists. Keyed on customer_reference so a re-run is a no-op.
     */
    private function ensure(
        ConsignmentService $service,
        int $tenantId,
        ?User $actor,
        TransportOrder $order,
        array $data,
        string $marker = '',
    ): ?TransportConsignment {
        $reference = $data['customer_reference'] ?? null;

        $existing = TransportConsignment::where('tenant_id', $tenantId)
            ->where('order_id', $order->id)
            ->when($reference, fn ($q) => $q->where('customer_reference', $reference))
            ->when(! $reference, fn ($q) => $q->whereNull('customer_reference'))
            ->first();

        if ($existing) {
            return $existing;
        }

        return $service->create(array_merge($data, ['order_id' => $order->id]), $tenantId, $actor);
    }
}
