<?php

namespace Sire\AI\Providers;

use Sire\Contracts\Ai\AiProvider;
use Sire\Support\Ai\AiRequest;
use Sire\Support\Ai\AiSuggestionResult;

/**
 * SIRE AI — the only provider that exists.
 *
 * It declines everything, and that is the point of this phase. SIRE AI is
 * FOUNDATION ONLY: the contracts, the boundary and the persistence are built; no
 * analysis is implemented and no vendor is integrated.
 *
 * This class is also the permanent default. A tenant that has not configured a
 * provider gets this one, so "AI is optional" is not a flag anyone can forget to
 * check — the absence of AI is a working object with the same interface.
 *
 * [VERIFY] The CRM appears to have SOME existing AI: User::canGenerateAiJd()
 * suggests HR generates job descriptions somehow. No AI vendor package appears in
 * composer.json, so whatever it uses is not visible from the dependency list.
 * FIND IT BEFORE ADDING A PROVIDER — reusing the existing integration is far
 * better than standing up a second one with its own key, its own bill and its own
 * data-processing agreement.
 */
class NullAiProvider implements AiProvider
{
    public function name(): string
    {
        return 'null';
    }

    public function supports(string $capability): bool
    {
        return false;
    }

    public function describe(): array
    {
        return [
            'provider'      => 'null',
            'model'         => null,
            'model_version' => null,
            'endpoint'      => null,
        ];
    }

    public function suggest(AiRequest $request): AiSuggestionResult
    {
        return AiSuggestionResult::unavailable(
            'No AI provider is configured for this workspace.',
        );
    }
}
