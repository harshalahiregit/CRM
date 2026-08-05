<?php

namespace App\Support\Hr;

/**
 * The vocabulary of tax deduction sections — CODES AND LABELS ONLY.
 *
 * Read this carefully before adding anything: there is deliberately no limit, cap
 * or eligibility rule in this file. Which sections a regime allows, and how much
 * each one caps at, are legal figures that change with every Finance Act — they
 * live in the TDS rule's `config` where the business sets them and can date them.
 *
 * All this class does is name the sections so the UI can label a declaration line
 * and the engine can match it to a configured limit.
 */
final class TaxSections
{
    /** code => [label, group] */
    public const ALL = [
        '80C'      => ['Life insurance, PPF, ELSS, principal repayment, tuition fees', 'Chapter VI-A'],
        '80CCC'    => ['Pension fund contributions', 'Chapter VI-A'],
        '80CCD1'   => ['Employee contribution to NPS', 'Chapter VI-A'],
        '80CCD1B'  => ['Additional NPS contribution', 'Chapter VI-A'],
        '80CCD2'   => ['Employer contribution to NPS', 'Chapter VI-A'],
        '80D'      => ['Medical insurance premium', 'Chapter VI-A'],
        '80DD'     => ['Maintenance of a dependant with disability', 'Chapter VI-A'],
        '80DDB'    => ['Treatment of specified diseases', 'Chapter VI-A'],
        '80E'      => ['Interest on an education loan', 'Chapter VI-A'],
        '80EEA'    => ['Interest on a housing loan (first-time buyer)', 'Chapter VI-A'],
        '80G'      => ['Donations', 'Chapter VI-A'],
        '80GG'     => ['Rent paid where no HRA is received', 'Chapter VI-A'],
        '80TTA'    => ['Interest on a savings account', 'Chapter VI-A'],
        '80TTB'    => ['Interest income for senior citizens', 'Chapter VI-A'],
        '80U'      => ['Self — person with disability', 'Chapter VI-A'],
        '24B'      => ['Interest on a housing loan (self-occupied)', 'Income from house property'],
    ];

    /**
     * HRA is not a Chapter VI-A deduction — it is an exemption computed from rent,
     * salary and city, so it carries its own inputs and its own calculator.
     */
    public const HRA = 'HRA';

    public static function codes(): array
    {
        return array_keys(self::ALL);
    }

    public static function exists(string $code): bool
    {
        return $code === self::HRA || array_key_exists($code, self::ALL);
    }

    /** [['code'=>'80C','label'=>'…','group'=>'…'], …] for the declaration form. */
    public static function options(): array
    {
        $out = [];
        foreach (self::ALL as $code => [$label, $group]) {
            $out[] = ['code' => $code, 'label' => $label, 'group' => $group];
        }

        return $out;
    }

    public static function label(string $code): string
    {
        return self::ALL[$code][0] ?? $code;
    }
}
