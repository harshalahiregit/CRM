<?php

namespace App\Support\Hr;

/**
 * The canonical work-state vocabulary — the jurisdictions statutory rules are
 * keyed by (Professional Tax today; anything state-levied later).
 *
 * This exists so PT stops depending on `hr_employees.location`, which holds a CITY
 * ("Pune", "MUMBAI"). Keying tax rules off a city meant one rule per office and a
 * wrong deduction the moment a new city opened in an already-configured state.
 *
 * `normalize()` is the only way a state should ever enter the system. It absorbs
 * the messy real-world spellings — case, padding, 2-letter codes, renamed states —
 * and returns ONE canonical name, or null when the input is not a state at all
 * (which is exactly what a leftover city value returns, by design).
 */
final class WorkStates
{
    /** code => canonical name. Codes are the ISO 3166-2:IN subdivision letters. */
    public const ALL = [
        'AP' => 'Andhra Pradesh',
        'AR' => 'Arunachal Pradesh',
        'AS' => 'Assam',
        'BR' => 'Bihar',
        'CG' => 'Chhattisgarh',
        'GA' => 'Goa',
        'GJ' => 'Gujarat',
        'HR' => 'Haryana',
        'HP' => 'Himachal Pradesh',
        'JH' => 'Jharkhand',
        'KA' => 'Karnataka',
        'KL' => 'Kerala',
        'MP' => 'Madhya Pradesh',
        'MH' => 'Maharashtra',
        'MN' => 'Manipur',
        'ML' => 'Meghalaya',
        'MZ' => 'Mizoram',
        'NL' => 'Nagaland',
        'OD' => 'Odisha',
        'PB' => 'Punjab',
        'RJ' => 'Rajasthan',
        'SK' => 'Sikkim',
        'TN' => 'Tamil Nadu',
        'TS' => 'Telangana',
        'TR' => 'Tripura',
        'UP' => 'Uttar Pradesh',
        'UK' => 'Uttarakhand',
        'WB' => 'West Bengal',
        // Union Territories — several levy PT in their own right.
        'AN' => 'Andaman and Nicobar Islands',
        'CH' => 'Chandigarh',
        'DH' => 'Dadra and Nagar Haveli and Daman and Diu',
        'DL' => 'Delhi',
        'JK' => 'Jammu and Kashmir',
        'LA' => 'Ladakh',
        'LD' => 'Lakshadweep',
        'PY' => 'Puducherry',
    ];

    /**
     * The states and UTs that actually levy Professional Tax.
     *
     * PT is a STATE levy and roughly a third of the country does not impose it
     * at all. The rule screen offered all 36 regardless, so Delhi sat in the
     * dropdown next to Maharashtra with nothing to say it collects no PT — which
     * was picked up on screen during the 3 Sep review: "यहां पे ये स्टेट नहीं
     * दिखना चाहिए... दिल्ली में एप्लीकेबल ही नहीं है".
     *
     * Offering a state that levies nothing invites somebody to configure a slab
     * for it, and a configured slab deducts. The absent ones are the point of
     * this list: Delhi, Haryana, Himachal Pradesh, Uttar Pradesh, Uttarakhand,
     * Rajasthan, Goa, Arunachal Pradesh, Jammu and Kashmir, Ladakh, Chandigarh,
     * Andaman and Nicobar, Dadra and Nagar Haveli, and Lakshadweep.
     *
     * KEEP THIS REVIEWED. A state can begin or repeal the levy in a budget, and
     * a stale list here silently stops a real deduction. It is a starting
     * position for the dropdown, not a statement of law: a state already
     * carrying a configured rule is still offered (see selectable()) so a
     * workspace is never locked out of a jurisdiction this list has not caught
     * up with.
     */
    public const PT_APPLICABLE = [
        'AP', 'AS', 'BR', 'CG', 'GJ', 'JH', 'KA', 'KL', 'MP', 'MH',
        'MN', 'ML', 'MZ', 'NL', 'OD', 'PB', 'SK', 'TN', 'TS', 'TR', 'WB', 'PY',
    ];

