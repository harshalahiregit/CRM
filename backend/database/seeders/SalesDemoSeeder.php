<?php

namespace Database\Seeders;

use App\Models\Customer\Client;
use App\Models\Customer\ClientContact;
use App\Models\Sales\ContractType;
use App\Models\Sales\Estimate;
use App\Models\Sales\Lead;
use App\Models\Sales\Proposal;
use App\Models\Sales\SalesContract;
use App\Models\Sales\WebToLeadForm;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class SalesDemoSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::first();
        if (! $tenant) return;
        $tid = $tenant->id;
        $admin = User::where('tenant_id', $tid)->where('role', 'admin')->first();
        $actor = $admin?->id;

        // 1. Client & Contact
        $client = Client::firstOrCreate(
            ['tenant_id' => $tid, 'company' => 'Acme Corporation'],
            [
                'gst_number' => 'GSTIN27AAACA1234F1Z5',
                'phone' => '+91 98765 43210',
                'website' => 'https://acme.example.com',
                'address' => '101 Tech Park, BKC',
                'city' => 'Mumbai',
                'state' => 'Maharashtra',
                'zip' => '400051',
                'country' => 'India',
                'added_by' => $actor,
            ]
        );

        ClientContact::firstOrCreate(
            ['tenant_id' => $tid, 'client_id' => $client->id, 'email' => 'contact@acme.example.com'],
            [
                'first_name' => 'Rajesh',
                'last_name' => 'Kumar',
                'phone' => '+91 98765 43210',
                'title' => 'Chief Technology Officer',
                'is_primary' => true,
            ]
        );

        // 2. Contract Types
        $contractTypes = [
            'Software License Agreement',
            'Service Level Agreement (SLA)',
            'Annual Maintenance Contract (AMC)',
            'Consulting & Support Retainer'
        ];
        $typeIds = [];
        foreach ($contractTypes as $typeName) {
            $t = ContractType::firstOrCreate(
                ['tenant_id' => $tid, 'name' => $typeName]
            );
            $typeIds[$typeName] = $t->id;
        }

        // 3. Sample Contracts (matching older version standards)
        if (SalesContract::where('tenant_id', $tid)->count() === 0) {
            SalesContract::create([
                'tenant_id' => $tid,
                'reference_no' => 'CON-2026-001',
                'subject' => 'Enterprise Cloud ERP Software License & SLA Agreement',
                'client_id' => $client->id,
                'contract_type_id' => $typeIds['Software License Agreement'] ?? null,
                'value' => 750000.00,
                'currency' => 'INR',
                'start_date' => Carbon::now()->subDays(15),
                'end_date' => Carbon::now()->addYear(),
                'description' => '<p><strong>ENTERPRISE SOFTWARE & SERVICE AGREEMENT</strong></p><p>This Agreement is made by and between MLA Consulting and Acme Corporation for providing SaaS CRM & ERP Modules, 24/7 dedicated support, and monthly uptime SLAs of 99.9%.</p>',
                'status' => 'active',
                'signed_by_name' => 'Rajesh Kumar',
                'signed_at' => Carbon::now()->subDays(10),
                'created_by' => $actor,
                'public_token' => Str::random(40),
            ]);

            SalesContract::create([
                'tenant_id' => $tid,
                'reference_no' => 'CON-2026-002',
                'subject' => 'Annual Infrastructure & CRM Maintenance Contract (AMC)',
                'client_id' => $client->id,
                'contract_type_id' => $typeIds['Annual Maintenance Contract (AMC)'] ?? null,
                'value' => 250000.00,
                'currency' => 'INR',
                'start_date' => Carbon::now(),
                'end_date' => Carbon::now()->addMonths(6),
                'description' => '<p>Scope of work covers quarterly system audits, database optimizations, backup validation, and priority bug resolution.</p>',
                'status' => 'draft',
                'created_by' => $actor,
                'public_token' => Str::random(40),
            ]);
        }

        // 4. Sample Web-to-Lead Form (with rich field capabilities: rating, multi-select, attachment, text)
        if (WebToLeadForm::where('tenant_id', $tid)->count() === 0) {
            WebToLeadForm::create([
                'tenant_id' => $tid,
                'form_key' => 'sample-website-inquiry',
                'name' => 'Website Inquiry & Project Scope Form',
                'form_data' => [
                    ['key' => 'name', 'label' => 'Full Name', 'type' => 'text', 'required' => true],
                    ['key' => 'email', 'label' => 'Business Email', 'type' => 'email', 'required' => true],
                    ['key' => 'phone', 'label' => 'Phone Number', 'type' => 'text', 'required' => true],
                    ['key' => 'company', 'label' => 'Company Name', 'type' => 'text', 'required' => false],
                    ['key' => 'budget_rating', 'label' => 'Estimated Project Urgency / Priority (1-5 Stars)', 'type' => 'rating', 'required' => false],
                    ['key' => 'required_services', 'label' => 'Required CRM Services', 'type' => 'multiselect', 'options' => ['Sales Automation', 'Accounts & Invoicing', 'HR & Payroll', 'Inventory & Warehouse'], 'required' => false],
                    ['key' => 'project_attachment', 'label' => 'Upload RFP / Scope Specification Document', 'type' => 'file', 'required' => false],
                    ['key' => 'description', 'label' => 'Project Overview & Detailed Notes', 'type' => 'textarea', 'required' => false],
                ],
                'success_message' => 'Thank you for reaching out! A senior account manager will review your submission and contact you within 24 hours.',
                'allow_duplicate' => true,
                'is_active' => true,
                'submissions_count' => 0,
                'created_by' => $actor,
            ]);
        }
    }
}
