<?php

namespace App\Http\Controllers\Api\Project;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Services\Project\ProjectService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProjectFileController extends Controller
{
    use ApiResponse;

    public function __construct(private ProjectService $projects)
    {
    }

    private function guardView(Request $request, int $project): void
    {
        $this->projects->assertProjectVisible($project, $request->user()->tenant_id, $request->user()->id, $request->user()?->role === 'admin');
    }

    public function index(Request $request, int $project)
    {
        $this->guardView($request, $project);
        return $this->success($this->projects->listFiles($project, $request->user()->tenant_id), 'Files retrieved');
    }

    public function store(Request $request, int $project)
    {
        $this->guardView($request, $project);
        $request->validate([
            'file'                => 'required|file|max:20480',   // 20 MB
            'visible_to_customer' => 'nullable|boolean',
        ]);

        $tenantId = $request->user()->tenant_id;
        $uploaded = $request->file('file');
        $path = $uploaded->store("projects/{$tenantId}/{$project}", 'local');   // private disk

        $file = $this->projects->storeFile($project, [
            'file_name'           => basename($path),
            'original_name'       => $uploaded->getClientOriginalName(),
            'file_path'           => $path,
            'visible_to_customer' => $request->boolean('visible_to_customer'),
        ], $tenantId, $request->user()->id);

        return $this->success($file, 'File uploaded', 201);
    }

    public function download(Request $request, int $project, int $file)
    {
        $this->guardView($request, $project);
        $record = $this->projects->findFile($file, $project, $request->user()->tenant_id);
        abort_unless(Storage::disk('local')->exists($record->file_path), 404, 'File missing from storage.');

        return Storage::disk('local')->download($record->file_path, $record->original_name);
    }

    public function destroy(Request $request, int $project, int $file)
    {
        $this->guardView($request, $project);
        $this->projects->deleteFile($file, $project, $request->user()->tenant_id);
        return $this->success(null, 'File deleted');
    }
}
