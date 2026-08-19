<?php

namespace Database\Seeders;

use App\Models\Inventory\Asset;
use App\Models\Inventory\Batch;
use App\Models\Inventory\Bom;
use App\Models\Inventory\BomLine;
use App\Models\Inventory\BuildOrder;
use App\Models\Inventory\Category;
use App\Models\Inventory\CountLine;
use App\Models\Inventory\CountSession;
use App\Models\Inventory\Movement;
use App\Models\Inventory\Product;
use App\Models\Inventory\PurchaseOrder;
use App\Models\Inventory\PurchaseOrderLine;
use App\Models\Inventory\Rental;
use App\Models\Inventory\Serial;
use App\Models\Inventory\Stock;
use App\Models\Inventory\Transfer;
use App\Models\Inventory\TransferLine;
use App\Models\Inventory\Unit;
use App\Models\Inventory\Vendor;
use App\Models\Inventory\VmiAgreement;
use App\Models\Inventory\VmiItem;
use App\Models\Inventory\Voucher;
use App\Models\Inventory\VoucherItem;
use App\Models\Inventory\Warehouse;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Realistic Inventory demo data (owner: Shivam) — full module coverage.
 *
 * Why this exists: the SQLite DB is gitignored, so a teammate who pulls master
 * starts with empty tables and every Inventory screen renders blank. This
 * seeder reproduces a working dataset across the WHOLE module so `migrate
 * --seed` lights up every sub-screen: catalog, stock, batches, serials,
 * purchase orders, receiving/delivery/adjustment vouchers, consignment
 * transfers, assets, rentals, manufacturing (BOM + build), physical counts and
 * vendor-managed inventory.
 *
 * Direct inserts, not the service layer, so seeding never fires low-stock
 * emails or GPS/photo compliance checks. Opening stock is written as Movement
 * (type 'opening') + Stock so the ledger, history and analytics screens have a
 * baseline. Downstream documents (POs, vouchers, transfers, builds, counts) are
 * seeded as display records in representative states; they are demo data, not a
 * unit-accurate ledger reconciliation.
 *
 * Idempotent: skips if products already exist, so `migrate --seed` on an
 * existing DB never duplicates the catalog.
 */
class InventorySeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::first();
        if (! $tenant) {
            $this->command->warn('InventorySeeder: no tenant found — run the main seeder first.');
            return;
        }
        $tid = $tenant->id;

        if (Product::where('tenant_id', $tid)->exists()) {
            $this->command->info('InventorySeeder: products already present — skipping.');
            return;
        }

        $admin = User::where('tenant_id', $tid)->where('role', 'admin')->first();
        $actor = $admin?->id;

        DB::transaction(function () use ($tid, $actor) {
            // ── Units ─────────────────────────────────────────────────────────
            $units = [];
            foreach ([['Pieces', 'PCS'], ['Box', 'BOX'], ['Kilogram', 'KG'], ['Litre', 'LTR'], ['Metre', 'MTR']] as $i => [$n, $s]) {
                $units[$s] = Unit::create(['tenant_id' => $tid, 'name' => $n, 'short_name' => $s, 'order' => $i + 1]);
            }

            // ── Categories ────────────────────────────────────────────────────
            $cats = [];
            // 'PPE' is a first-class category: the TPV PPE matrix / issuance flow
            // treats every product under a category named "PPE" as issuable safety
            // gear. Without it that catalogue is empty and the picker has no options.
            foreach (['Electronics', 'Office Supplies', 'Raw Materials', 'Packaging', 'Tools', 'Finished Goods', 'PPE'] as $i => $n) {
                $cats[$n] = Category::create(['tenant_id' => $tid, 'name' => $n, 'order' => $i + 1, 'description' => "$n inventory items"]);
            }

            // ── Warehouses ────────────────────────────────────────────────────
            $main = Warehouse::create(['tenant_id' => $tid, 'name' => 'Main Warehouse', 'code' => 'WH-MAIN', 'type' => 'warehouse', 'city' => 'Pune', 'state' => 'Maharashtra', 'country' => 'India', 'is_default' => true, 'display' => true, 'status' => 'active', 'order' => 1]);
            $store = Warehouse::create(['tenant_id' => $tid, 'name' => 'Secondary Store', 'code' => 'WH-STORE', 'type' => 'store', 'city' => 'Mumbai', 'state' => 'Maharashtra', 'country' => 'India', 'is_default' => false, 'display' => true, 'status' => 'active', 'order' => 2]);

            // ── Vendors ───────────────────────────────────────────────────────
            $vendors = [];
            foreach ([
                ['Alpha Components Pvt Ltd', 'VN-ALPHA', 'sales@alphacomp.example', 7],
                ['Bharat Office Depot', 'VN-BHARAT', 'orders@bharatoffice.example', 3],
                ['Metro Raw Supplies', 'VN-METRO', 'supply@metroraw.example', 10],
            ] as [$n, $c, $e, $lead]) {
                $vendors[] = Vendor::create(['tenant_id' => $tid, 'name' => $n, 'code' => $c, 'email' => $e, 'city' => 'Pune', 'state' => 'Maharashtra', 'country' => 'India', 'payment_terms' => 'Net 30', 'lead_time_days' => $lead, 'status' => 'active', 'created_by' => $actor]);
            }

            // ── Products + opening stock/ledger ────────────────────────────────
            // [sku, name, category, unit, cost, sale, reorder, hsn, gst, qMain, qStore, batch, serial]
            $rows = [
                ['ELE-1001', 'USB-C Cable 1m', 'Electronics', 'PCS', 45, 99, 50, '8544', 18, 320, 80, false, false],
                ['ELE-1002', 'Wireless Mouse', 'Electronics', 'PCS', 240, 499, 30, '8471', 18, 140, 40, false, true],
                ['ELE-1003', 'HDMI Adapter', 'Electronics', 'PCS', 130, 299, 25, '8544', 18, 18, 0, false, false],   // LOW
                ['OFF-2001', 'A4 Paper Ream (500)', 'Office Supplies', 'BOX', 210, 320, 40, '4802', 12, 260, 60, false, false],
                ['OFF-2002', 'Ballpoint Pen (Blue)', 'Office Supplies', 'BOX', 55, 120, 60, '9608', 12, 500, 120, false, false],
                ['OFF-2003', 'Sticky Notes Pad', 'Office Supplies', 'PCS', 25, 60, 80, '4820', 12, 45, 0, false, false],  // LOW
                ['RAW-3001', 'Aluminium Sheet 2mm', 'Raw Materials', 'KG', 180, 260, 100, '7606', 18, 640, 150, true, false],
                ['RAW-3002', 'Copper Wire Spool', 'Raw Materials', 'MTR', 32, 58, 200, '7408', 18, 900, 0, true, false],
                ['PKG-4001', 'Corrugated Box (Medium)', 'Packaging', 'PCS', 18, 40, 150, '4819', 18, 1200, 300, false, false],
                ['PKG-4002', 'Bubble Wrap Roll', 'Packaging', 'MTR', 12, 28, 120, '3923', 18, 700, 0, false, false],
                ['TLS-5001', 'Screwdriver Set', 'Tools', 'BOX', 350, 650, 15, '8205', 18, 60, 20, false, false],
                ['TLS-5002', 'Measuring Tape 5m', 'Tools', 'PCS', 90, 180, 30, '9017', 18, 110, 25, false, false],
                ['FG-6001', 'Assembled Cable Kit', 'Finished Goods', 'BOX', 400, 799, 20, '8544', 18, 35, 0, false, false],
                // ── PPE (issuable safety gear for the TPV workforce flow) ──────
                ['PPE-7001', 'Safety Helmet', 'PPE', 'PCS', 180, 320, 50, '6506', 18, 200, 40, false, false],
                ['PPE-7002', 'Safety Gloves (Pair)', 'PPE', 'PCS', 60, 120, 100, '6116', 18, 400, 80, false, false],
                ['PPE-7003', 'Safety Boots (Pair)', 'PPE', 'PCS', 650, 1100, 40, '6403', 18, 150, 30, false, false],
                ['PPE-7004', 'Hi-Vis Safety Vest', 'PPE', 'PCS', 120, 240, 60, '6211', 18, 250, 50, false, false],
                ['PPE-7005', 'Safety Goggles', 'PPE', 'PCS', 90, 180, 60, '9004', 18, 180, 30, false, false],
                ['PPE-7006', 'Ear Plugs (Pair)', 'PPE', 'PCS', 15, 40, 120, '3926', 18, 500, 100, false, false],
            ];

            $p = [];  // sku => product
            foreach ($rows as [$sku, $name, $cat, $unit, $cost, $sale, $ro, $hsn, $gst, $qM, $qS, $tb, $ts]) {
                $prod = Product::create([
                    'tenant_id' => $tid, 'sku' => $sku, 'sku_code' => $sku, 'name' => $name, 'sku_name' => $name,
                    'description' => $name.' — demo catalog item', 'category_id' => $cats[$cat]->id,
                    'unit_id' => $units[$unit]->id, 'base_unit' => $unit, 'cost_price' => $cost, 'sale_price' => $sale,
                    'min_stock' => $ro, 'reorder_point' => $ro, 'hsn' => $hsn, 'gst_rate' => $gst,
                    'track_batch' => $tb, 'track_serial' => $ts, 'shelf_life_days' => $tb ? 365 : null,
                    'status' => 'active', 'created_by' => $actor,
                ]);
                $p[$sku] = $prod;

                $run = 0.0;
                foreach ([[$main->id, $qM], [$store->id, $qS]] as [$wh, $qty]) {
                    if ($qty <= 0) continue;
                    Stock::create(['tenant_id' => $tid, 'product_id' => $prod->id, 'warehouse_id' => $wh, 'quantity' => $qty, 'reserved_quantity' => 0]);
                    $run += $qty;
                    Movement::create(['tenant_id' => $tid, 'product_id' => $prod->id, 'type' => 'opening', 'direction' => 'in', 'quantity' => $qty, 'to_warehouse_id' => $wh, 'balance_after' => $run, 'reason' => 'Opening balance', 'notes' => 'Seeded opening stock', 'reference_type' => 'seed', 'actor_id' => $actor]);
                }
            }

            // ── Batches (for batch-tracked products) + expiry spread ───────────
            $b = [];
            foreach ([['RAW-3001', 'B-AL-2601', 640, 300], ['RAW-3002', 'B-CU-2602', 900, 120]] as $i => [$sku, $bn, $qty, $daysToExpiry]) {
                $b[$sku] = Batch::create([
                    'tenant_id' => $tid, 'product_id' => $p[$sku]->id, 'warehouse_id' => $main->id,
                    'batch_no' => $bn, 'lot_number' => $bn, 'manufactured_at' => Carbon::now()->subDays(30),
                    'expiry_date' => Carbon::now()->addDays($daysToExpiry), 'received_qty' => $qty, 'remaining_qty' => $qty,
                    'cost_price' => $p[$sku]->cost_price, 'quality_status' => 'passed', 'created_by' => $actor,
                ]);
            }

            // ── Serials (for serial-tracked product) ───────────────────────────
            for ($i = 1; $i <= 6; $i++) {
                Serial::create([
                    'tenant_id' => $tid, 'product_id' => $p['ELE-1002']->id, 'warehouse_id' => $main->id,
                    'serial_no' => 'SN-MOUSE-'.str_pad($i, 4, '0', STR_PAD_LEFT),
                    'status' => $i <= 5 ? 'in_stock' : 'issued',
                    'warranty_until' => Carbon::now()->addYear(), 'created_by' => $actor,
                ]);
            }

            // ── Purchase Orders (draft / sent / approved) + lines ──────────────
            $poDefs = [
                ['PO-000001', 0, 'draft',    [['ELE-1003', 100, 130], ['ELE-1001', 200, 45]]],
                ['PO-000002', 1, 'sent',     [['OFF-2003', 150, 25], ['OFF-2002', 100, 55]]],
                ['PO-000003', 2, 'approved', [['RAW-3001', 500, 180], ['RAW-3002', 800, 32]]],
            ];
            foreach ($poDefs as [$code, $vIdx, $status, $lines]) {
                $sub = 0; $taxT = 0;
                $po = PurchaseOrder::create([
                    'tenant_id' => $tid, 'code' => $code, 'vendor_id' => $vendors[$vIdx]->id, 'warehouse_id' => $main->id,
                    'status' => $status, 'source' => 'manual', 'currency' => 'INR',
                    'order_date' => Carbon::now()->subDays(5), 'expected_date' => Carbon::now()->addDays(5),
                    'subtotal' => 0, 'tax_total' => 0, 'total' => 0,
                    'created_by' => $actor,
                    'approved_by' => $status === 'approved' ? $actor : null,
                    'approved_at' => $status === 'approved' ? Carbon::now()->subDays(2) : null,
                    'sent_at' => in_array($status, ['sent', 'approved']) ? Carbon::now()->subDays(3) : null,
                ]);
                foreach ($lines as [$sku, $qty, $price]) {
                    $lineTotal = $qty * $price;
                    $tax = round($lineTotal * ($p[$sku]->gst_rate / 100), 2);
                    $sub += $lineTotal; $taxT += $tax;
                    PurchaseOrderLine::create(['tenant_id' => $tid, 'purchase_order_id' => $po->id, 'product_id' => $p[$sku]->id, 'description' => $p[$sku]->name, 'qty' => $qty, 'received_qty' => 0, 'unit_price' => $price, 'tax_rate' => $p[$sku]->gst_rate, 'line_total' => $lineTotal]);
                }
                $po->update(['subtotal' => $sub, 'tax_total' => $taxT, 'total' => $sub + $taxT]);
            }

            // ── Vouchers (receipt / delivery / internal / loss_adjustment) ─────
            $voucherDefs = [
                ['receipt', 'RCV-000001', 'approved', $main->id, 'Alpha Components Pvt Ltd', [['ELE-1001', 200, 45], ['ELE-1002', 40, 240]]],
                ['receipt', 'RCV-000002', 'draft', $main->id, 'Metro Raw Supplies', [['RAW-3001', 300, 180]]],
                ['delivery', 'DLV-000001', 'approved', $main->id, 'Acme Corp', [['PKG-4001', 150, 40], ['PKG-4002', 80, 28]]],
                ['delivery', 'DLV-000002', 'draft', $store->id, 'Beta Traders', [['OFF-2001', 30, 320]]],
                ['internal', 'INT-000001', 'approved', $main->id, null, [['TLS-5001', 10, 350]]],
                ['loss_adjustment', 'ADJ-000001', 'approved', $main->id, null, [['OFF-2003', 5, 25]]],
            ];
            foreach ($voucherDefs as [$type, $code, $status, $wh, $party, $items]) {
                $goods = 0; $tax = 0;
                $v = Voucher::create([
                    'tenant_id' => $tid, 'type' => $type, 'code' => $code, 'status' => $status,
                    'date_c' => Carbon::now()->subDays(4), 'date_add' => Carbon::now()->subDays(4),
                    'description' => Voucher::TYPES[$type][0], 'warehouse_id' => $wh,
                    'supplier_name' => $type === 'receipt' ? $party : null,
                    'customer_name' => $type === 'delivery' ? $party : null,
                    'reason' => $type === 'loss_adjustment' ? 'Damaged in storage' : null,
                    'total_goods' => 0, 'total_tax' => 0, 'total_discount' => 0, 'total_amount' => 0,
                    'created_by' => $actor,
                ]);
                foreach ($items as [$sku, $qty, $price]) {
                    $amount = $qty * $price;
                    $lineTax = round($amount * ($p[$sku]->gst_rate / 100), 2);
                    $goods += $amount; $tax += $lineTax;
                    VoucherItem::create(['tenant_id' => $tid, 'voucher_id' => $v->id, 'product_id' => $p[$sku]->id, 'warehouse_id' => $wh, 'quantity' => $qty, 'unit_price' => $price, 'tax_rate' => $p[$sku]->gst_rate, 'discount' => 0, 'amount' => $amount]);
                }
                $v->update(['total_goods' => $goods, 'total_tax' => $tax, 'total_amount' => $goods + $tax, 'inventory_value' => $goods]);
            }

            // ── Consignment transfers (in_transit / received) + lines ──────────
            $trDefs = [
                ['TR-000001', $main->id, $store->id, 'in_transit', [['ELE-1001', 50], ['PKG-4001', 100]]],
                ['TR-000002', $store->id, $main->id, 'received', [['OFF-2001', 20]]],
            ];
            foreach ($trDefs as [$code, $from, $to, $status, $lines]) {
                $tr = Transfer::create([
                    'tenant_id' => $tid, 'code' => $code, 'from_warehouse_id' => $from, 'to_warehouse_id' => $to,
                    'status' => $status, 'carrier' => 'BlueDart', 'vehicle_no' => 'MH12-AB-'.rand(1000, 9999),
                    'dispatched_at' => Carbon::now()->subDays(3), 'dispatched_by' => $actor,
                    'expected_at' => Carbon::now()->addDays(1),
                    'received_at' => $status === 'received' ? Carbon::now()->subDay() : null,
                    'received_by' => $status === 'received' ? $actor : null,
                    'created_by' => $actor,
                ]);
                foreach ($lines as [$sku, $qty]) {
                    TransferLine::create(['tenant_id' => $tid, 'transfer_id' => $tr->id, 'product_id' => $p[$sku]->id, 'dispatched_qty' => $qty, 'received_qty' => $status === 'received' ? $qty : 0, 'lost_qty' => 0, 'status' => $status === 'received' ? 'received' : 'in_transit']);
                }
            }

            // ── Assets ─────────────────────────────────────────────────────────
            $assetDefs = [
                ['AST-0001', 'Forklift #1', 'Machinery', 'in_service', $main->id, 850000],
                ['AST-0002', 'Barcode Scanner Gun', 'Equipment', 'in_service', $main->id, 12000],
                ['AST-0003', 'Delivery Van (MH12-XY-0099)', 'Vehicle', 'maintenance', $main->id, 1450000],
                ['AST-0004', 'Pallet Jack', 'Equipment', 'idle', $store->id, 35000],
            ];
            foreach ($assetDefs as [$code, $name, $category, $status, $wh, $cost]) {
                Asset::create(['tenant_id' => $tid, 'code' => $code, 'name' => $name, 'category' => $category, 'status' => $status, 'warehouse_id' => $wh, 'purchase_date' => Carbon::now()->subMonths(8), 'purchase_cost' => $cost, 'warranty_until' => Carbon::now()->addYear(), 'next_service_due' => Carbon::now()->addMonths(2), 'created_by' => $actor]);
            }

            // ── Rentals (out / reserved / overdue) ─────────────────────────────
            $rentalDefs = [
                ['RNT-0001', 'Rajesh Constructions', 'TLS-5001', 'out', 3, 200, 'day', Carbon::now()->subDays(4), Carbon::now()->addDays(3)],
                ['RNT-0002', 'Sharma Interiors', 'TLS-5002', 'overdue', 2, 150, 'day', Carbon::now()->subDays(10), Carbon::now()->subDays(2)],
                ['RNT-0003', 'Patil Events', 'ELE-1002', 'reserved', 5, 100, 'day', Carbon::now()->addDays(2), Carbon::now()->addDays(7)],
            ];
            foreach ($rentalDefs as [$code, $cust, $sku, $status, $qty, $rate, $period, $out, $due]) {
                Rental::create(['tenant_id' => $tid, 'code' => $code, 'customer_name' => $cust, 'customer_contact' => '98'.rand(10000000, 99999999), 'product_id' => $p[$sku]->id, 'item_label' => $p[$sku]->name, 'warehouse_id' => $main->id, 'qty' => $qty, 'rate' => $rate, 'rate_period' => $period, 'deposit' => $rate * $qty * 2, 'status' => $status, 'out_date' => $out, 'due_date' => $due, 'created_by' => $actor]);
            }

            // ── Manufacturing: a BOM + build order ─────────────────────────────
            $bom = Bom::create(['tenant_id' => $tid, 'product_id' => $p['FG-6001']->id, 'name' => 'Assembled Cable Kit — BOM v1', 'output_qty' => 1, 'status' => 'active', 'note' => '1 kit = 2 cables + 1 adapter + 1 box', 'created_by' => $actor]);
            foreach ([['ELE-1001', 2], ['ELE-1003', 1], ['PKG-4001', 1]] as [$sku, $qty]) {
                BomLine::create(['tenant_id' => $tid, 'bom_id' => $bom->id, 'component_id' => $p[$sku]->id, 'qty' => $qty]);
            }
            BuildOrder::create(['tenant_id' => $tid, 'code' => 'BLD-0001', 'bom_id' => $bom->id, 'product_id' => $p['FG-6001']->id, 'warehouse_id' => $main->id, 'qty' => 20, 'status' => 'in_progress', 'note' => 'Weekly assembly run', 'created_by' => $actor]);

            // ── Physical count session + lines (with a variance) ───────────────
            $count = CountSession::create(['tenant_id' => $tid, 'code' => 'CNT-0001', 'name' => 'Monthly cycle count — Main', 'warehouse_id' => $main->id, 'scope' => 'full', 'status' => 'counting', 'blind' => false, 'assigned_to' => $actor, 'created_by' => $actor, 'started_at' => Carbon::now()->subDay()]);
            foreach ([['ELE-1001', 320, 318], ['OFF-2002', 500, 500], ['PKG-4001', 1200, 1195]] as [$sku, $sys, $counted]) {
                CountLine::create(['tenant_id' => $tid, 'count_session_id' => $count->id, 'product_id' => $p[$sku]->id, 'system_qty' => $sys, 'system_at_count' => $sys, 'counted_qty' => $counted, 'variance' => $counted - $sys, 'status' => 'counted', 'counted_by' => $actor, 'counted_at' => Carbon::now()->subHours(2)]);
            }

            // ── Vendor-managed inventory (VMI) agreement + items ───────────────
            $vmi = VmiAgreement::create(['tenant_id' => $tid, 'vendor_id' => $vendors[1]->id, 'warehouse_id' => $main->id, 'name' => 'Office Supplies VMI — Bharat', 'status' => 'active', 'review_frequency' => 'weekly', 'note' => 'Vendor replenishes to max on weekly review', 'created_by' => $actor]);
            foreach ([['OFF-2001', 40, 300], ['OFF-2002', 60, 600], ['OFF-2003', 80, 400]] as [$sku, $min, $max]) {
                VmiItem::create(['tenant_id' => $tid, 'agreement_id' => $vmi->id, 'product_id' => $p[$sku]->id, 'min_level' => $min, 'max_level' => $max]);
            }
        });

        $this->command->info('✅ Inventory seeded: '
            .Product::where('tenant_id', $tid)->count().' products, '
            .PurchaseOrder::where('tenant_id', $tid)->count().' POs, '
            .Voucher::where('tenant_id', $tid)->count().' vouchers, '
            .Transfer::where('tenant_id', $tid)->count().' transfers, '
            .Batch::where('tenant_id', $tid)->count().' batches, '
            .Serial::where('tenant_id', $tid)->count().' serials, '
            .Asset::where('tenant_id', $tid)->count().' assets, '
            .Rental::where('tenant_id', $tid)->count().' rentals, '
            .Bom::where('tenant_id', $tid)->count().' BOM, '
            .CountSession::where('tenant_id', $tid)->count().' count, '
            .VmiAgreement::where('tenant_id', $tid)->count().' VMI.');
    }
}
