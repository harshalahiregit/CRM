<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;

/**
 * Enterprise Salary Formula Engine.
 *
 * The single, reusable calculator every salary-consuming module reads through. Given
 * a set of salary items (each a component + a calculation), it resolves every line to
 * a concrete monthly amount and returns the professional CTC breakdown:
 *
 *     Earnings → Gross → Employer Contribution → CTC → Deductions → Net (In Hand)
 *
 * Calculation types:
 *   - Fixed      : a flat monthly amount
 *   - Percentage : `percentage`% of `based_on` (a component code/name, or GROSS)
 *   - Formula    : an arithmetic expression over other component codes + GROSS,
 *                  e.g. "50% GROSS", "12% BASIC", "4.81% BASIC", "BASIC + HRA"
 *   - Manual     : an amount entered by the user (same shape as Fixed)
 *
 * GROSS is a synthetic symbol = sum of all Earning lines. Dependencies are resolved
 * topologically; a circular reference (e.g. Basic depends on HRA which depends on
 * Basic) is detected and rejected before any evaluation. Amounts are monthly; yearly
 * is ×12. Pure and side-effect free — safe for live preview and for snapshots.
 *
 * Each input item is an array:
 *   ['key'=>mixed, 'code'=>string, 'name'=>string, 'type'=>Earning|Employer|Deduction|Benefit,
 *    'calculation_type'=>Fixed|Percentage|Formula|Manual, 'amount'=>?float,
 *    'percentage'=>?float, 'based_on'=>?string, 'formula'=>?string, 'sequence'=>?int]
 */
class SalaryFormulaEngine
{
    private const GROSS = 'GROSS';
    private const RESERVED = ['GROSS'];

    /** Alias map (UPPER code / UPPER name / UPPER name-without-spaces) => component code. */
    private array $alias = [];

    /**
     * Resolve items → ['resolved'=>[key=>amount], 'breakdown'=>[...enterprise shape...]].
     * Throws BusinessException on a circular reference or an unparseable formula.
     */
    public function calculate(array $items): array
    {
        $items = $this->normaliseItems($items);
        $this->alias = $this->buildAliasMap($items);
        $this->assertNoComponentCycle($items);       // real circular refs (A→B→A) rejected here
        $resolved = $this->resolveFixedPoint($items); // Gross-linked earnings converge iteratively

        $byKey = [];
        foreach ($items as $code => $item) {
            $byKey[$item['key']] = $resolved[$code] ?? 0.0;
        }
        return [
            'resolved'  => $byKey,
            'breakdown' => $this->breakdown($items, $resolved),
        ];
    }

    /** Live preview convenience — identical to calculate(), named for the API. */
    public function preview(array $items): array
    {
        return $this->calculate($items);
    }

