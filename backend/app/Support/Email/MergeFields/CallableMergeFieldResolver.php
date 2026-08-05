<?php

namespace App\Support\Email\MergeFields;

use App\Support\Email\MergeContext;

/**
 * A merge field built from a closure — keeps the built-in fields compact while
 * still flowing through the same interface, so a module can register either a
 * closure or a full class and the engine treats them identically.
 */
final class CallableMergeFieldResolver implements MergeFieldResolverInterface
{
    public function __construct(
        private string $token,
        private string $group,
        private string $description,
        /** @var callable(MergeContext): string */
        private $resolver,
        /** Value shown in previews when no real data is supplied. */
        private string $sample = '',
    ) {
    }

    public function token(): string
    {
        return $this->token;
    }

    public function group(): string
    {
        return $this->group;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function sample(): string
    {
        return $this->sample;
    }

    public function resolve(MergeContext $context): string
    {
        return (string) ($this->resolver)($context);
    }
}
