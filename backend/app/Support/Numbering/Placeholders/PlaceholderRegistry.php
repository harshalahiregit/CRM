<?php

namespace App\Support\Numbering\Placeholders;

use App\Services\Settings\SettingsService;
use App\Support\Numbering\NumberingContext;

/**
 * The placeholder engine. Tokens are resolved by registered resolvers — the
 * expansion loop knows nothing about any specific token, so new placeholders plug
 * in via register() (from a module service provider) without touching the engine.
 *
 * Tenant-derived values (financial-year boundary, company name) come from the
 * existing SettingsService/SettingRegistry rather than new configuration.
 */
class PlaceholderRegistry
{
    /** @var array<string, PlaceholderResolverInterface> */
    private array $resolvers = [];

    public function __construct(private SettingsService $settings)
    {
        $this->registerBuiltins();
    }

    public function register(PlaceholderResolverInterface $resolver): void
    {
        $this->resolvers[strtoupper($resolver->token())] = $resolver;
    }

    /** @return array<string, PlaceholderResolverInterface> */
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
        return isset($this->resolvers[strtoupper($token)]);
    }

    /** Every {TOKEN} found in a format string, uppercased, in order of appearance. */
    public function tokensIn(string $format): array
    {
        preg_match_all('/\{([A-Za-z_]+)\}/', $format, $m);

        return array_map('strtoupper', $m[1] ?? []);
    }

    /** Tokens present in the format that nothing can resolve. */
    public function unknownTokensIn(string $format): array
    {
        return array_values(array_unique(array_filter(
            $this->tokensIn($format),
            fn ($t) => ! $this->has($t),
        )));
    }

    /** Expand every known token; unknown tokens are left untouched (validation reports them). */
    public function expand(string $format, NumberingContext $context): string
    {
        return (string) preg_replace_callback(
            '/\{([A-Za-z_]+)\}/',
            function (array $m) use ($context) {
                $token = strtoupper($m[1]);

                return $this->has($token) ? $this->resolvers[$token]->resolve($context) : $m[0];
            },
            $format,
        );
    }

    /* ── built-in tokens ─────────────────────────────────────────────────── */

    private function registerBuiltins(): void
    {
        $add = fn (string $t, string $d, callable $fn) => $this->register(new CallablePlaceholderResolver($t, $d, $fn));

        $add('PREFIX', 'Configured prefix', fn (NumberingContext $c) => (string) $c->config('prefix', ''));
        $add('SUFFIX', 'Configured suffix', fn (NumberingContext $c) => (string) $c->config('suffix', ''));

        // The running number, zero-padded to the configured width.
        $add('NEXT', 'Sequence number, padded', function (NumberingContext $c) {
            $seq = (int) ($c->sequence ?? 0);
            $width = max(1, (int) $c->config('minimum_digits', 4));
            $pad = (string) $c->config('padding', '0');
            $pad = $pad === '' ? '0' : substr($pad, 0, 1);

            return str_pad((string) $seq, $width, $pad, STR_PAD_LEFT);
        });

        $add('YYYY', 'Four-digit year', fn (NumberingContext $c) => $c->at->format('Y'));
        $add('YY', 'Two-digit year', fn (NumberingContext $c) => $c->at->format('y'));
        $add('MM', 'Two-digit month', fn (NumberingContext $c) => $c->at->format('m'));
        $add('DD', 'Two-digit day', fn (NumberingContext $c) => $c->at->format('d'));

        // Financial year, honouring the tenant's configured FY start month.
        $add('FY', 'Financial year', fn (NumberingContext $c) => $this->financialYear($c));

        $add('TENANT', 'Tenant id', fn (NumberingContext $c) => (string) $c->tenantId);
        $add('COMPANY', 'Company name (General settings)', function (NumberingContext $c) {
            return $c->extra('company', (string) ($this->settings->get($c->tenantId, 'general', 'company_name') ?? ''));
        });
        // Supplied per call by the module allocating the number.
        $add('BRANCH', 'Branch code (caller-supplied)', fn (NumberingContext $c) => $c->extra('branch'));
        $add('DEPARTMENT', 'Department code (caller-supplied)', fn (NumberingContext $c) => $c->extra('department'));
    }

    /** e.g. 2026-27 (short) or 2026-2027 (long), based on the FY start month. */
    public function financialYear(NumberingContext $c): string
    {
        $startMonth = (int) ($this->settings->get($c->tenantId, 'numbering', 'fy_start_month') ?? 4);
        $startMonth = max(1, min(12, $startMonth));

        $year = (int) $c->at->format('Y');
        $startYear = (int) $c->at->format('n') >= $startMonth ? $year : $year - 1;
        $endYear = $startYear + 1;

        $format = (string) ($this->settings->get($c->tenantId, 'numbering', 'fy_format') ?? 'short');

        return $format === 'long'
            ? $startYear.'-'.$endYear
            : $startYear.'-'.substr((string) $endYear, -2);
    }
}
