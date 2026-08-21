<?php

namespace Tests\Unit\Rules;

use App\Rules\Pan;
use App\Rules\PhoneNumber;
use App\Rules\Pincode;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/** The shape rules that replace seven inconsistent `string|max:N` phone rules. */
class IdentifierRulesTest extends TestCase
{
    private function passes(string $field, $value, $rule): bool
    {
        return Validator::make([$field => $value], [$field => [$rule]])->passes();
    }

    public function test_phone_accepts_real_numbers_in_the_shapes_people_type(): void
    {
        foreach (['9820045678', '98200 45678', '(0982) 004-5678', '+91 98200 45678', '+1 415 555 0132'] as $ok) {
            $this->assertTrue($this->passes('phone', $ok, new PhoneNumber()), "should accept: $ok");
        }
    }

    public function test_phone_rejects_what_the_old_rules_let_through(): void
    {
        foreach (['aaaaaaa', '12345', '98200456789', '', '   '] as $bad) {
            if (trim($bad) === '') {
                // blank is nullable's job, not this rule's
                $this->assertTrue($this->passes('phone', $bad, new PhoneNumber()));
                continue;
            }
            $this->assertFalse($this->passes('phone', $bad, new PhoneNumber()), "should reject: $bad");
        }
    }

    /** A bare 11-digit string is a typo'd Indian number far more often than a foreign one. */
    public function test_phone_requires_a_plus_for_anything_longer_than_ten_digits(): void
    {
        $this->assertFalse($this->passes('phone', '919820045678', new PhoneNumber()));
        $this->assertTrue($this->passes('phone', '+919820045678', new PhoneNumber()));
    }

    public function test_pan_shape(): void
    {
        $this->assertTrue($this->passes('pan', 'ABCDE1234F', new Pan()));
        $this->assertTrue($this->passes('pan', 'abcde1234f', new Pan()), 'lower case is folded');
        foreach (['ABCD1234F', 'ABCDE12345', 'ABCDE1234', '1234567890'] as $bad) {
            $this->assertFalse($this->passes('pan', $bad, new Pan()), "should reject: $bad");
        }
    }

    public function test_pincode_shape(): void
    {
        $this->assertTrue($this->passes('pin', '400001', new Pincode()));
        foreach (['000000', '900001', '40001', '4000012', 'ABCDEF'] as $bad) {
            $this->assertFalse($this->passes('pin', $bad, new Pincode()), "should reject: $bad");
        }
    }
}
