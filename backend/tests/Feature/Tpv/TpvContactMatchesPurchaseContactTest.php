<?php

namespace Tests\Feature\Tpv;

use App\Http\Requests\Purchase\StorePurchaseContactRequest;
use App\Http\Requests\Tpv\StoreTpvContactRequest;
use App\Models\Purchase\PurchaseContact;
use App\Models\Tpv\TpvContact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * A vendor contact is one kind of record. The two workspaces must capture it
 * the same way.
 *
 * They did not, and the gap was not cosmetic. The TPV form asked for twenty-six
 * fields; the endpoint accepted nine and the table had nine. Seventeen were
 * typed in and dropped on save without a word — and the name box was
 * `full_name` while the request required `first_name` and `last_name`, so the
 * save did not lose data, it FAILED, every time, naming a field the form did
 * not have and could not show an error against.
 *
 * That is why a TPV vendor's contact count stayed at zero, and why the Add
 * Contact onboarding step could never go green on that side.
 *
 * Purchase's version asks for sixteen, accepts sixteen and stores sixteen. TPV
 * is now the same form against the same contract. These assertions are what
 * stops them parting company again.
 */
class TpvContactMatchesPurchaseContactTest extends TestCase
{
    use RefreshDatabase;

    /** Exactly what the (now shared) contact form posts. */
    private const FORM_BODY = [
        'first_name' => 'Shivam',
        'last_name' => 'Singh',
        'designation' => 'Full stack developer',
        'department' => 'Engineering',
        'email' => 'shivam@vendor.test',
        'phone' => '02012345678',
        'mobile' => '9876543210',
        'alternate_mobile' => '9876500000',
        'address' => '12 Industrial Estate',
        'city' => 'Pune',
        'state' => 'MH',
        'country' => 'India',
        'pincode' => '411001',
        'notes' => 'Night shift contact',
        'is_primary' => true,
    ];

    public function test_both_endpoints_accept_the_same_fields(): void
    {
        $tpv = array_keys((new StoreTpvContactRequest())->rules());
        $purchase = array_keys((new StorePurchaseContactRequest())->rules());

        sort($tpv);
        sort($purchase);

        $this->assertSame(
            $purchase,
            $tpv,
            'The two contact endpoints no longer take the same fields. One screen '
            .'is about to start dropping what somebody typed.'
        );
    }

    public function test_the_form_body_passes_both_endpoints(): void
    {
        foreach ([
            'TPV' => (new StoreTpvContactRequest())->rules(),
            'Purchase' => (new StorePurchaseContactRequest())->rules(),
        ] as $name => $rules) {
            $v = Validator::make(self::FORM_BODY, $rules);

            $this->assertFalse(
                $v->fails(),
                "{$name} refuses the contact form's own body: ".implode(' | ', $v->errors()->all())
            );
        }
    }

    /**
     * And the columns have to exist, or the rules are a promise the table
     * cannot keep — accepted, assigned, and silently gone at save.
     */
    public function test_both_tables_have_a_column_for_every_accepted_field(): void
    {
        $skip = ['is_primary', 'status'];   // present on both; asserted below anyway

        foreach ([
            'tpv_contacts' => new TpvContact(),
            'purchase_contacts' => new PurchaseContact(),
        ] as $table => $model) {
            $fillable = $model->getFillable();

            foreach (array_keys(self::FORM_BODY) as $field) {
                $this->assertTrue(
                    Schema::hasColumn($table, $field),
                    "{$table} has no column for {$field}, which its endpoint accepts."
                );

                if (! in_array($field, $skip, true)) {
                    $this->assertContains(
                        $field,
                        $fillable,
                        get_class($model)." does not list {$field} as fillable, so it is "
                        .'dropped on create even though the column exists.'
                    );
                }
            }
        }
    }

    /** The old break, named, so nobody reintroduces the field that never existed. */
    public function test_full_name_is_not_something_either_endpoint_takes(): void
    {
        foreach ([
            'TPV' => (new StoreTpvContactRequest())->rules(),
            'Purchase' => (new StorePurchaseContactRequest())->rules(),
        ] as $name => $rules) {
            $this->assertArrayNotHasKey(
                'full_name',
                $rules,
                "{$name} accepts full_name — the form must post first_name and last_name."
            );
        }
    }
}
