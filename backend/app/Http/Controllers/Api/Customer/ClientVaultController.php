<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Api\Customer\Concerns\AssertsClientTenant;
use App\Http\Controllers\Controller;
use App\Models\Customer\Client;
use App\Models\Customer\ClientVaultAccessLog;
use App\Models\Customer\ClientVaultEntry;
use App\Services\Customer\VaultUnlockService;
use App\Support\HtmlSanitizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Per-customer credential vault. Passwords are encrypted at rest and never
 * included in list responses — the decrypted value is only returned by the
 * explicit reveal() endpoint.
 *
 * §15 of the Customer 360 document asks for Vault to differ from Files by
 * having "stronger RBAC and audit trails". The RBAC is ClientVaultEntry's
 * visibility rules; the trail is written here, on every action that changes a
 * credential or discloses one. Listing is not logged — the list never contains
 * a password, so a page view discloses nothing.
 */
class ClientVaultController extends Controller
{
    use AssertsClientTenant;

    /** The private disk Files already uses; nothing here is web-reachable. */
    private const DISK = 'attachments';

    public function __construct(private VaultUnlockService $unlock)
    {
    }

    public function index(Client $client, Request $request)
    {
        $this->assertClientTenant($client, $request->user()->tenant_id);
        // `password` is $hidden on the model, so it is excluded here.
        // Entries the user isn't allowed to see are filtered out server-side.
        return response()->json(
            $client->vaultEntries()->visibleTo($request->user())->latest()->get()
        );
    }

    public function store(Client $client, Request $request)
    {
        $this->assertClientTenant($client, $request->user()->tenant_id);
        $data = $this->validated($request);

        $entry = $client->vaultEntries()->create([
            ...$data,
            ...$this->storeFile($client, $request),
            'tenant_id'  => $client->tenant_id,
            'created_by' => $request->user()->id,
        ]);

        $this->audit($request, $entry, ClientVaultAccessLog::CREATED);

        return response()->json($entry, 201);
    }

    public function update(Client $client, ClientVaultEntry $vaultEntry, Request $request)
    {
        $this->guardEntry($client, $vaultEntry, $request);
        $vaultEntry->update([
            ...$this->validated($request),
            ...$this->storeFile($client, $request, $vaultEntry),
        ]);
        $this->audit($request, $vaultEntry, ClientVaultAccessLog::UPDATED);

        return response()->json($vaultEntry);
    }

    /** Returns the decrypted password for a single entry (explicit reveal). */
    public function reveal(Client $client, ClientVaultEntry $vaultEntry, Request $request)
    {
        $this->guardEntry($client, $vaultEntry, $request, manage: false);
        // Re-authentication, as the legacy CRM required before opening the
        // vault. A session left open on an unlocked laptop is the realistic way
        // a credential store leaks, and every other control here assumes the
        // person at the keyboard is the one who logged in.
        $this->unlock->assertUnlocked($request->user());
        // The disclosure itself is the event worth recording.
        $this->audit($request, $vaultEntry, ClientVaultAccessLog::REVEALED);

        return response()->json(['password' => $vaultEntry->password]);
    }

    public function destroy(Client $client, ClientVaultEntry $vaultEntry, Request $request)
    {
        $this->guardEntry($client, $vaultEntry, $request);
        // Logged BEFORE the delete, so the trail exists even if the row does not.
        $this->audit($request, $vaultEntry, ClientVaultAccessLog::DELETED);
        $vaultEntry->delete();

        return response()->json(['message' => 'Vault entry deleted']);
    }

    /**
     * The audit trail for one entry.
     *
     * Administrators only. The log records who looked at which credential and
     * when, which is itself sensitive — handing it to every colleague would
     * turn an accountability record into a surveillance feed.
     */
    public function accessLog(Client $client, ClientVaultEntry $vaultEntry, Request $request)
    {
        $this->guardEntry($client, $vaultEntry, $request);
        abort_unless($request->user()->role === 'admin', 403,
            'Only an administrator can read the vault audit trail.');

        return response()->json(
            ClientVaultAccessLog::forTenant($client->tenant_id)
                ->where('vault_entry_id', $vaultEntry->id)
                ->with('user:id,name')
                ->orderByDesc('created_at')
                ->limit(200)
                ->get()
        );
    }

