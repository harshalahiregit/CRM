<?php

namespace App\Support;

/**
 * Minimal, dependency-free User-Agent classification for audit context.
 *
 * We only need a coarse browser name and device class for the acknowledgement
 * trail — not a full UA-parsing library. Ordering matters: Edge/Opera UAs also
 * contain "Chrome", and Chrome UAs contain "Safari", so more specific tokens are
 * matched first.
 */
class UserAgentInfo
{
    public static function parse(?string $ua): array
    {
        $ua = (string) $ua;

        $browser = 'Other';
        foreach (['Edg' => 'Edge', 'OPR' => 'Opera', 'Chrome' => 'Chrome', 'Firefox' => 'Firefox', 'Safari' => 'Safari'] as $needle => $name) {
            if (stripos($ua, $needle) !== false) {
                $browser = $name;
                break;
            }
        }

        $device = 'Desktop';
        if (preg_match('/(iPad|Tablet)/i', $ua)) {
            $device = 'Tablet';
        } elseif (preg_match('/(Mobile|Android|iPhone)/i', $ua)) {
            $device = 'Mobile';
        }

        return ['browser' => $browser, 'device' => $device];
    }
}