    /**
     * Validate a single formula string against a set of known component codes.
     * Returns the uppercased dependency codes it references. Throws on bad syntax or
     * unknown identifiers.
     */
    public function validateFormula(string $formula, array $knownCodes): array
    {
        $this->alias = [];
        $deps = $this->extractDependencies($formula);
        $known = array_map('strtoupper', $knownCodes);
        foreach ($deps as $d) {
            if ($d !== self::GROSS && ! in_array($d, $known, true)) {
                throw new BusinessException("Formula references unknown component “{$d}”.");
            }
        }
        // Dry-run the arithmetic with every identifier set to 1 to catch syntax errors.
        $this->evalExpression($this->substitute($formula, array_fill_keys(array_merge($deps, [self::GROSS]), 1.0)));

        return $deps;
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /** Index items by uppercased code and fill sane defaults. */
    private function normaliseItems(array $rawItems): array
    {
        $items = [];
        foreach (array_values($rawItems) as $i => $raw) {
            $code = strtoupper(trim((string) ($raw['code'] ?? '')));
            if ($code === '') {
                $code = 'CMP'.$i;   // structures should always carry a code; guard anyway
            }
            if (in_array($code, self::RESERVED, true)) {
                throw new BusinessException("“{$code}” is a reserved keyword and cannot be a component code.");
            }
            if (isset($items[$code])) {
                throw new BusinessException("Duplicate component “{$code}” in the salary structure.");
            }
            $items[$code] = [
                'key'              => $raw['key'] ?? $i,
                'code'             => $code,
                'name'             => $raw['name'] ?? $code,
                'type'             => $raw['type'] ?? 'Earning',
                'calculation_type' => $raw['calculation_type'] ?? 'Fixed',
                'amount'           => isset($raw['amount']) && $raw['amount'] !== '' ? (float) $raw['amount'] : null,
                'percentage'       => isset($raw['percentage']) && $raw['percentage'] !== '' ? (float) $raw['percentage'] : null,
                'based_on'         => $raw['based_on'] ?? null,
                'formula'          => $raw['formula'] ?? null,
                'sequence'         => (int) ($raw['sequence'] ?? $i),
            ];
        }

        return $items;
    }

    /** Build the alias map so `based_on`/formula tokens resolve by code OR name. */
    private function buildAliasMap(array $items): array
    {
        $map = [];
        foreach ($items as $code => $item) {
            $map[strtoupper($code)] = $code;
            $name = strtoupper(trim((string) ($item['name'] ?? '')));
            if ($name !== '') {
                $map[$name] = $code;
                $map[str_replace(' ', '', $name)] = $code;
            }
        }

        return $map;
    }

    /** Resolve a reference token (code/name/GROSS) to its canonical component code. */
    private function toCode(string $ref): string
    {
        $u = strtoupper(trim($ref));
        if ($u === self::GROSS) {
            return self::GROSS;
        }

        return $this->alias[$u] ?? $this->alias[str_replace(' ', '', $u)] ?? $u;
    }

    /** Dependencies of one item as canonical component codes / GROSS. */
    private function dependenciesOf(array $item): array
    {
        $calc = $item['calculation_type'];
        if ($calc === 'Percentage') {
            $base = trim((string) ($item['based_on'] ?: 'BASIC'));

            return $base === '' ? [] : [$this->toCode($base)];
        }
        if ($calc === 'Formula') {
            return array_map(fn ($d) => $this->toCode($d), $this->extractDependencies((string) ($item['formula'] ?? '')));
        }

        return []; // Fixed / Manual depend on nothing
    }

    /**
     * Reject a *component-level* circular reference (e.g. Basic = 40% HRA and
     * HRA = 50% Basic). The synthetic GROSS symbol is deliberately NOT a node here:
     * an earning defined as a percentage of GROSS (Basic = 50% GROSS) is a legitimate,
     * solvable relationship handled by fixed-point iteration — not a cycle. Only edges
     * between real components are considered. DFS with a recursion stack finds a cycle.
     */
    private function assertNoComponentCycle(array $items): void
    {
        $adj = [];
        foreach ($items as $code => $item) {
            $adj[$code] = array_values(array_filter(
                $this->dependenciesOf($item),
                fn ($dep) => $dep !== self::GROSS && isset($items[$dep])
            ));
        }

        $state = [];  // code => 0 unvisited | 1 in-stack | 2 done
        $walk = function ($node, array $path) use (&$walk, $adj, &$state) {
            $state[$node] = 1;
            $path[] = $node;
            foreach ($adj[$node] ?? [] as $next) {
                if (($state[$next] ?? 0) === 1) {
                    $cycle = array_slice($path, array_search($next, $path, true));
                    $cycle[] = $next;
                    throw new BusinessException('Circular salary formula detected: '.implode(' → ', $cycle).'.');
                }
                if (($state[$next] ?? 0) === 0) {
                    $walk($next, $path);
                }
            }
            $state[$node] = 2;
        };

        foreach (array_keys($adj) as $code) {
            if (($state[$code] ?? 0) === 0) {
                $walk($code, []);
            }
        }
    }

    /**
     * Fixed-point resolution. Start every line at 0, then repeatedly recompute GROSS
     * (sum of earnings) and each line from the current values until the largest change
     * settles below a cent. Linear salary systems (Basic = 50% Gross, HRA = 40% Basic,
     * PF = 12% Basic …) converge quickly; a well-defined structure is stable. Returns
     * [componentCode => amount] plus GROSS.
     */
    private function resolveFixedPoint(array $items): array
    {
        $resolved = array_fill_keys(array_keys($items), 0.0);
        $resolved[self::GROSS] = 0.0;

        for ($pass = 0; $pass < 200; $pass++) {
            $resolved[self::GROSS] = $this->sumEarnings($items, $resolved);
            $maxDelta = 0.0;
            foreach ($items as $code => $item) {
                $val = $this->evaluateItem($item, $resolved);
                $maxDelta = max($maxDelta, abs($val - $resolved[$code]));
                $resolved[$code] = $val;
            }
            $resolved[self::GROSS] = $this->sumEarnings($items, $resolved);
            if ($pass > 0 && $maxDelta < 0.005) {
                break;
            }
        }

        foreach ($items as $code => $item) {
            $resolved[$code] = round($resolved[$code], 2);
        }
        $resolved[self::GROSS] = $this->sumEarnings($items, $resolved);

        return $resolved;
    }

    private function sumEarnings(array $items, array $resolved): float
    {
        $sum = 0.0;
        foreach ($items as $code => $item) {
            if (($item['type'] ?? '') === 'Earning') {
                $sum += $resolved[$code] ?? 0.0;
            }
        }

        return round($sum, 2);
    }

    private function evaluateItem(array $item, array $resolved): float
    {
        switch ($item['calculation_type']) {
            case 'Fixed':
            case 'Manual':
                return (float) ($item['amount'] ?? 0);

            case 'Percentage':
                $base = $this->toCode((string) ($item['based_on'] ?: 'BASIC'));
                $baseVal = $resolved[$base] ?? 0.0;

                return (float) ($item['percentage'] ?? 0) / 100 * $baseVal;

            case 'Formula':
                $expr = $this->substitute((string) ($item['formula'] ?? '0'), $resolved);

                return $this->evalExpression($expr);

            default:
                return (float) ($item['amount'] ?? 0);
        }
    }

    /** Assemble the professional Earnings→Gross→Employer→CTC→Deductions→Net breakdown. */
    private function breakdown(array $items, array $resolved): array
    {
        $sorted = $items;
        uasort($sorted, fn ($a, $b) => $a['sequence'] <=> $b['sequence']);

        $line = function (array $item) use ($resolved) {
            $m = round($resolved[$item['code']] ?? 0.0, 2);

            return [
                'code'    => $item['code'],
                'name'    => $item['name'],
                'type'    => $item['type'],
                'monthly' => $m,
                'yearly'  => round($m * 12, 2),
            ];
        };

        $earnings = $employer = $deductions = [];
        $grossM = $employerM = $dedM = 0.0;
        foreach ($sorted as $item) {
            $row = $line($item);
            switch ($item['type']) {
                case 'Earning':
                    $earnings[] = $row; $grossM += $row['monthly']; break;
                case 'Employer':
                case 'Benefit':   // legacy — treated as employer contribution
                    $employer[] = $row; $employerM += $row['monthly']; break;
                case 'Deduction':
                    $deductions[] = $row; $dedM += $row['monthly']; break;
            }
        }
        $grossM = round($grossM, 2);
        $employerM = round($employerM, 2);
        $dedM = round($dedM, 2);
        $ctcM = round($grossM + $employerM, 2);
        $netM = round($grossM - $dedM, 2);

        $tot = fn ($m) => ['monthly' => round($m, 2), 'yearly' => round($m * 12, 2)];

        return [
            'earnings'              => $earnings,
            'employer'              => $employer,
            'deductions'            => $deductions,
            'gross_salary'          => $tot($grossM),
            'employer_contribution' => $tot($employerM),
            'ctc'                   => $tot($ctcM),
            'total_deduction'       => $tot($dedM),
            'net_salary'            => $tot($netM),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Tiny safe arithmetic evaluator (+ - * / parentheses, and "N%" shorthand)
    |--------------------------------------------------------------------------
    */

    /** Pull the identifier tokens (component codes / GROSS) a formula references. */
    private function extractDependencies(string $formula): array
    {
        preg_match_all('/[A-Za-z_][A-Za-z0-9_]*/', $formula, $m);

        return array_values(array_unique(array_map('strtoupper', $m[0] ?? [])));
    }

    /**
     * Replace identifiers with their resolved values and expand the "N%" shorthand
     * ("50% GROSS" → "50/100*(value)"), yielding a pure arithmetic string.
     */
    private function substitute(string $formula, array $resolved): string
    {
        // Expand a percent sign into "/100" plus implicit multiplication when it is
        // directly followed by a value/identifier/paren: "50% GROSS" → "50/100* GROSS".
        $expr = preg_replace('/%\s*(?=[0-9A-Za-z_(])/', '/100*', $formula);
        $expr = preg_replace('/%/', '/100', $expr); // a trailing percent → just /100

        // Replace identifiers (mapped through the alias table to their component code).
        $expr = preg_replace_callback('/[A-Za-z_][A-Za-z0-9_]*/', function ($mm) use ($resolved) {
            $code = $this->toCode($mm[0]);

            return (string) (float) ($resolved[$code] ?? 0.0);
        }, $expr);

        return $expr;
    }

    /** Recursive-descent evaluator for + - * / and parentheses. No eval(), no globals. */
    private function evalExpression(string $expr): float
    {
        $tokens = $this->tokenize($expr);
        $pos = 0;
        $value = $this->parseExpr($tokens, $pos);
        if ($pos !== count($tokens)) {
            throw new BusinessException('Invalid salary formula syntax.');
        }

        return $value;
    }

    private function tokenize(string $expr): array
    {
        preg_match_all('/\d+(?:\.\d+)?|[()+\-*\/]/', $expr, $m);

        return $m[0] ?? [];
    }

    private function parseExpr(array $tokens, int &$pos): float
    {
        $value = $this->parseTerm($tokens, $pos);
        while ($pos < count($tokens) && ($tokens[$pos] === '+' || $tokens[$pos] === '-')) {
            $op = $tokens[$pos++];
            $rhs = $this->parseTerm($tokens, $pos);
            $value = $op === '+' ? $value + $rhs : $value - $rhs;
        }

        return $value;
    }

    private function parseTerm(array $tokens, int &$pos): float
    {
        $value = $this->parseFactor($tokens, $pos);
        while ($pos < count($tokens) && ($tokens[$pos] === '*' || $tokens[$pos] === '/')) {
            $op = $tokens[$pos++];
            $rhs = $this->parseFactor($tokens, $pos);
            if ($op === '/') {
                $value = $rhs == 0.0 ? 0.0 : $value / $rhs;
            } else {
                $value *= $rhs;
            }
        }

        return $value;
    }

    private function parseFactor(array $tokens, int &$pos): float
    {
        if ($pos >= count($tokens)) {
            throw new BusinessException('Invalid salary formula syntax.');
        }
        $tok = $tokens[$pos];
        if ($tok === '(') {
            $pos++;
            $value = $this->parseExpr($tokens, $pos);
            if (($tokens[$pos] ?? null) !== ')') {
                throw new BusinessException('Unbalanced parentheses in salary formula.');
            }
            $pos++;

            return $value;
        }
        if ($tok === '-') { $pos++; return -$this->parseFactor($tokens, $pos); }
        if ($tok === '+') { $pos++; return $this->parseFactor($tokens, $pos); }
        if (is_numeric($tok)) { $pos++; return (float) $tok; }

        throw new BusinessException('Invalid salary formula syntax.');
    }
}
