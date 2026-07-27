<?php

namespace Tests\Unit\Support;

use App\Support\UserAgentInfo;
use PHPUnit\Framework\TestCase;

class UserAgentInfoTest extends TestCase
{
    public function test_detects_chrome_on_desktop(): void
    {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';
        $info = UserAgentInfo::parse($ua);

        $this->assertSame('Chrome', $info['browser']);
        $this->assertSame('Desktop', $info['device']);
    }

    public function test_edge_wins_over_chrome_token(): void
    {
        $ua = 'Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/120.0 Safari/537.36 Edg/120.0';
        $this->assertSame('Edge', UserAgentInfo::parse($ua)['browser']);
    }

    public function test_detects_iphone_mobile_safari(): void
    {
        $ua = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
        $info = UserAgentInfo::parse($ua);

        $this->assertSame('Safari', $info['browser']);
        $this->assertSame('Mobile', $info['device']);
    }

    public function test_detects_ipad_tablet(): void
    {
        $ua = 'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Safari/604.1';
        $this->assertSame('Tablet', UserAgentInfo::parse($ua)['device']);
    }

    public function test_detects_firefox(): void
    {
        $ua = 'Mozilla/5.0 (X11; Linux x86_64; rv:121.0) Gecko/20100101 Firefox/121.0';
        $this->assertSame('Firefox', UserAgentInfo::parse($ua)['browser']);
    }

    public function test_handles_empty_and_null(): void
    {
        $this->assertSame(['browser' => 'Other', 'device' => 'Desktop'], UserAgentInfo::parse(null));
        $this->assertSame(['browser' => 'Other', 'device' => 'Desktop'], UserAgentInfo::parse(''));
    }
}
