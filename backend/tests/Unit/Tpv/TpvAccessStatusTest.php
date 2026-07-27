<?php

namespace Tests\Unit\Tpv;

use App\Support\Tpv\TpvAccessStatus as Access;
use PHPUnit\Framework\TestCase;

class TpvAccessStatusTest extends TestCase
{
    private const DAY = 86400;

    public function test_band_thresholds(): void
    {
        $this->assertSame('green', Access::band(4 * self::DAY));       // > 3 days
        $this->assertSame('orange', Access::band(3 * self::DAY));      // ≤ 3 days
        $this->assertSame('orange', Access::band(self::DAY + 1));      // > 24h, ≤ 3d
        $this->assertSame('red', Access::band(self::DAY));             // ≤ 24h
        $this->assertSame('red', Access::band(60));                    // < 24h
        $this->assertSame('expired', Access::band(0));                 // 0
        $this->assertSame('expired', Access::band(-100));              // past
    }

    public function test_derive_status(): void
    {
        $this->assertSame(Access::ACTIVE,    Access::derive(Access::ACTIVE, 4 * self::DAY));
        $this->assertSame(Access::EXPIRING,  Access::derive(Access::ACTIVE, self::DAY));       // < 24h
        $this->assertSame(Access::EXPIRING,  Access::derive(Access::ACTIVE, 3600));
        $this->assertSame(Access::EXPIRED,   Access::derive(Access::ACTIVE, 0));
        $this->assertSame(Access::EXPIRED,   Access::derive(Access::ACTIVE, -1));
        // A force-expired vendor stays Expired even with time left on the clock.
        $this->assertSame(Access::EXPIRED,   Access::derive(Access::EXPIRED, 5 * self::DAY));
    }

    public function test_converted_is_terminal(): void
    {
        // Once converted, remaining time is irrelevant.
        $this->assertSame(Access::CONVERTED, Access::derive(Access::CONVERTED, 10 * self::DAY));
        $this->assertSame(Access::CONVERTED, Access::derive(Access::CONVERTED, 0));
        $this->assertSame(Access::CONVERTED, Access::derive(Access::CONVERTED, -5));
    }

    public function test_label_and_validity(): void
    {
        $this->assertSame('Expiring', Access::label(Access::EXPIRING));
        $this->assertTrue(Access::isValid(Access::ACTIVE));
        $this->assertFalse(Access::isValid('Bogus'));
    }

    public function test_due_reminders(): void
    {
        // More than 7 days out — nothing due.
        $this->assertSame([], Access::dueReminders(8 * self::DAY, []));
        // 6 days left, none sent — only the 7d reminder is due.
        $this->assertSame(['7d'], Access::dueReminders(6 * self::DAY, []));
        // 6 days left, 7d already sent — nothing due.
        $this->assertSame([], Access::dueReminders(6 * self::DAY, ['7d']));
        // 12 hours left, 7d/3d/1d sent — 6h not reached yet, nothing due.
        $this->assertSame([], Access::dueReminders(12 * 3600, ['7d', '3d', '1d']));
        // 5 hours left, 7d/3d/1d sent — now the 6h reminder is due.
        $this->assertSame(['6h'], Access::dueReminders(5 * 3600, ['7d', '3d', '1d']));
        // 2 hours left, nothing sent — every threshold has been crossed.
        $this->assertSame(['7d', '3d', '1d', '6h'], Access::dueReminders(2 * 3600, []));
        // Expired — no reminders (expiry is handled separately).
        $this->assertSame([], Access::dueReminders(0, []));
    }
}
