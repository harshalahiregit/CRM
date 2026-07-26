<?php

namespace App\Services\Sales;

use App\Models\Sales\ContentPage;
use App\Support\HtmlSanitizer;
use Illuminate\Database\Eloquent\Model;

/**
 * Multi-page rich-text content shared by proposals, proposal templates and
 * contracts (polymorphic content_pages). Pages are replaced wholesale on
 * save (delete-recreate, mirroring syncLineItems) — page identity carries no
 * references elsewhere, so this stays simple and order is authoritative.
 */
class ContentPageService
{
    /** @param array<int, array{title?: string, content?: string}> $pages */
    public function syncPages(Model $model, array $pages, int $tenantId): void
    {
        ContentPage::where('pageable_type', $model::class)
            ->where('pageable_id', $model->getKey())
            ->delete();

        foreach (array_values($pages) as $i => $page) {
            ContentPage::create([
                'tenant_id'     => $tenantId,
                'pageable_type' => $model::class,
                'pageable_id'   => $model->getKey(),
                'title'         => trim($page['title'] ?? '') !== '' ? $page['title'] : 'Page '.($i + 1),
                'content'       => HtmlSanitizer::clean($page['content'] ?? ''),
                'sort_order'    => $i,
            ]);
        }
    }

    /** Copy all pages from one record to another (template → proposal etc.). */
    public function copyPages(Model $from, Model $to, int $tenantId): void
    {
        $pages = ContentPage::where('pageable_type', $from::class)
            ->where('pageable_id', $from->getKey())
            ->orderBy('sort_order')
            ->get(['title', 'content'])
            ->map(fn ($p) => ['title' => $p->title, 'content' => $p->content])
            ->all();

        if ($pages !== []) {
            $this->syncPages($to, $pages, $tenantId);
        }
    }
}
