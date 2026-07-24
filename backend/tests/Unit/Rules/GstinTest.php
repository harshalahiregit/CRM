<?php

namespace Tests\Unit\Rules;

use App\Rules\Gstin;
use PHPUnit\Framework\TestCase;

class GstinTest extends TestCase
{
    private function fails(string $value): bool
    {
        $failed = false;
        (new Gstin())->validate('gst', $value, function () use (&$failed) { $failed = true; });

        return $failed;
    }

    public function test_a_self_consistent_gstin_passes(): void
    {
        $first14 = '27AAPFU0939F1Z';
        $valid = $first14.Gstin::checksumChar($first14);

        $this->assertFalse($this->fails($valid), "Expected {$valid} to be valid");
    }

    public function test_lowercase_is_accepted(): void
    {
        $first14 = '27AAPFU0939F1Z';
        $valid = strtolower($first14.Gstin::checksumChar($first14));

        $this->assertFalse($this->fails($valid));
    }

    public function test_wrong_checksum_fails(): void
    {
        $first14 = '27AAPFU0939F1Z';
        $check = Gstin::checksumChar($first14);
        $wrong = $check === 'A' ? 'B' : 'A';

        $this->assertTrue($this->fails($first14.$wrong));
    }

    public function test_bad_format_fails(): void
    {
        $this->assertTrue($this->fails('INVALID'));
        $this->assertTrue($this->fails('27AAPFU0939F1'));       // 13 chars
        $this->assertTrue($this->fails('AA1AAPFU0939F1ZV'));    // letters where digits expected
        $this->assertTrue($this->fails(''));
    }

    public function test_checksum_char_is_deterministic(): void
    {
        $this->assertSame(Gstin::checksumChar('27AAPFU0939F1Z'), Gstin::checksumChar('27AAPFU0939F1Z'));
    }
}
