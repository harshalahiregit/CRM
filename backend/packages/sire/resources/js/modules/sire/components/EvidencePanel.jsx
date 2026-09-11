/**
 * Screenshot and attachments, through the shared attachment engine.
 * Never renders a storage path or disk name — those are hidden server-side and
 * downloads go through a tenant-checked, controller-streamed endpoint.
 */
import { useEffect, useState } from 'react';
import { sireApi } from '../../../services/sireApi';
import { validateScreenshotFile } from '../../../lib/sire/screenshot';

const isImage = (a) => /^image\//.test(a.mime_type || '');

/**
 * Evidence lives on a PRIVATE disk behind auth:sanctum, and this app sends a
 * Bearer token -- which an <img src> cannot carry. So the bytes are fetched with
 * the shared client and rendered from an object URL, which is revoked on unmount
 * so a long QA session does not leak one blob per screenshot it looked at.
 */
function AuthedImage({ issueId, attachment, className }) {
  const [src, setSrc] = useState(null);
  const [failed, setFailed] = useState(false);

  useEffect(() => {
    let url = null;
    let alive = true;

    sireApi
      .attachmentBlob(issueId, attachment.id)
      .then((res) => {
        if (!alive) return;
        url = URL.createObjectURL(res.data);
        setSrc(url);
      })
      .catch(() => alive && setFailed(true));

    return () => {
      alive = false;
      if (url) URL.revokeObjectURL(url);
    };
  }, [issueId, attachment.id]);

  if (failed) {
    return (
      <span className={`${className} flex items-center justify-center px-2 text-center text-[11px] text-gray-400`}>
        Could not load
      </span>
    );
  }

  if (!src) {
    return <span className={`${className} block animate-none bg-gray-100 dark:bg-gray-800`} />;
  }

  return <img src={src} alt={attachment.original_name} className={className} />;
}

export default function EvidencePanel({ issueId, attachments = [], canUpload, onUploaded, toast }) {
  const [lightbox, setLightbox] = useState(null);
  const [busy, setBusy] = useState(false);

  const upload = async (event) => {
    const file = event.target.files?.[0];
    const problem = validateScreenshotFile(file);
    if (problem) return toast?.error?.(problem);

    setBusy(true);
    try {
      await sireApi.uploadEvidence(issueId, file);
      toast?.success?.('Evidence attached.');
      onUploaded?.();
    } catch (err) {
      toast?.error?.(err?.response?.data?.message || 'Could not attach that file.');
    } finally {
      setBusy(false);
      event.target.value = '';
    }
    return undefined;
  };

  return (
    <section>
      <div className="mb-2 flex items-center justify-between">
        <h3 className="text-xs font-semibold uppercase tracking-wide text-gray-500">
          Evidence {attachments.length > 0 && `(${attachments.length})`}
        </h3>
        {canUpload && (
          <label className="cursor-pointer rounded-lg border border-gray-300 px-2.5 py-1 text-[11px] font-medium hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-800">
            {busy ? 'Uploading…' : 'Attach evidence'}
            <input type="file" accept="image/*" className="hidden" onChange={upload} disabled={busy} />
          </label>
        )}
      </div>

      {attachments.length === 0 ? (
        <p className="text-xs text-gray-400">No screenshots or files attached.</p>
      ) : (
        <div className="flex flex-wrap gap-2">
          {attachments.map((a) => (
            <button
              key={a.id}
              type="button"
              onClick={() => isImage(a) && setLightbox(a)}
              className="group relative overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700"
              title={a.original_name}
            >
              {isImage(a) ? (
                <AuthedImage issueId={issueId} attachment={a} className="h-24 w-32 object-cover" />
              ) : (
                <span className="flex h-24 w-32 items-center justify-center px-2 text-center text-[11px] text-gray-500">
                  {a.original_name}
                </span>
              )}
              <span className="absolute inset-x-0 bottom-0 truncate bg-black/60 px-1 py-0.5 text-[10px] text-white">
                {a.size_label}
              </span>
            </button>
          ))}
        </div>
      )}

      {lightbox && (
        // The full-size view went through a plain <img src>, which carries no
        // Bearer token -- so opening a screenshot always showed a broken image.
        // Same authorized fetch as the thumbnail.
        //
        // It closes on the button, not the backdrop: a stray click must not
        // dismiss the evidence someone is reading.
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-6">
          <button
            type="button"
            onClick={() => setLightbox(null)}
            aria-label="Close"
            className="absolute right-4 top-4 rounded-full bg-white/10 px-3 py-1 text-lg leading-none text-white hover:bg-white/20"
          >
            &times;
          </button>
          <AuthedImage
            issueId={issueId}
            attachment={lightbox}
            className="max-h-full max-w-full rounded object-contain"
          />
        </div>
      )}
    </section>
  );
}
