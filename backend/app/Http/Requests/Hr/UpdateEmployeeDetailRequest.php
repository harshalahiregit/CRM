<?php

namespace App\Http\Requests\Hr;

use App\Rules\Ifsc;
use App\Rules\Pan;
use App\Rules\PhoneNumber;
use App\Rules\Pincode;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The extended employee record — personal, address, education, emergency
 * contact, bank, identity and statutory.
 *
 * Everything is optional. HR fills these in over weeks as documents arrive, and
 * a form that refuses to save until a passport number is present is a form that
 * loses the emergency contact somebody typed in the meantime.
 *
 * What IS enforced is format, on the fields where a wrong one costs real money:
 * an IFSC that does not exist means a salary that bounces, and a malformed PAN
 * or UAN is rejected by the filing rather than by us.
 */
class UpdateEmployeeDetailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // ── Personal ──
            'marital_status'  => 'nullable|string|in:Single,Married,Divorced,Widowed,Other',
            'blood_group'     => 'nullable|string|in:A+,A-,B+,B-,AB+,AB-,O+,O-',
            'father_name'     => 'nullable|string|max:150',
            'mother_name'     => 'nullable|string|max:150',
            'spouse_name'     => 'nullable|string|max:150',
            'nationality'     => 'nullable|string|max:80',
            'religion'        => 'nullable|string|max:80',
            'personal_email'  => 'nullable|email|max:191',
            'alternate_phone' => ['nullable', 'string', 'max:30', new PhoneNumber],

            // ── Address as it appears on their documents ──
            'permanent_address' => 'nullable|string|max:1000',
            'permanent_city'    => 'nullable|string|max:120',
            'permanent_state'   => 'nullable|string|max:120',
            'permanent_pincode' => ['nullable', 'string', 'max:10', new Pincode],
            'permanent_country' => 'nullable|string|max:120',

            // ── Education ──
            'highest_qualification' => 'nullable|string|max:150',
            'specialization'        => 'nullable|string|max:150',
            'institution'           => 'nullable|string|max:200',
            // A string, not an integer: people write "2015-16" for a session.
            'year_of_passing'       => 'nullable|string|max:20',

            // ── Emergency contact ──
            'emergency_name'         => 'nullable|string|max:150',
            'emergency_relationship' => 'nullable|string|max:80',
            'emergency_phone'        => ['nullable', 'string', 'max:30', new PhoneNumber],
            'emergency_alt_phone'    => ['nullable', 'string', 'max:30', new PhoneNumber],
            'emergency_address'      => 'nullable|string|max:1000',

            // ── Bank ──
            'bank_account_holder_name' => 'nullable|string|max:150',
            // Not numeric: account numbers have leading zeros, and a numeric rule
            // would quietly turn 0012345 into 12345 and send the salary elsewhere.
            'bank_account_number'      => 'nullable|string|max:34',
            'bank_ifsc'                => ['nullable', 'string', 'max:15', new Ifsc],
            'bank_name'                => 'nullable|string|max:150',
            'bank_branch'              => 'nullable|string|max:150',
            'bank_account_type'        => 'nullable|string|in:Savings,Current',

            // ── Identity ──
            'pan_number'             => ['nullable', 'string', 'max:12', new Pan],
            // Twelve digits. Spaces are stripped before this runs, so a number
            // typed in the 1234 5678 9012 grouping people are used to is accepted.
            'aadhaar_number'         => 'nullable|string|regex:/^[0-9]{12}$/',
            'passport_number'        => 'nullable|string|max:20',
            'passport_expiry'        => 'nullable|date',
            'driving_licence_number' => 'nullable|string|max:25',

            // ── Statutory ──
            'uan_number'              => 'nullable|string|regex:/^[0-9]{12}$/',
            'pf_number'               => 'nullable|string|max:40',
            'esic_number'             => 'nullable|string|max:25',
            'esic_ip_number'          => 'nullable|string|max:25',
            'pf_nominee_name'         => 'nullable|string|max:150',
            'pf_nominee_relation'     => 'nullable|string|max:80',
            'is_international_worker' => 'nullable|boolean',
            'has_previous_pf'         => 'nullable|boolean',
            'tax_regime'              => 'nullable|string|in:Old,New',

            // ── Whether each deduction applies to this person ──
            'pf_applicable'       => 'nullable|boolean',
            'eps_applicable'      => 'nullable|boolean',
            'esic_applicable'     => 'nullable|boolean',
            'pt_applicable'       => 'nullable|boolean',
            'lwf_applicable'      => 'nullable|boolean',
            'gratuity_applicable' => 'nullable|boolean',

            // PF membership can begin after employment — a probationer enrolled
            // on confirmation. Filing with joining_date would be wrong.
            'pf_joining_date' => 'nullable|date',
            // Voluntary PF: a flat amount OR a percentage, never both. Capped at
            // 100 because a percentage above it is a typo, not a choice.
            'vpf_amount'      => 'nullable|numeric|min:0|max:9999999',
            'vpf_percent'     => 'nullable|numeric|min:0|max:100',
            // Null means "follow the rule"; true/false overrides it per person.
            'restrict_pf_to_ceiling' => 'nullable|boolean',

            'esic_dispensary' => 'nullable|string|max:150',
            // ESIC's ceiling is 25,000 for a person with disability, not 42,000.
            'is_disabled'     => 'nullable|boolean',
        ];
    }

    /**
     * Strip the grouping people type into number fields before validating.
     *
     * "1234 5678 9012" is how an Aadhaar is printed on the card, and rejecting it
     * teaches somebody to remove the spaces rather than teaching us to.
     */
    protected function prepareForValidation(): void
    {
        foreach (['aadhaar_number', 'uan_number'] as $field) {
            if ($this->filled($field)) {
                $this->merge([$field => preg_replace('/\D/', '', (string) $this->input($field))]);
            }
        }
    }

    public function messages(): array
    {
        return [
            'aadhaar_number.regex' => 'An Aadhaar number is 12 digits.',
            'uan_number.regex'     => 'A UAN is 12 digits.',
        ];
    }
}
