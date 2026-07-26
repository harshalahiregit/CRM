<?php

namespace App\Services\Shared\MeetingProviders;

/**
 * Stub provider — used when no real credentials are configured.
 *
 * Returns a deterministic placeholder URL so the rest of the system can be
 * tested end-to-end (UI, notifications, link storage) without live API keys.
 * Switch to a real provider by setting MEETING_PROVIDER in .env.
 */
class StubProvider implements MeetingProviderInterface
{
    public function create(array $data): array
    {
        $id = strtolower(substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(9))), 0, 12));

        return [
            'link'      => "https://meet.example.com/{$id}",
            'id'        => $id,
            'passcode'  => null,
            'host_link' => null,
            'platform'  => 'stub',
        ];
    }
}
