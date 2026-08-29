<?php

namespace App\Http\Controllers\Api\Settings;

use App\Http\Controllers\Controller;
use App\Models\Helpdesk\Ticket;
use App\Models\Project\Project;
use App\Models\Sales\Estimate;
use App\Models\Sales\SalesInvoice;
use App\Models\Task\Task;
use Illuminate\Http\Request;

/**
 * ST2 — a global recycle bin. Lists soft-deleted records across the key modules
 * (invoice, estimate/PI, project, task, ticket) and restores one by type + id.
 * Admin-only and tenant-scoped, so a restore can never resurrect another tenant's
 * data. Each entry is one deleted row with a human label and when it was deleted.
 */
class RecycleBinController extends Controller
{
    /** type => [model class, label column, human label]. */
    private const TYPES = [
        'invoice'  => [SalesInvoice::class, 'number',    'Tax Invoice'],
        'estimate' => [Estimate::class,     'reference',  'Estimate / Proforma'],
        'project'  => [Project::class,      'name',       'Project'],
        'task'     => [Task::class,         'name',       'Task'],
        'ticket'   => [Ticket::class,       'subject',    'Ticket'],
    ];

    /** GET /settings/recycle-bin — recently deleted items across modules. */
    public function index(Request $request)
    {
        $tenantId = (int) $request->user()->tenant_id;
        $items = [];

        foreach (self::TYPES as $type => [$model, $labelCol, $label]) {
            /** @var \Illuminate\Database\Eloquent\Model $model */
            $rows = $model::onlyTrashed()
                ->where('tenant_id', $tenantId)
                ->orderByDesc('deleted_at')
                ->limit(100)
                ->get();

            foreach ($rows as $row) {
                $items[] = [
                    'type'       => $type,
                    'type_label' => $label,
                    'id'         => $row->id,
                    'label'      => (string) ($row->{$labelCol} ?? ('#'.$row->id)),
                    'deleted_at' => $row->deleted_at,
                ];
            }
        }

        // Most recently deleted first, across all types.
        usort($items, fn ($a, $b) => strcmp((string) $b['deleted_at'], (string) $a['deleted_at']));

        return response()->json([
            'data'  => $items,
            'types' => array_map(fn ($t) => ['value' => $t, 'label' => self::TYPES[$t][2]], array_keys(self::TYPES)),
        ]);
    }

    /** POST /settings/recycle-bin/restore — bring one deleted item back. */
    public function restore(Request $request)
    {
        $data = $request->validate([
            'type' => 'required|string',
            'id'   => 'required|integer',
        ]);

        abort_unless(isset(self::TYPES[$data['type']]), 422, 'Unknown item type.');
        [$model] = self::TYPES[$data['type']];

        /** @var \Illuminate\Database\Eloquent\Model|null $row */
        $row = $model::onlyTrashed()
            ->where('tenant_id', (int) $request->user()->tenant_id)
            ->find($data['id']);

        abort_unless($row, 404, 'That item is not in the recycle bin.');

        $row->restore();

        return response()->json(['restored' => true, 'type' => $data['type'], 'id' => $row->id]);
    }
}
