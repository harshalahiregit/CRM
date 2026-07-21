<?php

namespace App\Support\Shared;

use App\Models\Tpv\TpvOnboarding;
use App\Models\Vendor\Vendor;

/**
 * What a kickoff meeting may be attached to.
 *
 * An allowlist, not a free-form class name — a client that could name the class
 * could attach a meeting to any model in the app. The API speaks in stable keys
 * ('vendor'), never FQCNs.
 *
 * This is the one seam left open for Shivam's Project&Task module: when that
 * lands, a project subject is a single line here and nothing else in the engine
 * changes. That is the whole point of building this polymorphically rather than
 * as a TPV-owned table — the brief calls for "one shared KickoffMeeting feature
 * that both TPV and Projects can attach to".
 */
final class KickoffSubject
{
    public const VENDOR     = 'vendor';
    public const ONBOARDING = 'onboarding';
    // public const PROJECT = 'project';  // ← Shivam plugs in here.

    /** key => Eloquent class. */
    public const MAP = [
        self::VENDOR     => Vendor::class,
        self::ONBOARDING => TpvOnboarding::class,
    ];

    public const LABELS = [
        self::VENDOR     => 'Vendor',
        self::ONBOARDING => 'Onboarding',
    ];

    /** The display-name field on each subject, used in meeting titles/listings. */
    public const TITLE_FIELD = [
        self::VENDOR     => 'company_name',
        self::ONBOARDING => null,   // resolved via the onboarding's vendor below
    ];

    public static function isValid(?string $key): bool
    {
        return $key !== null && array_key_exists($key, self::MAP);
    }

    public static function classFor(?string $key): ?string
    {
        return self::MAP[$key] ?? null;
    }

    public static function keyFor(?string $class): ?string
    {
        return array_flip(self::MAP)[$class] ?? null;
    }

    public static function label(?string $key): string
    {
        return self::LABELS[$key] ?? (string) $key;
    }

    /** The display name of a subject record, for titles and listings. */
    public static function nameOf(?object $model): ?string
    {
        if (! $model) {
            return null;
        }

        // An onboarding has no name of its own — it is "the kickoff for <vendor>".
        if ($model instanceof TpvOnboarding) {
            return $model->vendor?->company_name;
        }

        $field = self::TITLE_FIELD[self::keyFor($model::class)] ?? null;

        return $field ? ($model->{$field} ?? null) : null;
    }
}
