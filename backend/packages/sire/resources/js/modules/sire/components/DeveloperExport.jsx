/**
 * SIRE — take the backlog out as one brief.
 *
 * WHY THIS EXISTS. Fixing a defect is the small half of the job; the expensive
 * half is the round trip — open the issue, read it, find the code, fix it, come
 * back, move it on. Forty issues is eighty page loads, and people stop reporting
 * things long before the developer stops paying for that.
 *
 * So: pick the modules you are about to work on, get one markdown document with
 * every open issue in them, grouped by the SCREEN it happened on. One fix
 * usually closes several, and grouping by screen turns forty tickets into six
 * files to open.
 *
 * DELIBERATELY LABELLED. The panel says who it is for on its face. The brief
 * carries reproduction steps, internal screen names and failed API paths — it is
 * engineering material, and somebody who pastes it into a customer thread should
 * have been told before they did, not after.
 */
import { useMemo, useState } from 'react';
import { sireApi } from '../../../services/sireApi';
import { sireHostToast } from '../../../lib/sire/host';

const useToast = sireHostToast();

export default function DeveloperExport({ modules = [], scope = 'open' }) {
  const toast = useToast();

  const [open, setOpen] = useState(false);
  const [chosen, setChosen] = useState([]);
  const [busy, setBusy] = useState(false);
  const [withImages, setWithImages] = useState(true);

  // The register returns the modules that actually have issues, so this list is
  // never longer than it needs to be and never offers an empty one.
  const available = useMemo(
    () => (modules || []).filter(Boolean).map(String),
    [modules],
  );

  const toggle = (name) =>
    setChosen((current) =>
      current.includes(name) ? current.filter((m) => m !== name) : [...current, name]);

  const run = async (download) => {
    setBusy(true);
    try {
      const { data } = await sireApi.exportIssues({
        scope,
        // No selection means everything. Making somebody tick eight boxes to say
        // "all of it" is a worse default than just giving them all of it.
        module: chosen.length ? chosen : undefined,
        images: withImages ? undefined : 0,
        download: download ? 1 : undefined,
      });

      const text = typeof data === 'string' ? data : String(data ?? '');

      if (download) {
        const url = URL.createObjectURL(new Blob([text], { type: 'text/markdown' }));
        const a = document.createElement('a');
        a.href = url;
        a.download = `sire-issues-${new Date().toISOString().slice(0, 10)}.md`;
        a.click();
        URL.revokeObjectURL(url);
        toast?.success?.('Brief downloaded.');
      } else {
        await navigator.clipboard.writeText(text);
        toast?.success?.('Brief copied — paste it wherever you are working.');
      }
    } catch (err) {
      toast?.error?.(
        err?.response?.status === 403
          ? 'You do not have the export capability.'
          : 'Could not build the brief.',
      );
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="mt-3 border-t pt-3" style={{ borderColor: 'var(--border)' }}>
      <div className="flex flex-wrap items-center gap-2">
        <button
          type="button"
          onClick={() => setOpen((v) => !v)}
          className="text-xs font-medium"
          style={{ color: 'var(--text-h)' }}
        >
          {open ? '▾' : '▸'} Export issue brief
        </button>

        {/* Says on its face who it is for. */}
        <span
          className="rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide"
          style={{ background: 'rgba(124,58,237,0.12)', color: '#a78bfa' }}
        >
          Developers
        </span>

        <span className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
          one markdown file, grouped by screen
        </span>
      </div>

      {open && (
        <div className="mt-3">
          <p className="mb-2 text-[11px]" style={{ color: 'var(--text-muted)' }}>
            Pick the modules you are about to work on. Nothing ticked exports everything
            in the current scope. The brief carries reproduction steps and internal screen
            names — engineering material, not something to forward to a customer.
          </p>

          <label className="mb-3 flex items-center gap-2 text-[11px]" style={{ color: 'var(--text-muted)' }}>
            <input
              type="checkbox"
              checked={withImages}
              onChange={(e) => setWithImages(e.target.checked)}
            />
            {/* Screenshots travel as data URIs, so they survive being pasted
                anywhere. Off gives links only, for a small file to skim. */}
            Include screenshots in the file
          </label>

          {available.length === 0 ? (
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
              No modules to choose from yet — issues get a module when they are filed
              through the Report Issue button.
            </p>
          ) : (
            <div className="flex flex-wrap gap-1.5">
              {available.map((name) => {
                const on = chosen.includes(name);

                return (
                  <button
                    key={name}
                    type="button"
                    onClick={() => toggle(name)}
                    className="rounded-lg px-2 py-1 text-xs font-medium capitalize transition-colors"
                    style={{
                      background: on ? 'rgba(124,58,237,0.16)' : 'var(--bg-input)',
                      border: `1px solid ${on ? '#a78bfa' : 'var(--border)'}`,
                      color: on ? '#a78bfa' : 'var(--text-h)',
                    }}
                  >
                    {name}
                  </button>
                );
              })}
            </div>
          )}

          <div className="mt-3 flex flex-wrap items-center gap-2">
            <button
              type="button"
              onClick={() => run(false)}
              disabled={busy}
              className="rounded-lg px-3 py-1.5 text-xs font-semibold disabled:opacity-50"
              style={{ background: '#7c3aed', color: '#fff' }}
            >
              {busy ? 'Building…' : 'Copy brief'}
            </button>
            <button
              type="button"
              onClick={() => run(true)}
              disabled={busy}
              className="rounded-lg px-3 py-1.5 text-xs font-medium disabled:opacity-50"
              style={{ border: '1px solid var(--border)', color: 'var(--text-h)' }}
            >
              Download .md
            </button>
            {chosen.length > 0 && (
              <span className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
                {chosen.length} module{chosen.length === 1 ? '' : 's'} selected
              </span>
            )}
          </div>
        </div>
      )}
    </div>
  );
}
