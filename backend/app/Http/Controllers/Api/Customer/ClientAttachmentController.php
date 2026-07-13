<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Api\Customer\Concerns\AssertsClientTenant;
use App\Http\Controllers\Controller;
use App\Models\Customer\Client;
use App\Models\Customer\ClientAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ClientAttachmentController extends Controller
{
    use AssertsClientTenant;

    public function index(Client $client, Request $request)
    {
        $this->assertClientTenant($client, $request->user()->tenant_id);
        return response()->json($client->attachments()->get());
    }

    public function store(Client $client, Request $request)
    {
        $this->assertClientTenant($client, $request->user()->tenant_id);
        $request->validate(['file' => 'required|file|max:20480']); // 20 MB

        $file = $request->file('file');
        $path = $file->store("client-attachments/{$client->tenant_id}/{$client->id}", 'public');

        $attachment = $client->attachments()->create([
            'tenant_id'  => $client->tenant_id,
            'file_name'  => $file->getClientOriginalName(),
            'file_path'  => $path,
            'mime_type'  => $file->getClientMimeType(),
            'file_size'  => $file->getSize(),
            'created_by' => $request->user()->id,
        ]);

        return response()->json($attachment, 201);
    }

    public function destroy(Client $client, ClientAttachment $attachment, Request $request)
    {
        $this->assertClientTenant($client, $request->user()->tenant_id);
        abort_if($attachment->client_id !== $client->id, 404);

        Storage::disk('public')->delete($attachment->file_path);
        $attachment->delete();

        return response()->json(['message' => 'Attachment deleted']);
    }
}
