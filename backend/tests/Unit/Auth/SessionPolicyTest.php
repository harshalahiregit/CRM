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

    public function test_a_max_of_zero_means_unlimited(): void
    {
        // This is the default, and the fix for people being signed out
        // whenever they opened a second browser or logged in on a phone.
        // Nothing is ever evicted, however many sessions are already active.
        $this->assertSame([], SessionService::evictions('multi', 0, [10]));
        $this->assertSame([], SessionService::evictions('multi', 0, range(1, 50)));

        // Negative is treated the same rather than producing a negative slice.
        $this->assertSame([], SessionService::evictions('multi', -1, [10, 11]));
    }

    public function test_an_explicit_cap_is_still_honoured(): void
    {
        // Unlimited must not leak into deployments that deliberately set a cap.
        $this->assertSame([10], SessionService::evictions('multi', 1, [10]));
        $this->assertSame([10, 11], SessionService::evictions('multi', 1, [10, 11]));
    }
}
