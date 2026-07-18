<?php

namespace App\Support\Compliance;

use App\Models\Tpv\TpvWorker;
use App\Models\Vendor\Vendor;

/**
 * What a checklist may be attached to.
 *
 * An allowlist, not a free-form class name. The API speaks in stable keys
 * ('vendor'), never in FQCNs — a client that could name the class could attach
 * a checklist to any model in the application, including User.
 *
 * This registry is the engine's ONE concession to knowing its consumers. Adding
 * a subject (project, staff exit) is a line here; nothing else in the engine
 * changes.
 */
final class ChecklistSubject
{
    public const VENDOR = 'vendor';
    public const WORKER = 'worker';

    /** key => Eloquent class. */
    public const MAP = [
        self::VENDOR => Vendor::class,
        self::WORKER => TpvWorker::class,
    ];

    public const LABELS = [
        self::VENDOR => 'Vendor',
        self::WORKER => 'Worker',
    ];

    /** Human label for the subject, used in checklist titles. */
    public const TITLE_FIELD = [
        self::VENDOR => 'company_name',
        self::WORKER => 'name',
    ];

    public static function isValid(?string $key): bool
    {
        return $key !== null && array_key_exists($key, self::MAP);
    }

    public static function classFor(?string $key): ?string
    {
        return self::MAP[$key] ?? null;
    }

    /** Reverse lookup so a stored FQCN can be presented as its stable key. */
    public static function keyFor(?string $class): ?string
    {
        $flipped = array_flip(self::MAP);

        return $flipped[$class] ?? null;
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
        $field = self::TITLE_FIELD[self::keyFor($model::class)] ?? null;

        return $field ? ($model->{$field} ?? null) : null;
    }
}
