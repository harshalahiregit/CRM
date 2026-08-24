<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Api\Customer\Concerns\AssertsClientTenant;
use App\Http\Controllers\Controller;
use App\Models\Customer\Client;
use App\Models\Customer\ClientNote;
use App\Support\HtmlSanitizer;
use Illuminate\Http\Request;

class ClientNoteController extends Controller
{
    use AssertsClientTenant;

    public function index(Client $client, Request $request)
    {
        $this->assertClientTenant($client, $request->user()->tenant_id);

        // Private notes are visible to their author only.
        $query = $client->notes()->with('author:id,name')
            ->where(fn ($q) => $q->where('visibility', '!=', 'private')
                ->orWhere('created_by', $request->user()->id));

        // Seven types is enough that scrolling stops being a way to find one.
        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }
        if ($visibility = $request->query('visibility')) {
            $query->where('visibility', $visibility);
        }
        if ($request->boolean('pinned')) {
            $query->where('is_pinned', true);
        }
        if ($search = $request->query('search')) {
            $query->where('content', 'like', '%'.$search.'%');
        }

        // Pinned first, then by WHEN IT HAPPENED where that is known, falling
        // back to when it was written. A call logged three days late belongs
        // beside the day it happened, not at the top of the list.
        $notes = $query
            ->orderByDesc('is_pinned')
            ->orderByRaw('COALESCE(contacted_at, created_at) DESC')
            ->get();

        return response()->json($notes);
    }

    public function store(Client $client, Request $request)
    {
        $this->assertClientTenant($client, $request->user()->tenant_id);
        $data = $this->validated($request);

        $note = $client->notes()->create([
            ...$data,
            'tenant_id'  => $client->tenant_id,
            'created_by' => $request->user()->id,
        ]);

        return response()->json($note->load('author:id,name'), 201);
    }

    public function update(Client $client, ClientNote $note, Request $request)
    {
        $this->guard($client, $note, $request);
        $note->update($this->validated($request));

        return response()->json($note->fresh()->load('author:id,name'));
    }

    public function destroy(Client $client, ClientNote $note, Request $request)
    {
        $this->guard($client, $note, $request);
        $note->delete();

        return response()->json(['message' => 'Note deleted']);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'content'     => 'required|string|max:65535',
            // §16 — the taxonomy. Visibility is already handled by the
            // existing `visibility` field, whose 'client' value means exactly
            // "the customer may see this"; no second flag is needed.
            'type'             => 'nullable|string|max:30',
            'priority'    => 'nullable|in:low,medium,high,urgent',
            'deadline'    => 'nullable|date',
            'reminder_at' => 'nullable|date',
            'visibility'  => 'nullable|in:private,team,client',
            // WHEN the conversation happened, not when it was typed. Someone
            // logs Friday's call on Monday, and "when did we last speak to this
            // customer" is unanswerable from created_at.
            'contacted_at' => 'nullable|date',
            'is_pinned'    => 'nullable|boolean',
        ]);

        $data['content'] = HtmlSanitizer::clean($data['content']);

        return $data;
    }

    private function guard(Client $client, ClientNote $note, Request $request): void
    {
        $this->assertClientTenant($client, $request->user()->tenant_id);
        abort_if($note->client_id !== $client->id, 404);
        // Private notes can only be touched by their author.
        abort_if($note->visibility === 'private' && $note->created_by !== $request->user()->id, 403);
    }
}
