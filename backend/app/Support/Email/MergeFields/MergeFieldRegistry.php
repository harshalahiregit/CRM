<?php

namespace App\Support\Email\MergeFields;

use App\Services\Numbering\DocumentNumberServiceInterface;
use App\Services\Settings\SettingsFormatter;
use App\Services\Settings\SettingsService;
use App\Support\Email\MergeContext;

/**
 * The merge-field engine. Fields are resolved by registered resolvers — the
 * expansion loop knows nothing about any specific field, so new fields plug in via
 * register() (from a module service provider) without touching the engine.
 *
 * Syntax is {{ group.field }} (spaces optional). Deliberately double-brace so it
 * can never collide with the Document Numbering Engine's single-brace {TOKEN}.
 *
 * SECURITY: resolvers return raw values; this class escapes them when the context
 * mode is HTML. The template body itself is authored HTML and is NOT escaped — it
 * is sanitised on save by App\Support\HtmlSanitizer.
 *
 * Every tenant-derived value (company details, dates, currency, document numbers)
 * comes from the existing SettingsService / SettingsFormatter / DocumentNumber
 * services — this class never formats anything itself.
 */
class MergeFieldRegistry
{
    /** @var array<string, MergeFieldResolverInterface> */
    private array $resolvers = [];

    public function __construct(
        private SettingsService $settings,
        private SettingsFormatter $formatter,
        private DocumentNumberServiceInterface $numbers,
    ) {
        $this->registerBuiltins();
    }

    public function register(MergeFieldResolverInterface $resolver): void
    {
        $this->resolvers[strtolower($resolver->token())] = $resolver;
    }

    /** @return array<string, MergeFieldResolverInterface> */
    public function all(): array
    {
        return $this->resolvers;
    }

    public function tokens(): array
    {
        return array_keys($this->resolvers);
    }

    public function has(string $token): bool
    {
        return isset($this->resolvers[strtolower(trim($token))]);
    }

    /** Every {{token}} in a string, lowercased, in order of appearance. */
    public function tokensIn(?string $text): array
    {
        preg_match_all('/\{\{\s*([A-Za-z0-9_.]+)\s*\}\}/', (string) $text, $m);

        return array_map(fn ($t) => strtolower(trim($t)), $m[1] ?? []);
    }

    /** Tokens present in the text that nothing can resolve. */
    public function unknownTokensIn(?string $text): array
    {
        return array_values(array_unique(array_filter(
            $this->tokensIn($text),
            fn ($t) => ! $this->has($t),
        )));
    }

