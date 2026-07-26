<?php

namespace Tests\Unit\Tpv;

use App\Http\Requests\Tpv\SaveOnboardingProfileRequest;
use App\Rules\Gstin;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class SaveOnboardingProfileRequestTest extends TestCase
{
    private function rules(): array
    {
        return (new SaveOnboardingProfileRequest())->rules();
    }

    private function validGstin(): string
    {
        $first14 = '27AAPFU0939F1Z';

        return $first14.Gstin::checksumChar($first14);
    }

    private function fails(array $profile): bool
    {
        return Validator::make(['profile' => $profile], $this->rules())->fails();
    }

    public function test_legacy_flat_profile_still_validates(): void
    {
        // Backward compatibility: an existing profile with only legacy keys passes.
        $this->assertFalse($this->fails([
            'contact_person' => 'Ravi', 'designation' => 'Director', 'scope_of_work' => 'Civil works',
        ]));
    }

    public function test_full_valid_profile_passes(): void
    {
        $this->assertFalse($this->fails([
            'company_name' => 'Acme', 'category' => 'Construction',
            'contact_person' => 'Ravi', 'contact_email' => 'ravi@acme.com',
            'authorized_name' => 'Ravi Menon', 'authorized_email' => 'auth@acme.com',
            'bank_account_holder' => 'Acme', 'bank_account_number' => '000123456789', 'bank_ifsc' => 'HDFC0001234',
            'bank_account_type' => 'Current',
            'gst_number' => $this->validGstin(), 'pan_number' => 'AAAPL1234C',
            'city' => 'Pune', 'state' => 'MH', 'country' => 'India', 'pincode' => '411001',
        ]));
    }

    public function test_invalid_gstin_fails(): void
    {
        $this->assertTrue($this->fails(['gst_number' => '27AAPFU0939F1ZZ'])); // bad checksum/format
    }

    public function test_invalid_pan_fails(): void
    {
        $this->assertTrue($this->fails(['pan_number' => 'AAA1234567']));
    }

    public function test_invalid_ifsc_fails(): void
    {
        $this->assertTrue($this->fails(['bank_account_number' => '000123456789', 'bank_ifsc' => 'BADIFSC']));
    }

    public function test_account_number_must_be_9_to_18_digits(): void
    {
        $this->assertTrue($this->fails(['bank_account_number' => '123', 'bank_ifsc' => 'HDFC0001234']));
        $this->assertTrue($this->fails(['bank_account_number' => '12AB56789', 'bank_ifsc' => 'HDFC0001234']));
    }

    public function test_ifsc_and_account_are_mutually_required(): void
    {
        $this->assertTrue($this->fails(['bank_account_number' => '000123456789'])); // ifsc missing
        $this->assertTrue($this->fails(['bank_ifsc' => 'HDFC0001234']));            // account missing
    }

    public function test_bad_pincode_and_account_type_fail(): void
    {
        $this->assertTrue($this->fails(['pincode' => '4110']));
        $this->assertTrue($this->fails(['bank_account_type' => 'Fixed']));
    }
}
