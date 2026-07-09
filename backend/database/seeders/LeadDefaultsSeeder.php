<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Sales\LeadStatus;
use App\Models\Sales\LeadSource;
use App\Models\Tenant;

class LeadDefaultsSeeder extends Seeder
{
    public function run(): void
    {
        // Seed for all existing tenants
        $tenants = Tenant::all();

        foreach ($tenants as $tenant) {
            $this->seedForTenant($tenant->id);
        }
    }

    public static function seedForTenant(int $tenantId): void
    {
        // ── Default Pipeline Statuses ────────────────────────
        $statuses = [
            ['name' => 'New',            'color' => '#3b82f6', 'sort_order' => 1, 'is_default' => true,  'is_won_status' => false],
            ['name' => 'Contacted',      'color' => '#8b5cf6', 'sort_order' => 2, 'is_default' => false, 'is_won_status' => false],
            ['name' => 'Qualified',      'color' => '#f59e0b', 'sort_order' => 3, 'is_default' => false, 'is_won_status' => false],
            ['name' => 'Proposal Sent',  'color' => '#ec4899', 'sort_order' => 4, 'is_default' => false, 'is_won_status' => false],
            ['name' => 'Negotiation',    'color' => '#ef4444', 'sort_order' => 5, 'is_default' => false, 'is_won_status' => false],
            ['name' => 'Won',            'color' => '#10b981', 'sort_order' => 6, 'is_default' => false, 'is_won_status' => true],
        ];

        foreach ($statuses as $status) {
            LeadStatus::firstOrCreate(
                ['tenant_id' => $tenantId, 'name' => $status['name']],
                $status + ['tenant_id' => $tenantId]
            );
        }

        // ── Default Lead Sources ─────────────────────────────
        $sources = [
            ['name' => 'Google',    'sort_order' => 1],
            ['name' => 'Facebook',  'sort_order' => 2],
            ['name' => 'Website',   'sort_order' => 3],
            ['name' => 'Referral',  'sort_order' => 4],
            ['name' => 'Cold Call', 'sort_order' => 5],
            ['name' => 'LinkedIn',  'sort_order' => 6],
            ['name' => 'Other',     'sort_order' => 7],
        ];

        foreach ($sources as $source) {
            LeadSource::firstOrCreate(
                ['tenant_id' => $tenantId, 'name' => $source['name']],
                $source + ['tenant_id' => $tenantId]
            );
        }
    }
}
