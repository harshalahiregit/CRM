<?php

namespace Tests\Unit\Rules;

use App\Rules\Ifsc;
use PHPUnit\Framework\TestCase;

class IfscTest extends TestCase
{
    private function fails(string $value): bool
    {
        $failed = false;
        (new Ifsc())->validate('ifsc', $value, function () use (&$failed) { $failed = true; });

        return $failed;
    }

    public function test_valid_ifsc_passes(): void
    {
        $this->assertFalse($this->fails('HDFC0001234'));
        $this->assertFalse($this->fails('SBIN0000456'));
        $this->assertFalse($this->fails('hdfc0001234')); // lowercase accepted
    }

    public function test_invalid_ifsc_fails(): void
    {
        $this->assertTrue($this->fails('HDFC1001234'));  // 5th char must be 0
        $this->assertTrue($this->fails('HDF0001234'));   // only 3 leading letters
        $this->assertTrue($this->fails('HDFC000123'));   // too short
        $this->assertTrue($this->fails('HDFC00012345')); // too long
        $this->assertTrue($this->fails(''));
    }
}
