<?php

namespace App\Console\Commands;

use App\Models\Inventory\Product;
use App\Models\Purchase\PurchaseKickoffMeeting;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Purchase\PurchaseVendorItem;
use App\Models\Shared\KickoffMeeting;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Support\Purchase\PurchaseKickoffStatus;
use App\Support\Shared\BusinessTime;
use App\Support\Shared\KickoffStatus;
use App\Support\Vendor\VendorStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * One walkable example of the admin → vendor-portal flow, for BOTH engines.
 *
 * Deliberately ONE record of each kind, not a hundred: the point is to click
 * through a real path and see the same record from both sides, which a wall of
 * generated rows actively gets in the way of.
 *
 * Idempotent. Every record is matched on a stable natural key and reused, so
 * running this twice leaves exactly one of each rather than a second set —
 * re-run it freely to top up whatever is missing.
 *
 * Nothing here is destructive: existing vendors, meetings and items are left
 * exactly as they are.
 */
class SeedVendorWorkflowDemo extends Command
{
    protected $signature = 'demo:vendor-workflow {--tenant=1 : Tenant to seed into}
                                                 {--password=Demo@12345 : Portal password for the demo vendors}';

    protected $description = 'Create ONE example of each record in the admin → vendor portal flow (TPV + Purchase)';

    private const TPV_NAME      = 'Demo TPV Vendor';
    private const PURCHASE_NAME = 'Demo Purchase Vendor';
    private const ITEM_SKU      = 'DEMO-SKU-001';

    public function handle(): int
    {
        $tenantId = (int) $this->option('tenant');
        $password = (string) $this->option('password');

        if (! Tenant::find($tenantId)) {
            $this->error("Tenant {$tenantId} does not exist.");

            return self::FAILURE;
        }

        BusinessTime::flush();
        $this->line('');

        $tpv      = $this->tpvVendor($tenantId, $password);
        $purchase = $this->purchaseVendor($tenantId, $password);
        $item     = $this->vendorItem($tenantId, $purchase);
        $this->tpvMeeting($tenantId, $tpv);
        $this->purchaseMeeting($tenantId, $purchase);

        $this->line('');
        $this->info('Sign in at /auth/login — one page for every identity.');
        $this->table(['Portal', 'Role to pick', 'Email', 'Password'], [
            ['TPV vendor',      'Third-Party Vendor', $tpv->email,      $password],
            ['Purchase vendor', 'Purchase Vendor',    $purchase->email, $password],
        ]);

        $this->line('  Then check, on each portal:');
        $this->line('   • Dashboard → "Your meetings" shows the meeting, counting down.');
        $this->line('   • Governance → Meetings & MOM shows the same meeting with its full slot.');
        $this->line('   • Purchase only → Commercial → My Items shows "'.$item->product?->name.'".');
        $this->line('  And as an admin: TPV/Purchase → Meetings lists the same two meetings.');
        $this->line('');

        return self::SUCCESS;
    }

    /* ── TPV ─────────────────────────────────────────────────────────────── */

    private function tpvVendor(int $tenantId, string $password): Vendor
    {
        $vendor = Vendor::withTrashed()
            ->where('tenant_id', $tenantId)->where('company_name', self::TPV_NAME)->first();

        if (! $vendor) {
            $vendor = Vendor::create([
                'tenant_id'    => $tenantId,
                'company_name' => self::TPV_NAME,
                'email'        => 'demo.tpv.vendor@example.test',
                'status'       => VendorStatus::ACTIVE,
            ]);
            $this->report('TPV vendor', 'created', $vendor->company_name);
        } else {
            $this->report('TPV vendor', 'exists', $vendor->company_name);
        }

        // The portal login is a User with the vendor role, linked back to the
        // vendor row — the TPV portal resolves the vendor from the token.
        $user = User::where('email', 'demo.tpv.vendor@example.test')->first();
        if (! $user) {
            $user = User::create([
                'tenant_id' => $tenantId, 'name' => self::TPV_NAME, 'role' => 'third_party_vendor',
                'email' => 'demo.tpv.vendor@example.test', 'password' => Hash::make($password),
                'status' => 'active',
            ]);
            $this->report('TPV portal login', 'created', $user->email);
        } else {
            $user->forceFill(['password' => Hash::make($password), 'status' => 'active'])->save();
            $this->report('TPV portal login', 'password reset', $user->email);
        }

        if ((int) $vendor->user_id !== (int) $user->id) {
            $vendor->forceFill(['user_id' => $user->id])->save();
        }

        return $vendor->fresh();
    }

