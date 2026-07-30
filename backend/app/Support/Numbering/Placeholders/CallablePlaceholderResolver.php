<?php

namespace App\Support\Numbering\Placeholders;

use App\Support\Numbering\NumberingContext;

/**
 * A resolver built from a closure. Keeps the built-in tokens compact while still
 * flowing through the same interface, so a module can register either a closure
 * or a full class and the engine treats them identically.
 */
final class CallablePlaceholderResolver implements PlaceholderResolverInterface
{
    public function __construct(
        private string $token,
        private string $description,
        /** @var callable(NumberingContext): string */
        private $resolver,
    ) {
    }

    public function token(): string
    {
        return $this->token;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function resolve(NumberingContext $context): string
    {
        return (string) ($this->resolver)($context);
    }
}
