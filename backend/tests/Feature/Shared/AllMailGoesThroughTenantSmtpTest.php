<?php

namespace Tests\Feature\Shared;

use Tests\TestCase;

/**
 * Every e-mail leaves through the tenant's own SMTP. None through the global mailer.
 *
 * `Mail::to(...)->send(...)` resolves `config('mail.default')`, and that is
 * `env('MAIL_MAILER', 'log')`. A production deployment runs `php artisan
 * config:cache`, which stops .env being read at all — so the literal default
 * wins and the mailer is **log**. Every message written to storage/logs, every
 * send reporting success, nobody told.
 *
 * That is not a hypothetical. "I assigned a task to the super admin and no mail
 * came" was exactly this: TaskNotifier called the global facade, so the mail was
 * filed to disk on live. Twenty call sites across Tasks, Helpdesk, Inventory, HR
 * and the scheduled commands did the same.
 *
 * The second reason matters just as much even when MAIL_MAILER *is* set: mail
 * then leaves from the .env account rather than the tenant's own domain, fails
 * SPF/DKIM, and lands in spam.
 *
 * So: TenantMailer is the only way out. It resolves the tenant's Settings →
 * Email transport and refuses outright when none is configured — a visible
 * failure instead of a silent log line.
 */
class AllMailGoesThroughTenantSmtpTest extends TestCase
{
    /** Facade calls that pick the transport from config rather than the tenant. */
    private const BANNED = [
        'Mail::to(',
        'Mail::send(',
        'Mail::raw(',
        'Mail::html(',
        'Mail::queue(',
        'Mail::later(',
    ];

    /**
     * TenantMailer is the one place allowed to reach the facade — it calls
     * `Mail::mailer($name)` after building that mailer from tenant settings.
     */
    private const ALLOWED_FILES = [
        'app/Services/Mail/TenantMailer.php',
    ];

    /** @return string[] */
    private function phpFilesUnderApp(): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $out[] = $file->getPathname();
            }
        }

        sort($out);

        return $out;
    }

    /**
     * Comments describing the bug must not trip the guard.
     *
     * Several of the converted call sites carry a note explaining what the old
     * line did and why it failed. Stripping comments with the tokenizer means
     * the guard reads code, not prose about code — the same trick the other
     * source guards in this suite use, and for the same reason: one of them
     * once failed on its own explanation.
     */
    private function codeWithoutComments(string $path): string
    {
        $out = '';

        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $out .= $token[1];
            } else {
                $out .= $token;
            }
        }

        return $out;
    }

    public function test_no_code_sends_through_the_global_mailer(): void
    {
        $allowed = array_map(fn ($p) => str_replace('/', DIRECTORY_SEPARATOR, base_path($p)), self::ALLOWED_FILES);
        $offenders = [];

        foreach ($this->phpFilesUnderApp() as $path) {
            if (in_array($path, $allowed, true)) {
                continue;
            }

            $code = $this->codeWithoutComments($path);

            foreach (self::BANNED as $needle) {
                if (str_contains($code, $needle)) {
                    $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path).' → '.$needle;
                }
            }
        }

        $this->assertSame([], $offenders,
            "These send through config('mail.default'), which is env('MAIL_MAILER', 'log') — on a "
            ."config:cache'd deployment that is the literal 'log' and the message is written to a "
            ."file while the send reports success. Use TenantMailer::send() or ::sendRawHtml() "
            ."instead:\n  ".implode("\n  ", $offenders));
    }

    /**
     * And the mailer itself must keep refusing when nothing is configured.
     *
     * A fallback to the global mailer here would reinstate the whole problem in
     * one line, and it would do it invisibly.
     */
    public function test_the_tenant_mailer_still_refuses_to_fall_back(): void
    {
        $src = (string) file_get_contents(app_path('Services/Mail/TenantMailer.php'));

        $this->assertStringContainsString('NO .env FALLBACK', $src,
            'the note explaining why TenantMailer must never fall through to the global mailer has '
            .'gone — check the behaviour went with it');

        $start = strpos($src, 'private function configureMailer');
        $this->assertNotFalse($start);

        $body = substr($src, $start, 1200);

        $this->assertStringContainsString('throw new BusinessException', $body,
            'TenantMailer no longer refuses when a tenant has no SMTP settings — mail would silently '
            .'resolve to the global mailer again');
    }
}