    /**
     * Append one row to the trail.
     *
     * Never allowed to break the action it is recording: a vault read must not
     * 500 because the audit insert failed. The write is best-effort and the
     * failure is logged, which is the right trade for a trail that exists to
     * explain history rather than to gate access.
     */
    private function audit(Request $request, ClientVaultEntry $entry, string $action): void
    {
        try {
            ClientVaultAccessLog::create([
                'tenant_id'      => $entry->tenant_id,
                'client_id'      => $entry->client_id,
                'vault_entry_id' => $entry->id,
                'user_id'        => $request->user()?->id,
                'action'         => $action,
                'ip'             => $request->ip(),
                'user_agent'     => substr((string) $request->userAgent(), 0, 255),
                'created_at'     => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title'             => 'required|string|max:255',
            'kind'              => ['nullable', \Illuminate\Validation\Rule::in(ClientVaultEntry::KINDS)],
            // Free string, not an enum: the suggested classes cover the common
            // cases and an unforeseen one should be recordable, not refused.
            'category'          => 'nullable|string|max:60',
            'username'          => 'nullable|string|max:255',
            'password'          => 'nullable|string|max:1000',
            'url'               => 'nullable|string|max:500',
            'notes'             => 'nullable|string|max:65535',
            // Agreements and certificates lapse; recorded so alerts can warn.
            'expires_at'        => 'nullable|date',
            'visibility'        => 'nullable|integer|in:1,2,3',
            'share_in_projects' => 'nullable|boolean',
            // Same allow-list and cap as Files. A vault document is protected by
            // the access rules on this row, not by being an unusual file type.
            'file'              => 'nullable|file|max:20480|mimes:pdf,doc,docx,xls,xlsx,csv,ppt,pptx,txt,png,jpg,jpeg,gif,webp,zip',
        ]);

        // `file` is handled separately — it is not a column.
        unset($data['file']);

        // Notes are rich text — strip anything unsafe before storing.
        if (isset($data['notes'])) {
            $data['notes'] = HtmlSanitizer::clean($data['notes']);
        }

        return $data;
    }

    /**
     * Store an uploaded document and return the columns describing it.
     *
     * Same private disk as Files. The protection on a vault document is the
     * access rules on its row — visibility, creator-only management, and the
     * download being logged — not the path being hard to guess.
     *
     * @return array<string, mixed> empty when no file was sent
     */
    private function storeFile(Client $client, Request $request, ?ClientVaultEntry $existing = null): array
    {
        if (! $request->hasFile('file')) {
            return [];
        }

        $file = $request->file('file');
        $path = $file->store("client-vault/{$client->tenant_id}/{$client->id}", self::DISK);

        // Replacing a document removes the one it replaces. Leaving it behind
        // would mean a "deleted" confidential file still sitting on disk with a
        // row that no longer points at it — unreachable through the app, and
        // unnoticed by anyone auditing what the vault holds.
        if ($existing?->file_path) {
            Storage::disk(self::DISK)->delete($existing->file_path);
        }

        return [
            'kind'      => ClientVaultEntry::KIND_DOCUMENT,
            'file_name' => $this->sanitizeFilename($file->getClientOriginalName()),
            'file_path' => $path,
            'mime_type' => $file->getClientMimeType(),
            'file_size' => $file->getSize(),
        ];
    }

    /**
     * Download a vault document.
     *
     * The download IS the disclosure, so it is logged exactly as revealing a
     * password is. Without that, moving sensitive documents into the vault
     * would have given them the vault's access rules but not its accountability.
     */
    public function download(Client $client, ClientVaultEntry $vaultEntry, Request $request)
    {
        $this->guardEntry($client, $vaultEntry, $request, manage: false);
        $this->unlock->assertUnlocked($request->user());

        abort_unless($vaultEntry->file_path, 404, 'This entry has no document.');
        abort_unless(Storage::disk(self::DISK)->exists($vaultEntry->file_path), 404, 'The document is missing from storage.');

        $this->audit($request, $vaultEntry, ClientVaultAccessLog::DOWNLOADED);

        return Storage::disk(self::DISK)->download($vaultEntry->file_path, $vaultEntry->file_name);
    }

    /** Confirm the user's own password to open the vault for a short window. */
    public function unlock(Request $request)
    {
        $data = $request->validate(['password' => 'required|string']);

        return response()->json([
            'unlocked'   => true,
            'expires_in' => $this->unlock->unlock($request->user(), $data['password']),
        ]);
    }

    /** Whether the vault is currently open, so the UI knows to prompt. */
    public function lockState(Request $request)
    {
        return response()->json([
            'unlocked'  => $this->unlock->isUnlocked($request->user()),
            'remaining' => $this->unlock->remaining($request->user()),
        ]);
    }

    /** Close it deliberately — leaving a desk, or finishing. */
    public function lock(Request $request)
    {
        $this->unlock->lock($request->user());

        return response()->json(['unlocked' => false]);
    }

    /** Strip anything path-like from a client-supplied filename. */
    private function sanitizeFilename(string $name): string
    {
        return substr(preg_replace('/[^\w.\- ]+/u', '_', basename($name)) ?: 'document', 0, 180);
    }

    /**
     * @param bool $manage true for write actions (edit/delete), which are
     *                     limited to the entry's creator or an administrator.
     */
    private function guardEntry(Client $client, ClientVaultEntry $entry, Request $request, bool $manage = true): void
    {
        $this->assertClientTenant($client, $request->user()->tenant_id);
        abort_if($entry->client_id !== $client->id, 404);

        // Hidden entries must 404 (not 403) so their existence isn't leaked.
        abort_unless($entry->isVisibleTo($request->user()), 404);
        abort_unless(! $manage || $entry->isManageableBy($request->user()), 403,
            'Only the creator or an administrator can change this vault entry.');
    }
}
