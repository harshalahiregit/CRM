<?php

namespace App\Http\Controllers\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\AbstractPaginator;

trait ApiResponse
{
    protected function success($data = null, int $status = 200): JsonResponse
    {
        return response()->json($data, $status);
    }

    protected function error(string $message, int $status = 400): JsonResponse
    {
        return response()->json([
            'status'  => 'error',
            'message' => $message,
        ], $status);
    }

    protected function paginated(AbstractPaginator $paginator): JsonResponse
    {
        return response()->json($paginator);
    }
}