    /**
     * [['code' => 'MH', 'name' => 'Maharashtra'], …] for a given list of codes.
     *
     * @param  array<string>|null  $codes  null falls back to the seeded default
     */
    public static function ptOptions(?array $codes = null): array
    {
        $codes = $codes ?: self::PT_APPLICABLE;

        return array_values(array_map(
            fn ($code) => ['code' => $code, 'name' => self::ALL[$code]],
            array_filter($codes, fn ($code) => is_string($code) && isset(self::ALL[$code]))
        ));
    }

    /**
     * The configured PT states, plus any this workspace already has a rule for.
     *
     * The escape hatch for the paragraph above: if a state starts levying PT
     * before anybody updates the setting, a workspace that has configured it
     * keeps seeing it rather than losing the rule it is already applying.
     *
     * @param  array<string>       $codes             configured PT state codes
     * @param  array<string>       $configuredStates  canonical names already in use
     */
    public static function ptSelectable(array $codes = [], array $configuredStates = []): array
    {
        $options = self::ptOptions($codes ?: null);
        $have = array_column($options, 'name');

        foreach ($configuredStates as $name) {
            $canonical = self::normalize($name);
            if ($canonical && ! in_array($canonical, $have, true)) {
                $options[] = ['code' => array_search($canonical, self::ALL, true) ?: null, 'name' => $canonical];
                $have[] = $canonical;
            }
        }

        usort($options, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return $options;
    }

    /**
     * Historic and colloquial spellings that must resolve to a current name, so a
     * record entered years ago (or imported from another HRM) still matches a rule.
     *
     * Keys are already in key() form — lowercased, "&" spelled "and". Anything
     * key() alone turns into a canonical name ("Jammu & Kashmir") needs no entry.
     */
    private const ALIASES = [
        'orissa'                => 'Odisha',
        'pondicherry'           => 'Puducherry',
        'pondichery'            => 'Puducherry',
        'pondy'                 => 'Puducherry',
        'uttaranchal'           => 'Uttarakhand',
        'new delhi'             => 'Delhi',
        'nct of delhi'          => 'Delhi',
        'delhi ncr'             => 'Delhi',
        'j&k'                   => 'Jammu and Kashmir',
        'andaman and nicobar'   => 'Andaman and Nicobar Islands',
        'dadra and nagar haveli' => 'Dadra and Nagar Haveli and Daman and Diu',
        'daman and diu'         => 'Dadra and Nagar Haveli and Daman and Diu',
        'tamilnadu'             => 'Tamil Nadu',
        'chattisgarh'           => 'Chhattisgarh',
    ];

    /** Canonical names, alphabetical — the list the UI offers. */
    public static function names(): array
    {
        $names = array_values(self::ALL);
        sort($names);

        return $names;
    }

    /** [['code' => 'MH', 'name' => 'Maharashtra'], …] for a select. */
    public static function options(): array
    {
        $options = [];
        foreach (self::ALL as $code => $name) {
            $options[] = ['code' => $code, 'name' => $name];
        }
        usort($options, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return $options;
    }

    /**
     * The canonical name for any reasonable spelling, or null if it is not a state.
     *
     * Returning null for unrecognised input is the point: a stale city value must
     * NOT silently resolve to some state and deduct tax under it.
     */
    public static function normalize(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $key = self::key($value);

        // Exact code ("MH"), then canonical name, then a known alias.
        if (isset(self::ALL[strtoupper($value)])) {
            return self::ALL[strtoupper($value)];
        }
        foreach (self::ALL as $name) {
            if (self::key($name) === $key) {
                return $name;
            }
        }

        return self::ALIASES[$key] ?? null;
    }

    public static function isValid(?string $value): bool
    {
        return self::normalize($value) !== null;
    }

    /** Comparison key: lowercase, "and"/"&" unified, punctuation and spacing collapsed. */
    private static function key(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = str_replace(' & ', ' and ', $value);
        $value = preg_replace('/[^a-z& ]+/', '', $value);

        return preg_replace('/\s+/', ' ', trim($value));
    }
}
