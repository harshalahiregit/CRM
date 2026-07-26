<?php

namespace App\Http\Controllers\Api\Task;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Services\Task\TaskService;
use Illuminate\Http\Request;

/** Reusable checklist templates (e.g. "Code Review Checklist"). */
class TaskTemplateController extends Controller
{
    use ApiResponse;

    public function __construct(private TaskService $tasks)
    {
    }

    public function index(Request $request)
    {
        return $this->success($this->tasks->listTemplates($request->user()->tenant_id), 'Templates retrieved');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'    => 'required|string|max:120',
            'items'   => 'required|array|min:1|max:100',
            'items.*' => 'required|string|max:500',
        ]);

        $tpl = $this->tasks->createTemplate($data, $request->user()->tenant_id, $request->user()->id);

        return $this->success($tpl, 'Template created', 201);
    }

    public function destroy(Request $request, int $template)
    {
        $this->tasks->deleteTemplate($template, $request->user()->tenant_id);

        return $this->success(null, 'Template deleted');
    }

    /** Append a template's items onto a task's checklist. */
    public function apply(Request $request, int $task)
    {
        $data = $request->validate(['template_id' => 'required|integer|min:1']);

        $items = $this->tasks->applyTemplate($task, $data['template_id'], $request->user()->tenant_id);

        return $this->success($items, 'Template applied');
    }

    /** Save a task's existing checklist as a new reusable template. */
    public function saveFromTask(Request $request, int $task)
    {
        $data = $request->validate(['name' => 'required|string|max:120']);

        $tpl = $this->tasks->saveChecklistAsTemplate($task, $data['name'], $request->user()->tenant_id, $request->user()->id);

        return $this->success($tpl, 'Checklist saved as template', 201);
    }
}
