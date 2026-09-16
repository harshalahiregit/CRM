<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\Client\Response;

/**
 * What came back from Graph, in a shape the rest of the app can act on.
 *
 * Meta puts the useful part of a failure in error.message and the actionable
 * part in error.error_data.details -- "Recipient phone number not in allowed
 * list" lives in details, while message says only "Unsupported post request".
 * Losing details is why an integration looks broken for a day.
 */
final class CloudApiResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly ?string $messageId,
        public readonly ?string $error,
        public readonly ?int $code,
        public readonly array $raw,
    ) {
    }

    /**
     * Meta was never reached — DNS, routing, a timeout.
     *
     * A distinct case from a refusal: nothing was sent, so it is worth
     * retrying, and the caller can say so instead of leaving the row queued.
     */
    public static function unreachable(string $why): self
    {
        return new self(false, null, 'Could not reach WhatsApp: '.$why, null, []);
    }

    public static function from(Response $res): self
    {
        $body = $res->json() ?? [];

        if ($res->successful() && ! isset($body['error'])) {
            return new self(true, $body['messages'][0]['id'] ?? null, null, null, $body);
        }

        $err = $body['error'] ?? [];
        $parts = array_filter([
            $err['message'] ?? null,
            $err['error_data']['details'] ?? null,
        ]);

        return new self(
            false,
            null,
            $parts !== [] ? implode(' — ', $parts) : 'WhatsApp request failed (HTTP '.$res->status().')',
            isset($err['code']) ? (int) $err['code'] : null,
            $body,
        );
    }

    /**
     * The business tried to start a conversation with plain text. Callers use
     * this to fall back to a template rather than reporting a hard failure.
     */
    public function isOutsideServiceWindow(): bool
    {
        return in_array($this->code, [131047, 131051], true);
    }

    /** The token has expired or been revoked -- worth saying plainly. */
    public function isAuthProblem(): bool
    {
        return in_array($this->code, [190, 102, 200, 10], true);
    }
}
