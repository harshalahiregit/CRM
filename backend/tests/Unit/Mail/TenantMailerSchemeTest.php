<?php

namespace Tests\Unit\Mail;

use App\Models\TenantMailSetting;
use App\Services\Mail\TenantMailer;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Guards the SMTP encryption→scheme mapping. Laravel 12 only derives the
 * implicit-TLS scheme `smtps` from encryption when encryption==='tls' && port===465;
 * a plain 'ssl' setting would otherwise fall back to plaintext 'smtp' and fail
 * (the Hostinger SSL/465 bug). TenantMailer::smtpScheme() fixes that.
 */
class TenantMailerSchemeTest extends TestCase
{
    /** @dataProvider schemeCases */
    public function test_smtp_scheme_mapping(?string $encryption, ?int $port, string $expected): void
    {
        $this->assertSame($expected, TenantMailer::smtpScheme($encryption, $port));
    }

    public static function schemeCases(): array
    {
        return [
            'ssl 465 → implicit TLS'      => ['ssl', 465, 'smtps'],
            'ssl 587 → still implicit'    => ['ssl', 587, 'smtps'],
            'tls 587 → STARTTLS'          => ['tls', 587, 'smtp'],
            'tls 465 → implicit TLS'      => ['tls', 465, 'smtps'],
            'none → plain'                => ['none', 25, 'smtp'],
            'null → plain'                => [null, 587, 'smtp'],
            'uppercase SSL normalised'    => ['SSL', 465, 'smtps'],
        ];
    }

    /** The runtime `tenant` mailer must carry the resolved scheme. */
    public function test_configure_mailer_sets_scheme_for_ssl_465(): void
    {
        $settings = new TenantMailSetting([
            'host' => 'smtp.hostinger.com', 'port' => 465, 'encryption' => 'ssl',
            'username' => 'u@example.com', 'password' => 'secret', 'from_email' => 'u@example.com',
            'enabled' => true,
        ]);

        $m = new ReflectionMethod(TenantMailer::class, 'configureMailer');
        $m->setAccessible(true);
        $mailerName = $m->invoke(new TenantMailer(), $settings);

        $this->assertSame('tenant', $mailerName);
        $this->assertSame('smtps', config('mail.mailers.tenant.scheme'));
        $this->assertSame(465, config('mail.mailers.tenant.port'));
    }

    public function test_configure_mailer_sets_starttls_scheme_for_tls_587(): void
    {
        $settings = new TenantMailSetting([
            'host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'tls',
            'username' => 'u@example.com', 'password' => 'secret', 'from_email' => 'u@example.com',
            'enabled' => true,
        ]);

        $m = new ReflectionMethod(TenantMailer::class, 'configureMailer');
        $m->setAccessible(true);
        $m->invoke(new TenantMailer(), $settings);

        $this->assertSame('smtp', config('mail.mailers.tenant.scheme'));
    }
}
