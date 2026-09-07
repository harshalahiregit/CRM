<?php

namespace App\Http\Requests\Concerns;

use App\Support\Shared\OnboardingProfileDraft;

/**
 * Shared behaviour for the two step-2 onboarding profile requests.
 *
 * Both engines ask the same question of the same long form, so both answer it the
 * same way. The RULES stay in each request — they are that module's own — and
 * only the draft handling and the on-screen field names live here.
 *
 * @see OnboardingProfileDraft for why a draft is sifted rather than rejected.
 */
trait SavesProfileDrafts
{
    /**
     * Fields the vendor typed that could not be stored yet, and why.
     *
     * @var array<string,string>
     */
    protected array $skippedFields = [];

    /** @return array<string,string> */
    public function skippedFields(): array
    {
        return $this->skippedFields;
    }

    /** Is this the wizard keeping a half-filled form on the way past? */
    public function isDraft(): bool
    {
        return $this->boolean('draft');
    }

    /**
     * The rule for the profile bag itself.
     *
     * A draft may legitimately sift down to nothing — every field the vendor had
     * touched so far was half-typed — and "you sent nothing" is not a failure
     * worth showing them, so an empty bag is allowed on that path only.
     */
    protected function profileRule(): string
    {
        return $this->isDraft() ? 'present|array' : 'required|array';
    }

    /**
     * Each request wraps its own rules in this.
     *
     * On a draft the completeness rules come out, so a field the vendor has not
     * reached yet cannot fail a save of the fields they have. Format rules stay:
     * a malformed GSTIN is wrong now and will still be wrong at submission.
     *
     * @param  array<string,mixed>  $rules
     * @return array<string,mixed>
     */
    protected function relaxForDraft(array $rules): array
    {
        return $this->isDraft() ? OnboardingProfileDraft::relax($rules) : $rules;
    }

    /**
     * On a draft, keep every field that stands on its own and set the rest aside.
     *
     * Without this a single half-finished field — an account number with the IFSC
     * still to come — failed the whole save, and the vendor lost everything else
     * they had typed with it.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->isDraft() || ! is_array($this->input('profile'))) {
            return;
        }

        [$kept, $skipped] = OnboardingProfileDraft::sift($this->input('profile'), $this->rules());

        $this->skippedFields = $skipped;
        $this->merge(['profile' => $kept]);
    }

    /**
     * Name the boxes on the form, not the keys in the payload.
     *
     * Left alone, Laravel says "The profile.bank ifsc field is required when
     * profile.bank account number is present" — a JSON path the vendor has never
     * seen. With these it says "The IFSC field is required when Account number is
     * present", which points at something on screen.
     */
    public function attributes(): array
    {
        return OnboardingProfileDraft::attributes();
    }
}
