<?php

namespace Database\Seeders;

use App\Models\Inventory\Category;
use App\Models\Inventory\Movement;
use App\Models\Inventory\Product;
use App\Models\Inventory\Stock;
use App\Models\Inventory\Unit;
use App\Models\Inventory\Vendor;
use App\Models\Inventory\Warehouse;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Realistic Inventory (Product Catalog + Stock) demo data (owner: Shivam).
 *
 * Why this exists: the SQLite DB is gitignored, so a teammate who pulls master
 * starts with empty tables. Without this seeder the Inventory module renders
 * blank for everyone but the machine that created the data by hand. This makes
 * `php artisan migrate --seed` reproduce a working catalog + stock ledger.
 *
 * Side-effect-free: opening stock is written directly as Movement (type
 * 'opening') + Stock rows rather than through StockService, so seeding never
 * triggers low-stock emails or GPS/photo compliance checks. A couple of items
 * are deliberately left BELOW their reorder point so the low-stock view has
 * something to show — but because we write the ledger directly, no alert fires.
 *
 * Idempotent: if products already exist for the tenant it skips, so a plain
 * `migrate --seed` on an existing DB never duplicates the catalog.
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
        $tenantId = $tenant->id;

        if (Product::where('tenant_id', $tenantId)->exists()) {
            $this->command->info('InventorySeeder: products already present — skipping.');
            return;
        }

        $admin = User::where('tenant_id', $tenantId)->where('role', 'admin')->first();
        $actorId = $admin?->id;

        DB::transaction(function () use ($tenantId, $actorId) {
            // ── Units of measure ──────────────────────────────────────────────
            $units = [];
            foreach ([
                ['Pieces', 'PCS'], ['Box', 'BOX'], ['Kilogram', 'KG'],
                ['Litre', 'LTR'], ['Metre', 'MTR'],
            ] as $i => [$name, $short]) {
                $units[$short] = Unit::create([
                    'tenant_id' => $tenantId, 'name' => $name, 'short_name' => $short, 'order' => $i + 1,
                ]);
            }

            // ── Categories ────────────────────────────────────────────────────
            $categories = [];
            foreach ([
                'Electronics', 'Office Supplies', 'Raw Materials', 'Packaging', 'Tools',
            ] as $i => $name) {
                $categories[$name] = Category::create([
                    'tenant_id' => $tenantId, 'name' => $name, 'order' => $i + 1,
                    'description' => "$name inventory items",
                ]);
            }

            // ── Warehouses ────────────────────────────────────────────────────
            $main = Warehouse::create([
                'tenant_id' => $tenantId, 'name' => 'Main Warehouse', 'code' => 'WH-MAIN',
                'type' => 'warehouse', 'city' => 'Pune', 'state' => 'Maharashtra', 'country' => 'India',
                'is_default' => true, 'display' => true, 'status' => 'active', 'order' => 1,
            ]);
            $store = Warehouse::create([
                'tenant_id' => $tenantId, 'name' => 'Secondary Store', 'code' => 'WH-STORE',
                'type' => 'store', 'city' => 'Mumbai', 'state' => 'Maharashtra', 'country' => 'India',
                'is_default' => false, 'display' => true, 'status' => 'active', 'order' => 2,
            ]);

            // ── Inventory vendors ─────────────────────────────────────────────
            foreach ([
                ['Alpha Components Pvt Ltd', 'VN-ALPHA', 'sales@alphacomp.example', 7],
                ['Bharat Office Depot',      'VN-BHARAT', 'orders@bharatoffice.example', 3],
                ['Metro Raw Supplies',       'VN-METRO', 'supply@metroraw.example', 10],
            ] as [$name, $code, $email, $lead]) {
                Vendor::create([
                    'tenant_id' => $tenantId, 'name' => $name, 'code' => $code, 'email' => $email,
                    'city' => 'Pune', 'state' => 'Maharashtra', 'country' => 'India',
                    'payment_terms' => 'Net 30', 'lead_time_days' => $lead, 'status' => 'active',
                    'created_by' => $actorId,
                ]);
            }

            // ── Products (SKU, category, unit, pricing, reorder point) ─────────
            // [sku, name, category, unit, cost, sale, reorder, hsn, gst, opening@Main, opening@Store]
            $rows = [
                ['ELE-1001', 'USB-C Cable 1m',           'Electronics',     'PCS', 45,   99,   50, '8544', 18, 320, 80],
                ['ELE-1002', 'Wireless Mouse',           'Electronics',     'PCS', 240,  499,  30, '8471', 18, 140, 40],
                ['ELE-1003', 'HDMI Adapter',             'Electronics',     'PCS', 130,  299,  25, '8544', 18, 18,  0],  // LOW (below reorder)
                ['OFF-2001', 'A4 Paper Ream (500)',      'Office Supplies', 'BOX', 210,  320,  40, '4802', 12, 260, 60],
                ['OFF-2002', 'Ballpoint Pen (Blue)',     'Office Supplies', 'BOX', 55,   120,  60, '9608', 12, 500, 120],
                ['OFF-2003', 'Sticky Notes Pad',         'Office Supplies', 'PCS', 25,   60,   80, '4820', 12, 45,  0],  // LOW (below reorder)
                ['RAW-3001', 'Aluminium Sheet 2mm',      'Raw Materials',   'KG',  180,  260,  100,'7606', 18, 640, 150],
                ['RAW-3002', 'Copper Wire Spool',        'Raw Materials',   'MTR', 32,   58,   200,'7408', 18, 900, 0],
                ['PKG-4001', 'Corrugated Box (Medium)',  'Packaging',       'PCS', 18,   40,   150,'4819', 18, 1200,300],
                ['PKG-4002', 'Bubble Wrap Roll',         'Packaging',       'MTR', 12,   28,   120,'3923', 18, 700, 0],
                ['TLS-5001', 'Screwdriver Set',          'Tools',           'BOX', 350,  650,  15, '8205', 18, 60,  20],
                ['TLS-5002', 'Measuring Tape 5m',        'Tools',           'PCS', 90,   180,  30, '9017', 18, 110, 25],
            ];

            foreach ($rows as [$sku, $name, $cat, $unit, $cost, $sale, $reorder, $hsn, $gst, $qMain, $qStore]) {
                $product = Product::create([
                    'tenant_id'    => $tenantId,
                    'sku'          => $sku,   'sku_code' => $sku,
                    'name'         => $name,  'sku_name' => $name,
                    'description'  => $name.' — demo catalog item',
                    'category_id'  => $categories[$cat]->id,
                    'unit_id'      => $units[$unit]->id,
                    'base_unit'    => $unit,
                    'cost_price'   => $cost,
                    'sale_price'   => $sale,
                    'min_stock'    => $reorder,
                    'reorder_point' => $reorder,
                    'hsn'          => $hsn,
                    'gst_rate'     => $gst,
                    'status'       => 'active',
                    'created_by'   => $actorId,
                ]);

                $running = 0.0;
                foreach ([[$main->id, $qMain], [$store->id, $qStore]] as [$whId, $qty]) {
                    if ($qty <= 0) {
                        continue;
                    }
                    Stock::create([
                        'tenant_id' => $tenantId, 'product_id' => $product->id,
                        'warehouse_id' => $whId, 'quantity' => $qty, 'reserved_quantity' => 0,
                    ]);
                    $running += $qty;
                    Movement::create([
                        'tenant_id'      => $tenantId,
                        'product_id'     => $product->id,
                        'type'           => 'opening',
                        'direction'      => 'in',
                        'quantity'       => $qty,
                        'to_warehouse_id' => $whId,
                        'balance_after'  => $running,
                        'reason'         => 'Opening balance',
                        'notes'          => 'Seeded opening stock',
                        'reference_type' => 'seed',
                        'actor_id'       => $actorId,
                    ]);
                }
            }
        });

        $count = Product::where('tenant_id', $tenantId)->count();
        $this->command->info("✅ Inventory seeded: {$count} products across 5 categories, 2 warehouses, 3 vendors, with opening stock + ledger.");
    }
}
