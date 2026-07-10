<?php

namespace Database\Seeders;

use App\Models\Sales\HsnSacCode;
use Illuminate\Database\Seeder;

class HsnSacCodeSeeder extends Seeder
{
    public function run(): void
    {
        $codes = [
            // Common services (SAC)
            ['code' => '998311', 'description' => 'Management consulting services', 'gst_rate' => 18, 'type' => 'SAC'],
            ['code' => '998312', 'description' => 'Business consulting services', 'gst_rate' => 18, 'type' => 'SAC'],
            ['code' => '998313', 'description' => 'Information technology (IT) consulting services', 'gst_rate' => 18, 'type' => 'SAC'],
            ['code' => '998314', 'description' => 'IT design and development services', 'gst_rate' => 18, 'type' => 'SAC'],
            ['code' => '998315', 'description' => 'Hosting and IT infrastructure provisioning services', 'gst_rate' => 18, 'type' => 'SAC'],
            ['code' => '998316', 'description' => 'IT infrastructure and network management services', 'gst_rate' => 18, 'type' => 'SAC'],
            ['code' => '998319', 'description' => 'Other IT services', 'gst_rate' => 18, 'type' => 'SAC'],
            ['code' => '998321', 'description' => 'Software development and support services', 'gst_rate' => 18, 'type' => 'SAC'],
            ['code' => '998361', 'description' => 'Advertising services', 'gst_rate' => 18, 'type' => 'SAC'],
            ['code' => '998363', 'description' => 'Market research and public opinion polling services', 'gst_rate' => 18, 'type' => 'SAC'],
            ['code' => '998391', 'description' => 'Specialty design services', 'gst_rate' => 18, 'type' => 'SAC'],
            ['code' => '998393', 'description' => 'Photography and videography services', 'gst_rate' => 18, 'type' => 'SAC'],
            ['code' => '998411', 'description' => 'Legal advisory and representation services', 'gst_rate' => 18, 'type' => 'SAC'],
            ['code' => '998412', 'description' => 'Accounting and bookkeeping services', 'gst_rate' => 18, 'type' => 'SAC'],
            ['code' => '998413', 'description' => 'Tax consultancy and preparation services', 'gst_rate' => 18, 'type' => 'SAC'],
            ['code' => '998551', 'description' => 'Employment and recruitment agency services', 'gst_rate' => 18, 'type' => 'SAC'],
            ['code' => '998552', 'description' => 'Human resources provision services', 'gst_rate' => 18, 'type' => 'SAC'],
            ['code' => '999799', 'description' => 'Other miscellaneous services', 'gst_rate' => 18, 'type' => 'SAC'],
            ['code' => '997212', 'description' => 'Rental or leasing services involving commercial property', 'gst_rate' => 18, 'type' => 'SAC'],
            ['code' => '996511', 'description' => 'Local land transport services of goods', 'gst_rate' => 5, 'type' => 'SAC'],

            // Common goods (HSN)
            ['code' => '8471', 'description' => 'Automatic data processing machines (computers)', 'gst_rate' => 18, 'type' => 'HSN'],
            ['code' => '8517', 'description' => 'Telephone sets, smartphones', 'gst_rate' => 18, 'type' => 'HSN'],
            ['code' => '4820', 'description' => 'Registers, notebooks, stationery articles', 'gst_rate' => 12, 'type' => 'HSN'],
            ['code' => '4901', 'description' => 'Printed books, brochures, leaflets', 'gst_rate' => 0, 'type' => 'HSN'],
            ['code' => '8443', 'description' => 'Printing machinery', 'gst_rate' => 18, 'type' => 'HSN'],
            ['code' => '9403', 'description' => 'Office and other furniture', 'gst_rate' => 18, 'type' => 'HSN'],
            ['code' => '8523', 'description' => 'Discs, tapes, storage media', 'gst_rate' => 18, 'type' => 'HSN'],
            ['code' => '4911', 'description' => 'Other printed matter (brochures, posters)', 'gst_rate' => 12, 'type' => 'HSN'],
        ];

        foreach ($codes as $code) {
            HsnSacCode::updateOrCreate(
                ['code' => $code['code'], 'type' => $code['type']],
                $code
            );
        }
    }
}
