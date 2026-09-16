# Attachments

## SIRE builds no attachment store

Evidence and screenshots go through `SireAttachmentProvider`. No new disk, no new
bucket, no `sire_attachments` table. The discovery report notes eight
module-specific attachment stores already exist — one too many, let alone nine.

## Integration

**1. Implement three methods** — `store()`, `listFor()`, `delete()` — returning
this descriptor, which the SIRE frontend renders directly:

```php
['id' => …, 'name' => …, 'size' => …, 'mime' => …, 'url' => …, 'created_at' => …]
```

```php
// what SIRE calls
$this->attachments->store(
    $report,                  // the ROUTE-BOUND model, never a payload value
    $request->file('file'),
    $request->user(),
);
```

**2. Add `Models\Sire\Report` to the polymorphic attachable allowlist**, if your
attachment service keeps one. That is the whole schema cost.

**Until you bind it**, `SireLocalAttachmentProvider` stores files on the configured
Laravel disk under `sire/{tenant}/{type}/{id}/` and keeps no table at all. Tenant
ownership is structural there: a listing only ever reads one tenant's directory,
which is a stronger guarantee than a `WHERE` clause somebody has to remember.

## The rules SIRE preserves

**Subject from the route, never the payload.** The existing service takes the
subject from the bound model so a file cannot be retargeted at another record by
editing a request body. SIRE keeps that contract.

**Every download asserts tenant and record ownership before touching the disk.**
There is no `tenant/{id}/` path prefix and no signed-URL layer anywhere in this
host — the controller check is the *only* thing between a guessed id and someone
else's evidence. Every new download endpoint is a fresh IDOR opportunity, which is
the class of bug the report says `TpvAdminApiVendorIsolationTest` was written to
close.

**`path` and `disk` never appear in JSON.** Hidden on the model.

**Type and size are checked against the SNIFFED mime type**, not the
browser-supplied one — an `accept` attribute and a `Content-Type` header are both
client hints. Beyond that, the blocklist is your service's; SIRE adds none of its
own.

## Screenshots

Captured with `navigator.mediaDevices.getDisplayMedia` — **no new dependency**.
`html2canvas` was considered and rejected: it is a dependency SIRE would be adding to its host,
for a feature the browser already provides.

The trade-off, stated plainly: the browser shows its own picker, so the user
chooses what is shared. That is also its safety property — nothing is captured
without an explicit gesture and an explicit choice. When unavailable or declined,
the flow degrades to "attach an image", which always works.

The modal **closes for the capture and reopens with the draft preserved**,
otherwise every screenshot is a picture of the report form.

Images are downscaled to 1600px and 75% JPEG. **Size the volume before rollout** —
the report warns the server runs close to full and a deploy that fills the disk can
corrupt MySQL:

```
issues/month × screenshots/issue × ~150 KB
```

## No image editor

SIRE will not add canvas-annotation infrastructure — fabric.js, konva — so it
builds no annotation. Uploading an already-annotated image works fine.
