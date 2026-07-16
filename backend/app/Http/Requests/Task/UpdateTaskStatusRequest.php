<?php

namespace App\Http\Requests\Task;

use App\Services\StatusService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTaskStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Statuses are tenant-configurable (Advanced Status Manager), so the
        // allowed values come from the lookup table, not a literal list.
        $keys = app(StatusService::class)->keys('task', $this->user()->tenant_id);

        return [
            'status' => ['required', Rule::in($keys)],
        ];
    }
}
