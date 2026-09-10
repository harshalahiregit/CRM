<?php

namespace Sire\Adapters\Defaults;

use Sire\Contracts\SireNotesProvider;
use Sire\Contracts\SireTenantProvider;
use Sire\Models\Note;
use Sire\Dto\SireNote;
use Sire\Dto\SireUserIdentity;

/**
 * The shipped notes provider: SIRE's own table.
 *
 * A host with a notes system implements SireNotesProvider and SIRE comments
 * behave exactly like comments everywhere else in that product — same mentions,
 * same moderation, same visibility. A host without one gets working comments out
 * of the box.
 *
 * Comments differ from audit entries in one way that matters and is encoded
 * here: a comment has an author who may edit it, so this provider has update()
 * and delete() and returns updatedAt. Audit has none of the three.
 *
 * `updatedAt` is returned ONLY when the note was genuinely edited. SIRE renders
 * an "edited" marker from its presence, so always populating it would mark every
 * comment as edited.
 */
class SireLocalNotesProvider implements SireNotesProvider
{
    public function __construct(private readonly SireTenantProvider $tenants)
    {
    }

    public function add(object $subject, string $body, SireUserIdentity $author, bool $internal = true): SireNote
    {
        $note = Note::create([
            'tenant_id'    => $subject->tenant_id ?? $author->tenantId,
            'subject_type' => $subject::class,
            'subject_id'   => $subject->id ?? 0,
            'body'         => $body,
            'is_internal'  => $internal,
            'author_id'    => $author->id,
            'author_name'  => $author->displayName,
        ]);

        return $this->toDto($note);
    }

    public function listFor(object $subject, ?SireUserIdentity $viewer = null, int $limit = 200): array
    {
        return Note::query()
            ->forTenant((int) ($subject->tenant_id ?? 0))
            ->where('subject_type', $subject::class)
            ->where('subject_id', $subject->id ?? 0)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (Note $note) => $this->toDto($note))
            ->all();
    }

    public function find(int|string $noteId): ?SireNote
    {
        // Scoped, even though every caller also verifies the subject afterwards.
        // A note id is a global sequence: unscoped, /reports/1/comments/999 reads
        // a note attached to an invoice in another tenant, and the caller only
        // finds out after it is already in memory.
        $note = Note::query()->forTenant($this->tenantId())->find($noteId);

        return $note === null ? null : $this->toDto($note);
    }

    public function update(int|string $noteId, string $body, SireUserIdentity $user): SireNote
    {
        $note = Note::query()->forTenant($this->tenantId())->findOrFail($noteId);

        $note->body = $body;
        $note->save();   // touches updated_at, which is what surfaces the marker

        return $this->toDto($note->fresh());
    }

    public function delete(int|string $noteId, SireUserIdentity $user): void
    {
        Note::query()->forTenant($this->tenantId())->where('id', $noteId)->delete();
    }

    /**
     * The tenant these operations are confined to.
     *
     * find/update/delete are request-only paths, so a tenant is always in scope;
     * if one somehow is not, currentTenant() throws rather than letting an
     * unscoped write through.
     */
    private function tenantId(): int
    {
        return $this->tenants->currentTenant()->id;
    }

    private function toDto(Note $note): SireNote
    {
        return new SireNote(
            id: $note->id,
            tenantId: (int) $note->tenant_id,
            subjectType: (string) $note->subject_type,
            subjectId: (int) $note->subject_id,
            body: (string) $note->body,
            author: $note->author_id === null ? null : new SireUserIdentity(
                id: (int) $note->author_id,
                tenantId: (int) $note->tenant_id,
                displayName: (string) ($note->author_name ?? 'Unknown'),
            ),
            internal: (bool) $note->is_internal,
            createdAt: $note->created_at?->toIso8601String(),
            // Only when genuinely edited — an always-present value marks every
            // comment as edited.
            updatedAt: $note->updated_at && $note->created_at
                && $note->updated_at->ne($note->created_at)
                    ? $note->updated_at->toIso8601String()
                    : null,
        );
    }
}
