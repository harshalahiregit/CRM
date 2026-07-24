<?php

namespace Tests\Unit\Auth;

use App\Services\Auth\SessionService;
use PHPUnit\Framework\TestCase;

/**
 * The pure concurrency-policy rule: given the active sessions (oldest first)
 * before a new login, which are evicted?
 */
class SessionPolicyTest extends TestCase
{
    public function test_single_policy_evicts_all_prior_sessions(): void
    {
        $this->assertSame([10, 11, 12], SessionService::evictions('single', 1, [10, 11, 12]));
        $this->assertSame([], SessionService::evictions('single', 1, []));
    }

    public function test_multi_policy_keeps_up_to_max_devices(): void
    {
        // max 2, one active → adding a second is fine, nothing evicted.
        $this->assertSame([], SessionService::evictions('multi', 2, [10]));
        // max 2, two active → adding a third evicts the oldest (10).
        $this->assertSame([10], SessionService::evictions('multi', 2, [10, 11]));
        // max 3, three active → adding a fourth evicts the oldest one.
        $this->assertSame([10], SessionService::evictions('multi', 3, [10, 11, 12]));
        // max 2, three active (drifted over) → evict the two oldest to land at 2.
        $this->assertSame([10, 11], SessionService::evictions('multi', 2, [10, 11, 12]));
    }

    public function test_multi_policy_treats_zero_max_as_one(): void
    {
        $this->assertSame([10], SessionService::evictions('multi', 0, [10]));
    }
}
