<?php

namespace App\Http\Controllers\Api\Task;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Services\Task\TaskService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Task attachments. Files are written to the private local disk and are never
 * web-accessible — every download goes back through the authenticated,
 * tenant-scoped route below.
 */
class TaskFileController extends Controller
{
    use ApiResponse;
    use GuardsTaskAccess;

    public function __construct(private TaskService $tasks)
    {
    }

    public function index(Request $request, int $task)
    {
        $this->guardTask($request, $task);
        return $this->success($this->tasks->listFiles($task, $request->user()->tenant_id), 'Files retrieved');
    }

    public function store(Request $request, int $task)
    {
        $this->guardTask($request, $task);
        $request->validate([
            'files'   => ['required', 'array', 'max:10'],
            'files.*' => ['file', 'max:10240'],   // 10 MB each, same ceiling as helpdesk
        ]);

        $tenantId = $request->user()->tenant_id;

        $stored = [];
        foreach ($request->file('files', []) as $file) {
            $stored[] = [
                'file_path' => $file->store("tasks/attachments/{$tenantId}/{$task}", 'local'),
                'file_name' => $file->getClientOriginalName(),
                'file_size' => $file->getSize(),
                'mime_type' => $file->getClientMimeType(),
            ];
        }

        $files = $this->tasks->addFiles($task, $stored, $tenantId, $request->user()->id);

        return $this->success($files, 'Files uploaded', 201);
    }

    public function download(Request $request, int $task, int $file)
    {
        $this->guardTask($request, $task);
        $row = $this->tasks->findFile($file, $task, $request->user()->tenant_id);

        abort_unless(Storage::disk('local')->exists($row->file_path), 404, 'File missing from storage.');

        return Storage::disk('local')->download($row->file_path, $row->file_name);
    }

    public function destroy(Request $request, int $task, int $file)
    {
        $this->guardTask($request, $task);
        $this->tasks->deleteFile($file, $task, $request->user()->tenant_id);

        return $this->success(null, 'File deleted');
    }
}
