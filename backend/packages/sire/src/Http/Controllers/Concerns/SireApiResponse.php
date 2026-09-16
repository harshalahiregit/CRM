<?php

namespace Sire\Http\Controllers\Concerns;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;

/**
 * SIRE — one response envelope, used by all 17 SIRE controllers.
 *
 * { "data": ..., "meta": { ... } }
 *
 * Paginators are unwrapped into data + meta automatically, so a controller
 * returns $this->success($query->paginate()) and the frontend always receives
 * the same shape whether the result is one record or a page of them.
 *
 * IF THE CRM ALREADY HAS A HOUSE ENVELOPE — most do — make this trait defer to
 * it rather than editing 72 call sites: replace the two method bodies below
 * with calls to the CRM's helper. The SIRE frontend reads responses through
 * frontend/src/services/sireApi.js, which unwraps `data` in exactly one place,
 * so a different envelope is a two-file change, not a project-wide one.
 */
trait SireApiResponse
{
    protected function success(mixed $data = null, int $status = 200, array $meta = []): JsonResponse
    {
        if ($data instanceof LengthAwarePaginator) {
            $meta += [
                'page'     => $data->currentPage(),
                'per_page' => $data->perPage(),
                'total'    => $data->total(),
                'pages'    => $data->lastPage(),
            ];
            $data = $data->items();
        }

        return response()->json(array_filter([
            'data' => $data,
            'meta' => $meta ?: null,
        ], static fn ($v) => $v !== null), $status);
    }

    protected function error(string $message, int $status = 422, array $context = []): JsonResponse
    {
        return response()->json(array_filter([
            'message' => $message,
            'context' => $context ?: null,
        ], static fn ($v) => $v !== null), $status);
    }
}
