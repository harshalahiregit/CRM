<?php

namespace Sire\Support\Ai;

/**
 * SIRE AI — what a provider hands back.
 *
 * Four outcomes, and the difference between them is the difference between "AI is
 * switched off", "this provider cannot do that", and "something broke". Collapsing
 * them into a null would make an outage look like a configuration choice.
 */
final class AiSuggestionResult
{
    public const OK          = 'ok';
    public const UNAVAILABLE = 'unavailable'; // AI disabled, or no provider configured
    public const UNSUPPORTED = 'unsupported'; // provider exists but cannot do this
    public const FAILED      = 'failed';      // it tried and could not

    private function __construct(
        public readonly string $status,
        public readonly ?array $payload = null,
        public readonly ?float $confidence = null,
        /** Why. Free-form but structured: {summary, signals[], references[]} */
        public readonly ?array $evidence = null,
        public readonly ?array $provider = null,
        public readonly ?string $message = null,
    ) {
    }

    public static function ok(array $payload, ?float $confidence, ?array $evidence, array $provider): self
    {
        // Confidence is clamped rather than trusted. A provider reporting 1.7 is
        // wrong, and propagating it would put nonsense on a screen someone makes
        // decisions from.
        $clamped = $confidence === null ? null : max(0.0, min(1.0, $confidence));

        return new self(self::OK, $payload, $clamped, $evidence, $provider);
    }

    public static function unavailable(string $message = 'AI suggestions are not enabled.'): self
    {
        return new self(self::UNAVAILABLE, message: $message);
    }

    public static function unsupported(string $capability, ?array $provider = null): self
    {
        return new self(self::UNSUPPORTED, provider: $provider, message: "This provider does not support {$capability}.");
    }

    public static function failed(string $message, ?array $provider = null): self
    {
        return new self(self::FAILED, provider: $provider, message: $message);
    }

    public function isOk(): bool
    {
        return $this->status === self::OK;
    }

    /**
     * True when the caller should carry on as though AI did not exist — which is
     * every non-OK outcome. SIRE must never behave differently because a
     * suggestion failed to arrive.
     */
    public function shouldIgnore(): bool
    {
        return $this->status !== self::OK;
    }

    public function toArray(): array
    {
        return [
            'status'     => $this->status,
            'payload'    => $this->payload,
            'confidence' => $this->confidence,
            'evidence'   => $this->evidence,
            'provider'   => $this->provider,
            'message'    => $this->message,
        ];
    }
}
