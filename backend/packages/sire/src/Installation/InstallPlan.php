<?php

namespace Sire\Installation;

use Sire\Discovery\Finding;
use Sire\Discovery\HostProfile;
use Sire\Support\SireLoginType;

/**
 * SIRE — what the installer proposes to write, and what it must ask about first.
 *
 * Separated from the command so the decision-making is testable without a
 * terminal, and so "what would this change?" can be answered without changing
 * anything. An installer whose plan only exists while it is running cannot be
 * reviewed, dry-run, or re-run non-interactively.
 *
 * TWO KINDS OF SETTING, AND THE LINE BETWEEN THEM IS THE POINT
 *
 *   AUTOMATIC   High-confidence and harmless if wrong. The users table name;
 *               the display-name column; whether the host has a scheduler.
 *               Getting these wrong produces a visible, fixable annoyance.
 *
 *   CONFIRMED   Security-sensitive, whatever the confidence. The tenant source;
 *               the auth middleware; the four-login-type mapping. Getting these
 *               wrong is a data breach, not a bug, and no confidence score
 *               earns the right to skip the question.
 *
 * The installer NEVER writes a CONFIRMED value without an explicit answer —
 * not in interactive mode, and not in non-interactive mode either, where they
 * must be present in the config file or installation stops.
 */
final class InstallPlan
{
    /** @var array<string, mixed> config key => value */
    private array $automatic = [];

    /** @var array<string, array{value: mixed, question: string, why: string, finding: Finding}> */
    private array $confirmations = [];

    /** @var array<int, string> */
    private array $warnings = [];

    /** @var array<int, string> */
    private array $manualSteps = [];

    public function __construct(public readonly HostProfile $profile)
    {
        $this->build();
    }

    /** @return array<string, mixed> */
    public function automatic(): array
    {
        return $this->automatic;
    }

    /** @return array<string, array{value: mixed, question: string, why: string, finding: Finding}> */
    public function confirmations(): array
    {
        return $this->confirmations;
    }

    /** @return array<int, string> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /** @return array<int, string> */
    public function manualSteps(): array
    {
        return $this->manualSteps;
    }

    private function build(): void
    {
        $this->buildIdentity();
        $this->buildTenancy();
        $this->buildAuth();
        $this->buildLoginTypes();
        $this->buildFrontend();
        $this->buildOptional();
    }

    // ---------------------------------------------------------------- identity

    private function buildIdentity(): void
    {
        foreach ([
            'user.model'      => 'user.model',
            'user.table'      => 'user.table',
            'user.id_field'   => 'user.id_field',
            'user.name_field' => 'user.name_field',
            'user.role_field' => 'user.role_field',
        ] as $profileKey => $configKey) {
            $finding = $this->profile->get($profileKey);

            // Automatic only at HIGH. A MEDIUM display-name column means SIRE
            // saw two candidates, and picking one silently is how a timeline
            // ends up showing usernames where the host shows full names.
            if ($finding->isAutoApplicable()) {
                $this->automatic["sire.{$configKey}"] = $finding->value;
            } elseif ($finding->isPresent()) {
                $this->automatic["sire.{$configKey}"] = $finding->value;
                $this->warnings[] = sprintf(
                    "%s set to '%s' (%s confidence). Alternatives: %s",
                    $configKey,
                    is_array($finding->value) ? implode(',', $finding->value) : $finding->value,
                    $finding->confidence,
                    $finding->alternatives === [] ? 'none' : implode(', ', $finding->alternatives),
                );
            }
        }
    }

    // ---------------------------------------------------------------- tenancy

    private function buildTenancy(): void
    {
        $strategy = $this->profile->get('tenant.strategy');
        $attribute = $this->profile->get('tenant.attribute');

        if (! $strategy->isPresent()) {
            // Nothing found. This is a legitimate state — a single-tenant CRM,
            // or subdomain tenancy — so it becomes a question, not an error.
            $this->confirmations['sire.tenant.strategy'] = [
                'value'    => null,
                'question' => 'How does this application decide which tenant a request belongs to?',
                'why'      => implode("\n", [
                    'SIRE found no tenant column, model or package.',
                    '',
                    '  user_attribute  the user carries the tenant id (tenant_id, organization_id, …)',
                    '  relationship    the user belongs to a tenant model',
                    '  resolver        a service in your app answers it',
                    '  callable        a Class@method — for subdomain or header tenancy',
                    '  single_tenant   this application is NOT multi-tenant',
                    '',
                    'Getting this wrong returns another tenant\'s data on a page that looks normal.',
                ]),
                'finding'  => $strategy,
            ];

            return;
        }

        $this->confirmations['sire.tenant.strategy'] = [
            'value'    => $strategy->value,
            'question' => "Use tenant strategy '{$strategy->value}'?",
            'why'      => implode("\n", array_merge(
                ['Evidence: '.implode('; ', $strategy->evidence)],
                $strategy->alternatives === [] ? [] : ['Alternatives: '.implode(', ', $strategy->alternatives)],
            )),
            'finding'  => $strategy,
        ];

        if ($strategy->value === 'user_attribute' && $attribute->isPresent()) {
            $this->confirmations['sire.tenant.attribute'] = [
                'value'    => $attribute->value,
                'question' => "Is '{$attribute->value}' the column that identifies the TENANT?",
                'why'      => implode("\n", array_filter([
                    'Evidence: '.implode('; ', $attribute->evidence),
                    $attribute->alternatives === []
                        ? null
                        : 'Other candidates: '.implode(', ', $attribute->alternatives),
                    '',
                    'Careful: a column like company_id may identify the CUSTOMER a user works for',
                    'rather than the tenant they belong to. Those look identical from the schema.',
                ])),
                'finding'  => $attribute,
            ];
        }

        if ($this->profile->has('tenant.model')) {
            $this->automatic['sire.tenant.model'] = $this->profile->value('tenant.model');
        }

        if ($this->profile->has('tenant.package')) {
            $this->manualSteps[] = sprintf(
                'A tenancy package (%s) manages tenants. Point sire.tenant.callable at its resolver, '
                .'or implement SireTenantProvider — see docs/TENANCY.md.',
                $this->profile->value('tenant.package'),
            );
        }
    }