    /**
     * Expand every known token. Unknown tokens are replaced with an empty string
     * so a stray placeholder never leaks raw "{{...}}" into a customer's inbox —
     * validation surfaces them to the author instead.
     */
    public function expand(?string $text, MergeContext $context): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([A-Za-z0-9_.]+)\s*\}\}/',
            function (array $m) use ($context) {
                $token = strtolower(trim($m[1]));
                if (! $this->has($token)) {
                    return '';
                }

                $value = $this->resolvers[$token]->resolve($context);

                // Values are untrusted; the surrounding body is not.
                return $context->isHtml() ? e($value) : $value;
            },
            (string) $text,
        );
    }

    /** Merge-field catalogue for the UI browser, grouped. */
    public function catalogue(): array
    {
        $out = [];
        foreach ($this->resolvers as $token => $resolver) {
            $out[$resolver->group()][] = [
                'token'       => '{{'.$token.'}}',
                'description' => $resolver->description(),
            ];
        }
        ksort($out);

        return $out;
    }

    /** Realistic values so a preview looks like a real email. */
    public function sampleData(): array
    {
        return [
            'user'     => ['name' => 'Priya Sharma', 'email' => 'priya@example.com'],
            'employee' => ['name' => 'Rahul Verma', 'code' => 'SNE-2026-014', 'email' => 'rahul@example.com', 'designation' => 'Site Engineer'],
            'candidate' => ['name' => 'Anita Desai', 'email' => 'anita@example.com', 'position' => 'Project Manager', 'stage' => 'Interview'],
            'customer' => ['name' => 'Acme Industries', 'email' => 'accounts@acme.test'],
            'vendor'   => ['name' => 'Sunrise Supplies', 'email' => 'sales@sunrise.test'],
            'ticket'   => ['id' => '1042', 'subject' => 'Printer not working'],
            'document' => ['number' => 'INV-2026-001', 'type' => 'Invoice'],
            'subject'  => 'Sample subject',
            'body'     => 'Sample message body.',
            'status'   => 'Approved',
            'period'   => 'March 2026',
            'due_date' => '31/03/2026',
            'amount'   => 125000,
        ];
    }

    /* ── built-in fields ─────────────────────────────────────────────────── */

    private function registerBuiltins(): void
    {
        $add = function (string $token, string $group, string $desc, callable $fn, string $sample = '') {
            $this->register(new CallableMergeFieldResolver($token, $group, $desc, $fn, $sample));
        };

        // Entity fields — resolved from the caller-supplied data.
        foreach ([
            ['user.name', 'User', "The recipient user's name"],
            ['user.email', 'User', "The recipient user's email"],
            ['employee.name', 'Employee', "Employee's full name"],
            ['employee.code', 'Employee', 'Employee code'],
            ['employee.email', 'Employee', "Employee's email"],
            ['employee.designation', 'Employee', "Employee's designation"],
            ['candidate.name', 'Candidate', "Candidate's name"],
            ['candidate.email', 'Candidate', "Candidate's email"],
            ['candidate.position', 'Candidate', 'Position applied for'],
            ['candidate.stage', 'Candidate', 'Current recruitment stage'],
            ['customer.name', 'Customer', 'Customer / company name'],
            ['customer.email', 'Customer', "Customer's email"],
            ['vendor.name', 'Vendor', 'Vendor / supplier name'],
            ['vendor.email', 'Vendor', "Vendor's email"],
            ['ticket.id', 'Helpdesk', 'Ticket number'],
            ['ticket.subject', 'Helpdesk', 'Ticket subject'],
            ['document.number', 'Document', 'The document number (invoice, PO, …)'],
            ['document.type', 'Document', 'The document type'],
            ['subject', 'General', 'Context-supplied subject line'],
            ['body', 'General', 'Context-supplied message body'],
            ['status', 'General', 'Context-supplied status'],
            ['period', 'General', 'Context-supplied period (e.g. March 2026)'],
            ['due_date', 'General', 'Context-supplied due date'],
        ] as [$token, $group, $desc]) {
            $add($token, $group, $desc, fn (MergeContext $c) => $c->value($token));
        }

        // Company / tenant — from the General settings group (Increment B).
        $add('company.name', 'Company', 'Workspace company name',
            fn (MergeContext $c) => $c->value('company.name', (string) ($this->settings->get($c->tenantId, 'general', 'company_name') ?? config('app.name'))));
        $add('company.email', 'Company', 'Support email address',
            fn (MergeContext $c) => $c->value('company.email', (string) ($this->settings->get($c->tenantId, 'general', 'support_email') ?? '')));
        $add('company.phone', 'Company', 'Support phone number',
            fn (MergeContext $c) => $c->value('company.phone', (string) ($this->settings->get($c->tenantId, 'general', 'support_phone') ?? '')));
        $add('company.website', 'Company', 'Company website',
            fn (MergeContext $c) => $c->value('company.website', (string) ($this->settings->get($c->tenantId, 'general', 'website') ?? '')));
        $add('company.address', 'Company', 'Company address',
            fn (MergeContext $c) => $c->value('company.address', (string) ($this->settings->get($c->tenantId, 'general', 'address') ?? '')));
        $add('tenant.id', 'Company', 'Workspace id', fn (MergeContext $c) => (string) $c->tenantId);

        // Dates / currency — always through the Increment-C formatter.
        $add('date.today', 'Date & Time', "Today's date in the tenant's format",
            fn (MergeContext $c) => (string) $this->formatter->date($c->tenantId, now()));
        $add('date.now', 'Date & Time', "Current date and time in the tenant's format",
            fn (MergeContext $c) => (string) $this->formatter->dateTime($c->tenantId, now()));
        $add('time.now', 'Date & Time', "Current time in the tenant's format",
            fn (MergeContext $c) => (string) $this->formatter->time($c->tenantId, now()));
        $add('amount', 'Currency', 'Context-supplied amount, formatted as currency',
            fn (MergeContext $c) => $this->formatter->money($c->tenantId, $c->value('amount', '0')));
        $add('currency.code', 'Currency', 'Currency code (e.g. INR)',
            fn (MergeContext $c) => (string) ($this->formatter->currencyMeta($c->tenantId)['code'] ?? ''));
        $add('currency.symbol', 'Currency', 'Currency symbol (e.g. ₹)',
            fn (MergeContext $c) => (string) ($this->formatter->currencyMeta($c->tenantId)['symbol'] ?? ''));

        // Document number — PREVIEW only. Rendering an email must never consume a
        // number from the sequence; a real number is passed in via context instead.
        $add('document.next_number', 'Document', 'Next number for a document type (preview — never consumes)',
            function (MergeContext $c) {
                $type = $c->value('document.type_key');
                if ($type === '') {
                    return '';
                }
                try {
                    return $this->numbers->preview($c->tenantId, $type);
                } catch (\Throwable $e) {
                    return '';
                }
            });

        // URLs. These land inside href="" in most shipped templates, and escaping
        // does NOT stop a javascript:/data: scheme — the stored body was sanitised
        // before the value existed. So every URL value is scheme-checked here.
        $appUrl = fn () => rtrim((string) config('app.url'), '/');
        $url = fn (string $key, string $fallback) => fn (MergeContext $c) => self::safeUrl($c->value($key, $fallback), $fallback);

        $add('url.app', 'Links', 'Application URL', $url('url.app', $appUrl()));
        $add('url.login', 'Links', 'Login page URL', $url('url.login', $appUrl().'/auth/login'));
        $add('url.portal', 'Links', 'Portal URL for the recipient', $url('url.portal', $appUrl()));
        $add('url.reset', 'Links', 'Password reset URL', $url('url.reset', $appUrl().'/auth/forgot-password'));
        $add('url.verify', 'Links', 'Email verification URL', $url('url.verify', $appUrl()));
    }

    /**
     * Only http(s) and site-relative URLs may be merged. Anything else
     * (javascript:, data:, vbscript:, protocol-relative //host) falls back, so a
     * caller-supplied value can never become an executable link.
     */
    public static function safeUrl(string $value, string $fallback = ''): string
    {
        $trimmed = trim($value);
        // Strip control characters that browsers ignore inside a scheme.
        $probe = strtolower(preg_replace('/[\s\x00-\x1F]+/', '', $trimmed) ?? '');

        if ($trimmed === '') {
            return $fallback;
        }
        if (str_starts_with($probe, 'http://') || str_starts_with($probe, 'https://')) {
            return $trimmed;
        }
        // Site-relative links are safe; protocol-relative (//host) is not.
        if (str_starts_with($trimmed, '/') && ! str_starts_with($trimmed, '//')) {
            return $trimmed;
        }

        return $fallback;
    }
}
