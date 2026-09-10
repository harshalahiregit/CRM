<?php

namespace Sire\Contracts\Ai;

use Sire\Support\Ai\AiRequest;
use Sire\Support\Ai\AiSuggestionResult;

/**
 * SIRE AI — the provider contract.
 *
 * The single seam a future AI vendor plugs into. Nothing else in SIRE knows a
 * provider exists: callers talk to SireAiGateway, which resolves a provider from
 * tenant settings and hands back a result.
 *
 * FOUR RULES A PROVIDER MUST HONOUR
 *
 *   1. NEVER THROW. Return a failed AiSuggestionResult instead. SIRE must work
 *      when AI is unavailable, and an exception escaping a provider would turn an
 *      optional convenience into an outage.
 *   2. NEVER MUTATE. A provider receives redacted context and returns a
 *      suggestion. It has no access to models and writes nothing.
 *   3. RECEIVE ONLY WHAT IT IS GIVEN. Context arrives already filtered by
 *      SireAiRedactor. A provider must not reach back for more.
 *   4. DECLARE ITSELF. describe() supplies the provider, model and version stored
 *      on every suggestion, so a recommendation can always be traced to what made
 *      it.
 *
 * No implementation of this interface performs analysis today. NullAiProvider is
 * the only one in the codebase and it declines everything.
 */
interface AiProvider
{
    /** Stable identifier stored on every suggestion, e.g. 'null', 'internal-hr'. */
    public function name(): string;

    /** False for anything this provider cannot do. The gateway will not call it. */
    public function supports(string $capability): bool;

    /**
     * Provider metadata recorded against every suggestion.
     *
     * @return array{provider: string, model: ?string, model_version: ?string, endpoint: ?string}
     */
    public function describe(): array;

    /**
     * Produce a suggestion. MUST NOT THROW.
     *
     * The request carries context that SireAiRedactor has already filtered to an
     * allowlist; a provider must treat it as the complete and only input.
     */
    public function suggest(AiRequest $request): AiSuggestionResult;
}
