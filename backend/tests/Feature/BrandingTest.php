<?php

namespace Tests\Feature;

use App\Support\Brand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Nothing that leaves this system is branded after a PHP framework.
 *
 * `config('app.name')` resolves to "Laravel" unless APP_NAME is set, and .env is
 * gitignored — so every PDF and e-mail the CRM issued carried the framework's
 * default name on it: contracts, minutes, offer letters, payslips, interview
 * invitations. All of them go to customers, vendors and candidates.
 *
 * The name has a real default now. This pins it, because the failure mode is
 * silent: a deploy without a .env, or somebody restoring the framework's
 * scaffolding, puts "Laravel" back on the paperwork and nothing complains.
 */
class BrandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_application_is_called_sangoe_os(): void
    {
        $this->assertSame('Sangoe OS', config('app.name'));
    }

    public function test_the_default_survives_a_missing_env(): void
    {
        // The value that matters is the fallback in config/app.php, not the one
        // in .env — .env is gitignored, so a fresh checkout has none.
        $config = file_get_contents(config_path('app.php'));

        $this->assertStringContainsString("env('APP_NAME', 'Sangoe OS')", $config,
            'the committed default must be Sangoe OS, or a deploy without .env is branded Laravel');
    }

    /**
     * Every Blade template that ships to a person, checked as text.
     *
     * Rendering all of them would need a fixture per template; the thing worth
     * pinning is simpler — that the word is not written into any of them.
     */
    public function test_no_pdf_or_email_template_mentions_laravel(): void
    {
        $offenders = [];

        foreach ([resource_path('views/pdf'), resource_path('views/emails')] as $dir) {
            if (! is_dir($dir)) {
                continue;
            }

            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));

            foreach ($files as $file) {
                if ($file->isDir() || $file->getExtension() !== 'php') {
                    continue;
                }
                if (stripos((string) file_get_contents($file->getRealPath()), 'laravel') !== false) {
                    $offenders[] = basename($file->getRealPath());
                }
            }
        }

        $this->assertSame([], $offenders,
            "these templates mention Laravel:\n  ".implode("\n  ", $offenders));
    }

    public function test_the_brand_mark_is_available_to_documents(): void
    {
        Brand::forget();

        $logo = Brand::logoDataUri();

        // Embedded, not linked: a PDF is read on machines that cannot reach this
        // server, and a mail client that fetches a remote image either blocks it
        // or reports the open back to us.
        $this->assertNotNull($logo, 'public/logo.png is missing — documents lose the mark');
        $this->assertStringStartsWith('data:image/', $logo);
    }

    public function test_the_mail_from_name_is_not_the_framework(): void
    {
        // MAIL_FROM_NAME follows APP_NAME in .env, so this is what a recipient
        // actually sees in their inbox next to the message.
        $this->assertNotSame('Laravel', config('mail.from.name'));
    }
}
