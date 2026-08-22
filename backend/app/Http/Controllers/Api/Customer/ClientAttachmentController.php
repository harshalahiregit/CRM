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

    /** Private, not web-reachable. Every read goes through download() below. */
    private const DISK = 'attachments';

    public function store(Client $client, Request $request)
    {
        $this->assertClientTenant($client, $request->user()->tenant_id);
        $request->validate([
            // Allow-list common document/image types. Kept even though the
            // files are no longer web-reachable: a narrow list is cheap, and
            // it stops the store becoming a general file drop.
            'file' => 'required|file|max:20480|mimes:pdf,doc,docx,xls,xlsx,csv,ppt,pptx,txt,png,jpg,jpeg,gif,webp,zip',
        ]);

        $file = $request->file('file');
        // The 'attachments' disk is private. These were on 'public', which
        // meant anyone holding the URL could fetch a customer's documents with
        // no credentials at all — and no way to revoke it short of deleting the
        // file, since deactivating the user changes nothing about a public URL.
        $path = $file->store("client-attachments/{$client->tenant_id}/{$client->id}", self::DISK);

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

    /**
     * Serve one attachment to an authorised member of staff.
     *
     * The file has no public URL, so this is the only way to read it: the
     * request carries the caller's token, the tenant is checked, and the
     * attachment must belong to the customer named in the path. That makes
     * access revocable — deactivating someone actually stops them, which was
     * not true while the files sat on the public disk.
     */
    public function download(Client $client, ClientAttachment $attachment, Request $request)
    {
        $this->assertClientTenant($client, $request->user()->tenant_id);
        abort_if($attachment->client_id !== $client->id, 404);
        abort_unless(Storage::disk(self::DISK)->exists($attachment->file_path), 404, 'File missing from storage.');

        return Storage::disk(self::DISK)->download($attachment->file_path, $attachment->file_name);
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

        Storage::disk(self::DISK)->delete($attachment->file_path);
        $attachment->delete();

        return response()->json(['message' => 'Attachment deleted']);
    }
}
