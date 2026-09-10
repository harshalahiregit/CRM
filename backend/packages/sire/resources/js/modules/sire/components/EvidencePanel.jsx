/**
 * Screenshot and attachments, through the shared attachment engine.
 * Never renders a storage path or disk name — those are hidden server-side and
 * downloads go through a tenant-checked, controller-streamed endpoint.
 */
import { useState } from 'react';
import { sireApi } from '../../../services/sireApi';
import { validateScreenshotFile } from '../../../lib/sire/screenshot';

const isImage = (a) => /^image\//.test(a.mime_type || '');

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
                <img src={a.download_url} alt={a.original_name} className="h-24 w-32 object-cover" loading="lazy" />
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
        <div
          role="presentation"
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-6"
          onClick={() => setLightbox(null)}
        >
          <img src={lightbox.download_url} alt={lightbox.original_name} className="max-h-full max-w-full rounded" />
        </div>
      )}
    </section>
  );
}
