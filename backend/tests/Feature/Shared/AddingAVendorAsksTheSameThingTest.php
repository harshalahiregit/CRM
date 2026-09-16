<?php

namespace Tests\Feature\Shared;

use Tests\TestCase;

/**
 * Adding a vendor asks the same thirteen questions in both modules.
 *
 * It did not. TPV asked thirteen; Purchase asked twenty-eight — currency,
 * default language, opening balance and the date it was as of, contact person
 * and their designation, a second company phone, website, manpower, MSME,
 * country, bank details, payment terms, return policy. The same job behind the
 * same button, and the longer form asked for a return policy before anyone had
 * agreed to buy anything.
 *
 * Thirteen is the set that has to be true at the moment a vendor exists: who
 * they are, how to reach them, how they log in, and where they are. The rest
 * describes a trading relationship that does not exist yet and stays editable
 * on the vendor's Profile tab, which is where a commercial term belongs.
 *
 * Both screens now render ONE component. This guards the two things that would
 * quietly undo that: a second copy of the form, and a create payload that
 * posts more than the form collects.
 */
class AddingAVendorAsksTheSameThingTest extends TestCase
{
    private function src(string $relative): string
    {
        $path = base_path('../frontend/src/'.$relative);
        $this->assertFileExists($path, "{$relative} has moved — this guard needs repointing");

        return (string) file_get_contents($path);
    }

    /** One form, thirteen fields, one validator. */
    public function test_the_form_is_defined_once(): void
    {
        $form = $this->src('components/vendors/VendorMasterForm.jsx');

        foreach ([
            'name', 'company_name', 'email', 'phone', 'gst_number', 'vendor_type', 'status',
            'address', 'city', 'state', 'pincode',
        ] as $field) {
            $this->assertStringContainsString("'{$field}'", $form,
                "the shared vendor form no longer collects {$field}");
        }

        // The two credential fields are the twelfth and thirteenth.
        $this->assertStringContainsString("set('password')", $form);
        $this->assertStringContainsString("set('password_confirmation')", $form);

        $this->assertStringContainsString('export function validateVendorMaster', $form);
        $this->assertStringContainsString('export const VENDOR_MASTER_FIELDS', $form);
    }

    /** And both vendor lists render that one component. */
    public function test_both_modules_use_it(): void
    {
        foreach ([
            'modules/purchase/pages/PurchaseVendors.jsx',
            'modules/tpv/pages/TpvVendors.jsx',
        ] as $page) {
            $src = $this->src($page);

            $this->assertStringContainsString("from '@/components/vendors/VendorMasterForm'", $src,
                "{$page} no longer imports the shared form — a second copy is exactly how these two "
                .'grew fifteen fields apart in the first place');
            $this->assertStringContainsString('<VendorMasterForm', $src);
            $this->assertStringContainsString('validateVendorMaster', $src);
        }
    }

    /**
     * Purchase posts only what the form collects.
     *
     * The edit path seeds itself from the full record, so posting that object
     * wholesale sends back every column the form no longer shows — and any one
     * of them already holding an invalid value rejects the save. A vendor whose
     * website had been stored as "dfghhoiujkhj" could not be edited at all:
     * "The website field format is invalid", naming a field that is not on the
     * screen, with nothing to type into to correct it.
     *
     * Omitting a field is not clearing it. The columns are absent from the
     * payload, so the model keeps them.
     */
    public function test_purchase_sends_only_the_collected_fields(): void
    {
        $page = $this->src('modules/purchase/pages/PurchaseVendors.jsx');

        $this->assertStringContainsString('VENDOR_MASTER_FIELDS.filter', $page,
            'the Purchase save no longer trims its payload to the form’s own fields; a hidden column '
            .'holding an invalid value will block every edit with an error nobody can act on');

        $this->assertDoesNotMatchRegularExpression(
            '/vendors\.(update\(modal\.id, modal\)|create\(modal\))/', $page,
            'the whole modal object is being posted again',
        );
    }

    /**
     * The server no longer demands what the form stopped asking for.
     *
     * `required` on category was in any case stricter than the data: rows with
     * a null category already existed, arriving through self-registration and
     * conversion paths that never collected one. A rule the table already
     * violates is not a rule.
     */
    public function test_the_server_does_not_require_a_field_the_form_dropped(): void
    {
        foreach (['StorePurchaseVendorRequest', 'UpdatePurchaseVendorRequest'] as $request) {
            $src = (string) file_get_contents(
                base_path("app/Http/Requests/Purchase/{$request}.php"),
            );

            foreach (['category', 'currency'] as $field) {
                $this->assertMatchesRegularExpression(
                    "/'{$field}'\s*=>\s*'nullable/", $src,
                    "{$request} still requires {$field}, which the add form no longer asks for — "
                    .'so the save fails pointing at a field that is not on the screen',
                );
            }
        }
    }
}
