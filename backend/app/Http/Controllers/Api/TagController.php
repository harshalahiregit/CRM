<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Services\TagService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Workspace-wide tags, shared by every taggable module. */
class TagController extends Controller
{
    use ApiResponse;

    public function __construct(private TagService $tags)
    {
    }

    public function index(Request $request)
    {
        $data = $request->validate(['type' => ['nullable', Rule::in(TagService::TYPES)]]);

        return $this->success($this->tags->list($request->user()->tenant_id, $data['type'] ?? null), 'Tags retrieved');
    }

    public function update(Request $request, int $tag)
    {
        $data = $request->validate([
            'name'  => 'required|string|max:60',
            'color' => 'nullable|string|max:9',
        ]);

        return $this->success(
            $this->tags->rename($tag, $data['name'], $data['color'] ?? null, $request->user()->tenant_id),
            'Tag updated',
        );
    }

    public function destroy(Request $request, int $tag)
    {
        $this->tags->delete($tag, $request->user()->tenant_id);

        return $this->success(null, 'Tag deleted');
    }
}
