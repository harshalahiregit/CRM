<?php

namespace Sire\AI;

use Sire\Contracts\Ai\AiProvider;
use Sire\AI\Providers\NullAiProvider;

/**
 * SIRE AI — provider resolution.
 *
 * An ALLOWLISTED MAP, never a class name from settings. Resolving an arbitrary
 * string to a class would let anyone with settings access instantiate anything in
 * the container, which is a remote-code-execution shape, not a configuration
 * feature.
 *
 * Adding a provider is a code change: implement AiProvider, add one line here.
 * That is deliberate — a new AI provider means a new data processor, and it
 * should go through review rather than a settings form.
 */
class SireAiRegistry
{
    /** provider name => class. The only classes that can ever be resolved. */
    private const PROVIDERS = [
        'null' => NullAiProvider::class,
        // Future providers land here, one line each, after review.
    ];

    public function resolve(?string $name): AiProvider
    {
        $class = self::PROVIDERS[$name ?? 'null'] ?? null;

        if ($class === null) {
            // An unrecognised provider name falls back to null rather than
            // throwing. A typo in settings must degrade to "no AI", never to an
            // error on a page that has nothing to do with AI.
            return app(NullAiProvider::class);
        }

        return app($class);
    }

    public function available(): array
    {
        return array_keys(self::PROVIDERS);
    }

    public function isConfigured(?string $name): bool
    {
        return $name !== null && $name !== 'null' && array_key_exists($name, self::PROVIDERS);
    }
}
