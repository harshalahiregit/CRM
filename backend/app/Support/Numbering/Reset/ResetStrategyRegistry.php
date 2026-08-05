<?php

namespace App\Support\Numbering\Reset;

use App\Services\Settings\SettingsService;
use App\Support\Numbering\NumberingContext;

/**
 * Resolves a reset rule key to its strategy. New rules register here (or at
 * runtime via register()) — the engine contains no switch over reset rules.
 */
class ResetStrategyRegistry
{
    /** @var array<string, ResetStrategyInterface> */
    private array $strategies = [];

    public function __construct(SettingsService $settings)
    {
        foreach ([
            new NeverResetStrategy(),
            new YearlyResetStrategy(),
            new FinancialYearResetStrategy($settings),
            new MonthlyResetStrategy(),
            new DailyResetStrategy(),
            new ManualResetStrategy(),
        ] as $strategy) {
            $this->register($strategy);
        }
    }

    public function register(ResetStrategyInterface $strategy): void
    {
        $this->strategies[$strategy->key()] = $strategy;
    }

    public function has(string $key): bool
    {
        return isset($this->strategies[$key]);
    }

    /** Falls back to 'never' so an unknown stored rule can never break generation. */
    public function get(string $key): ResetStrategyInterface
    {
        return $this->strategies[$key] ?? $this->strategies['never'];
    }

    public function keys(): array
    {
        return array_keys($this->strategies);
    }

    /** key => label, for the settings UI. */
    public function options(): array
    {
        $out = [];
        foreach ($this->strategies as $key => $s) {
            $out[$key] = $s->label();
        }

        return $out;
    }

    /**
     * The full period key the engine allocates against: the strategy's period plus
     * the config epoch, so an explicit reset always opens a fresh sequence row
     * instead of destroying the current one.
     */
    public function periodKeyFor(string $rule, NumberingContext $context): string
    {
        $epoch = (int) $context->config('epoch', 0);

        return $this->get($rule)->periodKey($context).':'.$epoch;
    }
}
