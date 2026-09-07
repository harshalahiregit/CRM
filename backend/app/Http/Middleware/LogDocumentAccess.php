<?php

namespace App\Http\Middleware;

use App\Models\DocumentAccessLog;
use App\Models\User;
use App\Support\IpLocation;
use App\Support\UserAgentInfo;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Every document that leaves the system, recorded once, in one place.
 *
 * Files are served from more than fifty endpoints across the modules — minutes,
 * kickoff packs, medical certificates, invoices, work-start letters, uploaded
 * attachments. Exactly one of them logged anything. The rest left no trace, so
 * "who downloaded that certificate, and from where?" had no answer, and the
 * answer is the kind of thing that is only ever wanted after the fact.
 *
 * ── Why middleware, and not fifty edits ─────────────────────────────────
 * Adding a log line to every file endpoint means fifty places to get right and
 * a fifty-first that somebody forgets next month — and the one that is
 * forgotten is the one that will be asked about. Here it is decided by what
 * actually went out over the wire, so an endpoint added tomorrow is covered
 * without anybody remembering to cover it.
 *
 * ── What is recorded ────────────────────────────────────────────────────
 * The address, the device and the browser come from the request itself: free,
 * immediate, and involving nobody else. A LOCATION does not — turning an
 * address into a place means sending that address to a third party — so it is
 * filled in only where an administrator has switched that on. See IpLocation.
 *
 * Never breaks a download: if the log cannot be written, the file still goes.
 */
class LogDocumentAccess
{
    /**
     * Content types worth recording. Documents and media that leave the system,
     * not the JSON that every other request returns.
     */
    private const LOGGABLE = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument',
        'application/vnd.ms-excel',
        'application/zip',
        'application/octet-stream',
        'image/',
        'text/csv',
    ];

    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        try {
            if ($this->isDocument($response)) {
                $this->record($request, $response);
            }
        } catch (\Throwable $e) {
            // A file people asked for must never fail because the audit line did.
            Log::channel('errors')->warning('Document access log failed', [
                'path' => $request->path(), 'error' => $e->getMessage(),
            ]);
        }

        return $response;
    }

    /** Did this response actually carry a file? */
    private function isDocument($response): bool
    {
        if (! $response instanceof BinaryFileResponse && ! $response instanceof StreamedResponse) {
            return false;
        }
        if ($response->getStatusCode() !== 200) {
            return false;
        }

        $type = strtolower((string) $response->headers->get('Content-Type'));

        foreach (self::LOGGABLE as $needle) {
            if (str_starts_with($type, $needle)) {
                return true;
            }
        }

        // A download with no useful type is still a document leaving the system.
        return str_contains((string) $response->headers->get('Content-Disposition'), 'attachment');
    }

    private function record(Request $request, $response): void
    {
        $actor = $request->user();
        $tenantId = $actor->tenant_id ?? null;
        if (! $tenantId) {
            // Nothing to file it under. A public signed link with no tenant in
            // scope is rare and is better left unlogged than logged wrongly.
            return;
        }

        $ua = UserAgentInfo::parse($request->userAgent());
        $disposition = (string) $response->headers->get('Content-Disposition');

        DocumentAccessLog::create([
            'tenant_id' => $tenantId,
            // Only a real User has a users.id; a vendor identity is its own
            // model and would write a foreign id into a user reference.
            'user_id' => $actor instanceof User ? $actor->id : null,
            'actor_type' => $this->actorType($actor),
            'actor_label' => $actor->name ?? $actor->company_name ?? null,

            // "inline" is a view, an attachment is a download — the header the
            // endpoint already sets says which, so nothing has to be passed in.
            'action' => str_contains($disposition, 'attachment') ? 'download' : 'view',
            'document' => $this->filename($disposition),
            'mime' => $response->headers->get('Content-Type'),
            'route' => $request->route()?->getName() ?: $request->route()?->uri(),
            'path' => mb_substr($request->path(), 0, 255),

            'ip' => $request->ip(),
            'device' => $ua['device'],
            'browser' => $ua['browser'],
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 400),
            'location' => IpLocation::for($request->ip(), $tenantId),
        ]);
    }

    private function actorType($actor): string
    {
        return match (true) {
            $actor instanceof User => 'user',
            $actor === null => 'guest',
            default => class_basename($actor),
        };
    }

    private function filename(string $disposition): ?string
    {
        if (preg_match('/filename\*?=(?:UTF-8\'\')?"?([^";]+)"?/i', $disposition, $m)) {
            return mb_substr(rawurldecode(trim($m[1])), 0, 191);
        }

        return null;
    }
}