    private function tpvMeeting(int $tenantId, Vendor $vendor): void
    {
        $existing = KickoffMeeting::where('tenant_id', $tenantId)
            ->where('kickoffable_type', Vendor::class)
            ->where('kickoffable_id', $vendor->id)
            ->first();

        if ($existing) {
            $this->report('TPV meeting', 'exists', $existing->meeting_no.' · '.$existing->title);

            return;
        }

        $start = BusinessTime::now($tenantId)->copy()->addHours(3)->setSeconds(0);
        $meeting = KickoffMeeting::create([
            'tenant_id'        => $tenantId,
            'kickoffable_type' => Vendor::class,
            'kickoffable_id'   => $vendor->id,
            'title'            => 'Demo kickoff — TPV',
            'meeting_type'     => 'kickoff',
            // Scheduled, not Draft: a Draft is invisible to the vendor by design,
            // and an invisible record proves nothing when clicking through.
            'status'           => KickoffStatus::SCHEDULED,
            'mode'             => 'online',
            'meeting_link'     => 'https://meet.jit.si/demo-tpv-kickoff',
            'location'         => 'Online',
            'scheduled_at'     => $start->format('Y-m-d H:i:s'),
            'end_at'           => $start->copy()->addHour()->format('Y-m-d H:i:s'),
            'duration_minutes' => 60,
        ]);

        $this->report('TPV meeting', 'created', $meeting->meeting_no.' · starts in 3h');
    }

    /* ── Purchase ────────────────────────────────────────────────────────── */

    private function purchaseVendor(int $tenantId, string $password): PurchaseVendor
    {
        $vendor = PurchaseVendor::where('tenant_id', $tenantId)
            ->where('company_name', self::PURCHASE_NAME)->first();

        if (! $vendor) {
            $vendor = PurchaseVendor::create([
                'tenant_id'            => $tenantId,
                'company_name'         => self::PURCHASE_NAME,
                'purchase_vendor_code' => 'PV-DEMO01',
                'email'                => 'demo.purchase.vendor@example.test',
                'status'               => 'Active',
                'portal_status'        => 'active',
            ]);
            $this->report('Purchase vendor', 'created', $vendor->company_name);
        } else {
            $this->report('Purchase vendor', 'exists', $vendor->company_name);
        }

        // A Purchase vendor IS the identity — it authenticates as itself, with
        // no User row behind it.
        $vendor->forceFill([
            'password'      => Hash::make($password),
            'portal_status' => 'active',
            'status'        => 'Active',
        ])->save();
        $this->report('Purchase portal login', 'password set', $vendor->email);

        return $vendor->fresh();
    }

    private function vendorItem(int $tenantId, PurchaseVendor $vendor): PurchaseVendorItem
    {
        $product = Product::where('tenant_id', $tenantId)->where('sku', self::ITEM_SKU)->first();
        if (! $product) {
            $product = Product::create([
                'tenant_id' => $tenantId,
                'name'      => 'Demo Safety Helmet',
                'sku'       => self::ITEM_SKU,
                'base_unit' => 'Nos',
            ]);
            $this->report('Inventory item', 'created', $product->name);
        } else {
            $this->report('Inventory item', 'exists', $product->name);
        }

        $mapping = PurchaseVendorItem::where('tenant_id', $tenantId)
            ->where('purchase_vendor_id', $vendor->id)
            ->where('inventory_product_id', $product->id)
            ->first();

        if (! $mapping) {
            $mapping = PurchaseVendorItem::create([
                'tenant_id'            => $tenantId,
                'purchase_vendor_id'   => $vendor->id,
                'inventory_product_id' => $product->id,
                'status'               => 'Active',
                'effective_date'       => now()->toDateString(),
                'remarks'              => 'Seeded by demo:vendor-workflow',
            ]);
            $this->report('Vendor item mapping', 'created', $product->name.' → '.$vendor->company_name);
        } else {
            $this->report('Vendor item mapping', 'exists', $product->name.' → '.$vendor->company_name);
        }

        return $mapping->load('product');
    }

    private function purchaseMeeting(int $tenantId, PurchaseVendor $vendor): void
    {
        $existing = PurchaseKickoffMeeting::where('tenant_id', $tenantId)
            ->where('purchase_vendor_id', $vendor->id)->first();

        if ($existing) {
            $this->report('Purchase meeting', 'exists', $existing->meeting_no.' · '.$existing->title);

            return;
        }

        $start = BusinessTime::now($tenantId)->copy()->addHours(3)->setSeconds(0);
        $meeting = PurchaseKickoffMeeting::create([
            'tenant_id'          => $tenantId,
            'purchase_vendor_id' => $vendor->id,
            'title'              => 'Demo kickoff — Purchase',
            'meeting_type'       => 'kickoff',
            'status'             => PurchaseKickoffStatus::SCHEDULED,
            'mode'               => 'online',
            'meeting_link'       => 'https://meet.jit.si/demo-purchase-kickoff',
            'location'           => 'Online',
            'scheduled_at'       => $start->format('Y-m-d H:i:s'),
            'end_at'             => $start->copy()->addHour()->format('Y-m-d H:i:s'),
            'duration_minutes'   => 60,
        ]);

        $this->report('Purchase meeting', 'created', $meeting->meeting_no.' · starts in 3h');
    }

    private function report(string $what, string $action, string $detail): void
    {
        $tone = $action === 'exists' ? 'comment' : 'info';
        $this->{$tone}(sprintf('  %-22s %-16s %s', $what, $action, $detail));
    }
}
