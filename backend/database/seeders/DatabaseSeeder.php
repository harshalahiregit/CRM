<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Create demo tenant
        $tenant = Tenant::firstOrCreate(
            ['subdomain' => 'mla-consulting'],
            [
                'name'      => 'MLA Consulting',
                'slug'      => 'mla-consulting',
                'plan'      => 'professional',
                'status'    => 'active',
                'branding_color' => '#2563EB',
            ]
        );

        // Create super admin
        User::firstOrCreate(
            ['email' => 'admin@mlacrm.com'],
            [
                'tenant_id' => $tenant->id,
                'name'      => 'Super Admin',
                'password'  => Hash::make('Admin@12345'),
                'role'      => 'admin',
                'status'    => 'active',
                'company'   => 'MLA Consulting',
            ]
        );

        // Demo vendor (active for testing)
        User::firstOrCreate(
            ['email' => 'vendor@mlacrm.com'],
            [
                'tenant_id'   => $tenant->id,
                'name'        => 'Demo Vendor',
                'password'    => Hash::make('Vendor@12345'),
                'role'        => 'vendor',
                'vendor_type' => 'standard',
                'status'      => 'active',
                'company'     => 'Demo Supplies Ltd',
            ]
        );

        // Demo TPV (active for testing)
        User::firstOrCreate(
            ['email' => 'tpv@mlacrm.com'],
            [
                'tenant_id' => $tenant->id,
                'name'      => 'Demo TPV',
                'password'  => Hash::make('TPV@12345'),
                'role'      => 'third_party_vendor',
                'tpv_type'  => 'permanent',
                'status'    => 'active',
            ]
        );

        // Demo client (active for testing)
        User::firstOrCreate(
            ['email' => 'client@mlacrm.com'],
            [
                'tenant_id' => $tenant->id,
                'name'      => 'Demo Client',
                'password'  => Hash::make('Client@12345'),
                'role'      => 'client',
                'status'    => 'active',
                'company'   => 'Acme Corp',
            ]
        );

        // Lead pipeline statuses + sources. Not demo data — the Leads kanban has no
        // columns without statuses, so every workspace needs these. This seeder
        // existed but was never wired up here, which is why the board came up blank.
        $this->call(LeadDefaultsSeeder::class);

        // Helpdesk module demo data (owner: Shivam)
        $this->call(HelpdeskSeeder::class);

        // Projects + Tasks demo data (owner: Shivam) — runs AFTER Helpdesk so its
        // integration step can link real seeded tickets to projects/tasks.
        $this->call(ProjectTaskSeeder::class);

        // Inventory catalog + stock ledger demo data (owner: Shivam).
        $this->call(InventorySeeder::class);

        // Sales demo data (Contracts, Web-To-Lead Forms)
        $this->call(SalesDemoSeeder::class);

        $this->command->info('✅ Demo data seeded successfully!');
        $this->command->info('');
        $this->command->info('Demo credentials:');
        $this->command->info('  Admin:  admin@mlacrm.com  / Admin@12345');
        $this->command->info('  Vendor: vendor@mlacrm.com / Vendor@12345');
        $this->command->info('  TPV:    tpv@mlacrm.com    / TPV@12345');
        $this->command->info('  Client: client@mlacrm.com / Client@12345');
    }
}
