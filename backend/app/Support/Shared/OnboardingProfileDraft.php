<?php

namespace App\Support\Shared;

use Illuminate\Support\Facades\Validator;

/**
 * A half-filled onboarding profile is a draft, not an error.
 *
 * Step 2 of vendor onboarding is a long form — company, contact, bank, GST, PAN,
 * address — and nobody fills it in one sitting. The wizard already saves what has
 * been typed when the vendor moves to another step, but the server was rejecting
 * that save outright: the bank block carries `required_with` both ways, so typing
 * an account number and leaving the IFSC for later made the WHOLE save a 422. The
 * draft flush swallowed it, and the vendor came back to an empty form having been
 * told nothing. Everything they had typed — company name, address, contact, the
 * lot — was thrown away because one field was half-finished.
 *
 * ── What this does ──────────────────────────────────────────────────────────
 * On a draft save each field is judged ON ITS OWN. What is valid is stored, what
 * is not is left out and NAMED, so the wizard can say "your IFSC was not saved
 * yet — it needs the account number too" instead of failing the lot in silence.
 *
 * Completeness rules are dropped for a draft and only those: `required_with` and
 * friends ask "is this finished?", which is the wrong question halfway through.
 * Format rules stay — a malformed GSTIN is wrong now and will still be wrong at
 * submission, so it is reported now rather than stored and rejected later.
 *
 * The strict rules are untouched and still run on Save & Continue and on submit,
 * which is where completeness actually has to hold.
 */
class OnboardingProfileDraft
{
    /** Rules that ask whether the form is FINISHED. Meaningless mid-draft. */
    private const COMPLETENESS = ['required', 'required_with', 'required_without', 'required_if', 'required_unless'];

    /**
     * What each field is called on screen.
     *
     * Laravel builds its sentences from the payload key, so a vendor was told
     * "The profile.bank ifsc field is required when profile.bank account number
     * is present" — a JSON path, naming no box they can see. These are the names
     * on the form, shared by both engines because both forms use them.
     *
     * @var array<string,string>
     */
    public const LABELS = [
        'company_name' => 'Company name',
        'legal_name' => 'Legal name',
        'company_registration_number' => 'Company registration number',
        'company_reg_date' => 'Company registration date',
        'registration_date' => 'Registration date',
        'category' => 'Category',
        'company_phone' => 'Company phone',
        'website' => 'Website',

        'contact_person' => 'Contact person',
        'contact_email' => 'Contact email',
        'contact_mobile' => 'Contact mobile',
        'designation' => 'Designation',

        'full_name' => 'Full name',
        'email' => 'Email',
        'mobile' => 'Mobile',
        'alt_mobile' => 'Alternate mobile',
        'gender' => 'Gender',
        'dob' => 'Date of birth',
        'profile_photo' => 'Profile photo',
        'emergency_contact' => 'Emergency contact',
        'emergency_phone' => 'Emergency phone',

        'authorized_name' => 'Authorised person',
        'authorized_designation' => 'Authorised designation',
        'authorized_email' => 'Authorised email',
        'authorized_mobile' => 'Authorised mobile',
        'authorized_id_proof' => 'Authorised ID proof',

        'bank_account_holder' => 'Account holder',
        'bank_name' => 'Bank name',
        'bank_account_number' => 'Account number',
        'bank_ifsc' => 'IFSC',
        'bank_branch' => 'Branch',
        'bank_account_type' => 'Account type',

        'gst_number' => 'GST number',
        'gst_state' => 'GST state',
        'pan_number' => 'PAN number',

        'registered_address' => 'Registered address',
        'city' => 'City',
        'state' => 'State',
        'country' => 'Country',
        'pincode' => 'Pincode',

        'estimated_workforce' => 'Estimated workforce',
        'scope_of_work' => 'Scope of work',
        'linkedin' => 'LinkedIn',
        'facebook' => 'Facebook',
        'twitter' => 'Twitter',
        'instagram' => 'Instagram',
        'youtube' => 'YouTube',
        'portfolio' => 'Portfolio',
    ];

    /**
     * Split a draft profile into what can be stored and what cannot (yet).
     *
     * @param  array<string,mixed>  $profile  what the vendor has typed so far
     * @param  array<string,mixed>  $rules  the request's own strict rules
     * @return array{0:array<string,mixed>,1:array<string,string>} [kept, skipped]
     */
    public static function sift(array $profile, array $rules): array
    {
        $kept = [];
        $skipped = [];

        foreach ($profile as $field => $value) {
            $rule = $rules['profile.'.$field] ?? null;

            // Not a field on this form. Storing it would put a key in the profile
            // that nothing reads and no rule guards, so it is left out — but it is
            // NAMED, because a field the form shows and the server does not know
            // is a wiring mistake, and silence is how that reaches production.
            if ($rule === null) {
                $skipped[$field] = 'This is not a field on this form and was not saved.';

                continue;
            }

            $v = Validator::make(
                ['profile' => [$field => $value]],
                ['profile.'.$field => self::withoutCompleteness($rule)],
                [],
                ['profile.'.$field => self::label($field)],
            );

            if ($v->fails()) {
                $skipped[$field] = $v->errors()->first();

                continue;
            }

            $kept[$field] = $value;
        }

        return [$kept, $skipped];
    }

    /**
     * The same rule set with every "is it finished?" rule taken out.
     *
     * Sifting the payload is not enough on its own: `required_with` fires on a
     * field that is ABSENT, so an account number typed without its IFSC still
     * failed the whole save — there was no `bank_ifsc` in the payload for the
     * sift to set aside. Completeness has to come out of the rules as well.
     *
     * @param  array<string,mixed>  $rules
     * @return array<string,mixed>
     */
    public static function relax(array $rules): array
    {
        $out = [];
        foreach ($rules as $field => $rule) {
            $out[$field] = $field === 'profile' ? $rule : self::withoutCompleteness($rule);
        }

        return $out;
    }

    /** Every field's on-screen name, for the strict path's messages. */
    public static function attributes(string $prefix = 'profile.'): array
    {
        $out = [];
        foreach (self::LABELS as $field => $label) {
            $out[$prefix.$field] = $label;
        }

        return $out;
    }

    public static function label(string $field): string
    {
        return self::LABELS[$field] ?? ucfirst(str_replace('_', ' ', $field));
    }

    /**
     * The same rule with "is it finished?" removed.
     *
     * @param  string|array  $rule
     */
    private static function withoutCompleteness($rule): array
    {
        $parts = is_string($rule) ? explode('|', $rule) : (array) $rule;

        return array_values(array_filter($parts, function ($part) {
            if (! is_string($part)) {
                return true;                       // a rule object — a format check
            }
            $name = strtolower(explode(':', $part, 2)[0]);

            return ! in_array($name, self::COMPLETENESS, true);
        }));
    }
}
