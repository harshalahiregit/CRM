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
        $request->validate([
            // Allow-list common document/image types only — blocks .php/.svg/.html
            // uploads that could be served as active content from the public disk.
            'file' => 'required|file|max:20480|mimes:pdf,doc,docx,xls,xlsx,csv,ppt,pptx,txt,png,jpg,jpeg,gif,webp,zip',
        ]);

        $file = $request->file('file');
        $path = $file->store("client-attachments/{$client->tenant_id}/{$client->id}", 'public');

        $attachment = $client->attachments()->create([
            'tenant_id'  => $client->tenant_id,
            'file_name'  => $this->sanitizeFilename($file->getClientOriginalName()),
            'file_path'  => $path,
            'mime_type'  => $file->getClientMimeType(),
            'file_size'  => $file->getSize(),
            'created_by' => $request->user()->id,
        ]);

        return response()->json($attachment, 201);
    }

    /** Strip path/HTML-unsafe characters from the display filename. */
    private function sanitizeFilename(string $name): string
    {
        $name = basename($name);
        $name = preg_replace('/[^\w.\- ]+/u', '_', $name) ?? 'file';
        return mb_substr(trim($name), 0, 255) ?: 'file';
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
