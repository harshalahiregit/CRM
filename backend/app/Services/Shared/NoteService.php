<?php

namespace App\Services\Shared;

use App\Models\Shared\Note;
use App\Support\HtmlSanitizer;

/**
 * The notes engine, addressed by model class.
 *
 * Mirrors ReminderService::createForSubject/listForSubject — the subject comes
 * from the route, never from the request body, so a note can never be retargeted
 * at another record by editing a payload.
 */
class NoteService
{
    public function listForSubject(string $subjectClass, int $subjectId, int $tenantId)
    {
        return Note::forTenant($tenantId)
            ->forSubject($subjectClass, $subjectId)
            ->with('creator:id,name')
            ->latest('id')
            ->get();
    }

    public function createForSubject(string $subjectClass, int $subjectId, array $data, int $tenantId, int $userId): Note
    {
        $data = HtmlSanitizer::cleanFields($data, ['content']);

        $note = Note::create([
            'tenant_id'    => $tenantId,
            'notable_type' => $subjectClass,
            'notable_id'   => $subjectId,
            'title'        => $data['title'],
            'content'      => $data['content'] ?? null,
            'created_by'   => $userId,
        ]);

        return $note->load('creator:id,name');
    }

    public function update(Note $note, array $data, int $tenantId): Note
    {
        $this->assertTenant($note, $tenantId);

        $data = HtmlSanitizer::cleanFields($data, ['content']);
        $note->update(array_intersect_key($data, array_flip(['title', 'content'])));

        return $note->fresh()->load('creator:id,name');
    }

    public function delete(Note $note, int $tenantId): void
    {
        $this->assertTenant($note, $tenantId);
        $note->delete();
    }

    private function assertTenant(Note $note, int $tenantId): void
    {
        abort_unless((int) $note->tenant_id === $tenantId, 404, 'Note not found');
    }
}