    // ---------------------------------------------------------------- auth

    private function buildAuth(): void
    {
        $middleware = $this->profile->get('auth.middleware');

        $this->confirmations['sire.host.auth_middleware'] = [
            'value'    => $middleware->isPresent() ? $middleware->value : null,
            'question' => $middleware->isPresent()
                ? 'Authenticate SIRE routes with: '.implode(', ', (array) $middleware->value).'?'
                : 'Which middleware should authenticate SIRE routes?',
            'why'      => implode("\n", array_filter([
                $middleware->evidence === [] ? null : 'Evidence: '.implode('; ', $middleware->evidence),
                'Guards available: '.implode(', ', (array) $this->profile->value('auth.guards', [])),
                '',
                'SIRE refuses ALL traffic until this is set. That is deliberate: an engineering',
                'issue tracker on the open internet must not be reachable by leaving a key blank.',
            ])),
            'finding'  => $middleware,
        ];
    }

    // ---------------------------------------------------------------- roles

    private function buildLoginTypes(): void
    {
        $roles = (array) $this->profile->value('roles.available', []);

        if ($roles === []) {
            $this->manualSteps[] = 'No role names were readable. Set config(\'sire.login_types\') by hand — '
                .'until you do, NO account has SIRE access.';

            return;
        }

        foreach (SireLoginType::ALL as $type) {
            $finding = $this->profile->get("roles.{$type}");
            $proposed = (array) ($finding->isPresent() ? $finding->value : []);

            $this->confirmations["sire.login_types.{$type}"] = [
                'value'    => $proposed,
                'question' => sprintf(
                    'Which roles are %s? %s',
                    strtoupper(str_replace('_', ' ', $type)),
                    in_array($type, SireLoginType::ENGINEERING, true)
                        ? '(these GET SIRE access)'
                        : '(these get NO SIRE access)',
                ),
                'why'      => implode("\n", array_filter([
                    'Proposed: '.($proposed === [] ? 'none' : implode(', ', $proposed)),
                    'All roles found: '.implode(', ', $roles),
                    '',
                    in_array($type, SireLoginType::ENGINEERING, true)
                        ? 'Anyone here can read the defect backlog, including unfixed reproduction steps.'
                        : 'Anyone here is blocked from SIRE entirely.',
                ])),
                'finding'  => $finding,
            ];
        }

        $unclassified = (array) $this->profile->value('roles.unclassified', []);

        if ($unclassified !== []) {
            $this->warnings[] = sprintf(
                '%d role(s) matched no category and will have NO SIRE access: %s',
                count($unclassified),
                implode(', ', $unclassified),
            );
        }
    }

    // ---------------------------------------------------------------- frontend

    private function buildFrontend(): void
    {
        $type = $this->profile->get('frontend.type');

        if (! $type->isPresent()) {
            $this->manualSteps[] = 'No SPA detected. Use the Blade Report Issue widget, or call the API '
                .'directly — see docs/REPORT-ISSUE.md.';

            return;
        }

        if (! in_array($type->value, ['react', 'inertia-react'], true)) {
            $this->manualSteps[] = sprintf(
                "Frontend is %s. SIRE ships React components; use the Blade widget or call the API "
                .'from your own components — see docs/FRONTEND-INTEGRATION.md.',
                $type->value,
            );

            return;
        }

        $this->manualSteps[] = 'Publish the SPA (php artisan vendor:publish --tag=sire-frontend), register '
            .'SireHost.configure({...}) once, and mount <ReportIssueRoot /> in your authenticated layout — '
            .'see docs/FRONTEND-INTEGRATION.md.';
    }

    // ---------------------------------------------------------------- optional

    private function buildOptional(): void
    {
        foreach (['audit', 'notes', 'settings', 'attachments', 'knowledge'] as $area) {
            if ($this->profile->has("integrations.{$area}")) {
                $this->manualSteps[] = sprintf(
                    'Optional: a %s system may exist here. SIRE uses its own unless you write '
                    .'Sire%sProvider — see docs/ADAPTERS.md.',
                    $area,
                    ucfirst($area === 'settings' ? 'Settings' : rtrim($area, 's')),
                );
            }
        }

        if (! $this->profile->value('scheduler.host_uses_scheduler', false)) {
            $this->warnings[] = 'No scheduled tasks found. Without cron running schedule:run, SIRE still '
                .'computes SLA state on read — only proactive warning/breach notices are lost.';
        }
    }
}
