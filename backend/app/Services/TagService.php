<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Tag;
use App\Models\Taggable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Shared tagging. Modules call sync()/tagsFor() with a taggable_type string —
 * no module needs its own tags table, and no model needs a schema change.
 *
 * Tags are created on demand by name: the UI lets you type a new tag, so a
 * "create the tag first" step would just be friction.
 */
class TagService
{
    /** Types allowed to carry tags — an allowlist so a typo can't invent a type. */
    public const TYPES = ['task', 'project'];

    private const MAX_PER_RECORD = 15;

    public function list(int $tenantId, ?string $type = null): Collection
    {
        $q = Tag::forTenant($tenantId)->orderBy('name');

        // Only tags actually in use on this type — keeps a task picker from
        // offering project-only tags.
        if ($type) {
            $q->whereHas('taggables', fn ($t) => $t->where('taggable_type', $type));
        }

        return $q->get();
    }

    /** Tags on one record. */
    public function tagsFor(string $type, int $id, int $tenantId): Collection
    {
        $this->assertType($type);

        return Tag::forTenant($tenantId)
            ->whereHas('taggables', fn ($t) => $t->where('taggable_type', $type)->where('taggable_id', $id))
            ->orderBy('name')
            ->get();
    }

    /**
     * Tags for many records at once, keyed by id — one query instead of one per
     * row, so a tagged list view doesn't N+1.
     *
     * @return array<int, array<int, array{id:int,name:string,color:string}>>
     */
    public function tagsForMany(string $type, array $ids, int $tenantId): array
    {
        $this->assertType($type);
        if (! $ids) {
            return [];
        }

        $rows = Taggable::forTenant($tenantId)
            ->where('taggable_type', $type)
            ->whereIn('taggable_id', $ids)
            ->with('tag:id,name,color')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            if ($row->tag) {
                $out[(int) $row->taggable_id][] = [
                    'id' => $row->tag->id, 'name' => $row->tag->name, 'color' => $row->tag->color,
                ];
            }
        }

        return $out;
    }

    /**
     * Replace a record's tags with $names, creating any that don't exist.
     * Names are matched case-insensitively so "Urgent" doesn't become a second
     * tag alongside "urgent".
     */
    public function sync(string $type, int $id, array $names, int $tenantId): Collection
    {
        $this->assertType($type);

        $clean = collect($names)
            ->map(fn ($n) => trim((string) $n))
            ->filter()
            ->map(fn ($n) => mb_substr($n, 0, 60))
            ->unique(fn ($n) => mb_strtolower($n))
            ->take(self::MAX_PER_RECORD)
            ->values();

        return DB::transaction(function () use ($clean, $type, $id, $tenantId) {
            $tagIds = $clean->map(fn ($name) => $this->findOrCreate($name, $tenantId)->id)->all();

            Taggable::forTenant($tenantId)
                ->where('taggable_type', $type)->where('taggable_id', $id)
                ->when($tagIds, fn ($q) => $q->whereNotIn('tag_id', $tagIds))
                ->delete();

            $existing = Taggable::forTenant($tenantId)
                ->where('taggable_type', $type)->where('taggable_id', $id)
                ->pluck('tag_id')->all();

            foreach (array_diff($tagIds, $existing) as $tagId) {
                Taggable::create([
                    'tenant_id' => $tenantId, 'tag_id' => $tagId,
                    'taggable_type' => $type, 'taggable_id' => $id,
                ]);
            }

            return $this->tagsFor($type, $id, $tenantId);
        });
    }

    /** Drop every link to a record — call when the record is hard-deleted. */
    public function clear(string $type, int $id, int $tenantId): void
    {
        $this->assertType($type);
        Taggable::forTenant($tenantId)->where('taggable_type', $type)->where('taggable_id', $id)->delete();
    }

    public function rename(int $tagId, string $name, ?string $color, int $tenantId): Tag
    {
        $tag = Tag::forTenant($tenantId)->find($tagId);
        if (! $tag) {
            throw new BusinessException('Tag not found.', 404);
        }

        $name = trim($name);
        $clash = Tag::forTenant($tenantId)->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->where('id', '!=', $tagId)->exists();
        if ($clash) {
            throw new BusinessException('A tag with that name already exists.', 422);
        }

        $tag->update(array_filter(['name' => $name, 'color' => $color], fn ($v) => $v !== null));

        return $tag->fresh();
    }

    /** Deleting a tag unlinks it everywhere (taggables cascades on the FK). */
    public function delete(int $tagId, int $tenantId): void
    {
        $tag = Tag::forTenant($tenantId)->find($tagId);
        if (! $tag) {
            throw new BusinessException('Tag not found.', 404);
        }
        $tag->delete();
    }

    private function findOrCreate(string $name, int $tenantId): Tag
    {
        $tag = Tag::forTenant($tenantId)->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();

        return $tag ?? Tag::create(['tenant_id' => $tenantId, 'name' => $name, 'color' => $this->pickColor($name)]);
    }

    /**
     * Deterministic colour from the name — the same tag always looks the same,
     * and nobody has to pick a colour just to add a tag.
     */
    private function pickColor(string $name): string
    {
        $palette = ['#8b5cf6', '#06b6d4', '#10b981', '#f59e0b', '#ef4444', '#3b82f6', '#ec4899', '#84cc16'];

        return $palette[crc32(mb_strtolower($name)) % count($palette)];
    }

    private function assertType(string $type): void
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new BusinessException("Unknown taggable type: {$type}", 422);
        }
    }
}
