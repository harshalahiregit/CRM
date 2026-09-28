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

/**
 * The way back: the same file, ticked, returned.
 *
 * ALWAYS PREVIEWS FIRST. The file has been outside the system — an editor, a
 * chat, a coding assistant — and closing thirty defect records is not something
 * anybody should discover the result of afterwards. The preview runs the real
 * capability and guard checks, so what it lists is what will happen.
 */
function SendItBack({ onDone }) {
  const toast = useToast();

  const [payload, setPayload] = useState(null);   // { file } or { text }
  const [preview, setPreview] = useState(null);
  const [busy, setBusy] = useState(false);

  const send = async (source, apply) => {
    setBusy(true);
    try {
      const { data } = await sireApi.importBrief({ ...source, apply });
      setPreview(data?.data ?? data);

      if (apply) {
        const closed = (data?.data ?? data)?.closed ?? 0;
        toast?.success?.(closed === 1 ? '1 issue closed.' : `${closed} issues closed.`);
        onDone?.();
      }
    } catch (err) {
      /*
       * SAY WHAT THE SERVER SAID. This used to answer every failure with "That
       * file could not be read", which is a guess dressed as a diagnosis: a 404
       * from an un-deployed endpoint, a 413 from a file over the limit and a 422
       * from a malformed upload all read the same, and none of them had anything
       * to do with reading the file. The one real failure it ever saw was a 422,
       * and the message sent the reader off looking at their markdown.
       */
      const status = err?.response?.status;
      const said = err?.response?.data?.message
        ?? Object.values(err?.response?.data?.errors ?? {})[0]?.[0];

      toast?.error?.(
        status === 403 ? 'You do not have permission to close issues.'
          : status === 404 ? 'This server does not have the import endpoint yet — it needs deploying.'
            : status === 413 ? 'That file is too large to upload.'
              : said ? `Upload refused: ${said}`
                : `Upload failed${status ? ` (${status})` : ''}.`,
      );
    } finally {
      setBusy(false);
    }
  };

  const pick = (file) => {
    if (!file) return;
    setPayload({ file });
    send({ file }, false);
  };

  const rows = preview?.results ?? [];

  return (
    <div className="mt-4 border-t pt-3" style={{ borderColor: 'var(--border)' }}>
      <p className="text-xs font-medium" style={{ color: 'var(--text-h)' }}>
        Send it back
      </p>
      <p className="mb-2 text-[11px]" style={{ color: 'var(--text-muted)' }}>
        Tick <code>- [x] Done</code> under each issue you fixed, then upload the file.
        You are shown what will close before anything does.
      </p>

      <input
        type="file"
        accept=".md,.markdown,.txt,text/markdown,text/plain"
        disabled={busy}
        onChange={(e) => pick(e.target.files?.[0])}
        className="block w-full text-[11px]"
        style={{ color: 'var(--text-muted)' }}
      />

      {preview?.message && (
        <p className="mt-2 rounded-lg px-2 py-1.5 text-[11px]"
           style={{ background: 'var(--bg-input)', color: 'var(--text-muted)' }}>
          {preview.message}
        </p>
      )}

      {rows.length > 0 && (
        <div className="mt-2">
          <div className="max-h-52 overflow-y-auto rounded-lg" style={{ border: '1px solid var(--border)' }}>
            {rows.map((r) => (
              <div
                key={r.report_number}
                className="flex items-start gap-2 px-2 py-1.5 text-[11px]"
                style={{ borderBottom: '1px solid var(--border)' }}
              >
                <span
                  className="mt-0.5 shrink-0 rounded px-1 text-[10px] font-semibold uppercase"
                  style={{
                    background: r.state === 'failed' ? 'rgba(239,68,68,0.15)'
                      : r.state === 'skipped' ? 'rgba(148,163,184,0.15)'
                        : 'rgba(34,197,94,0.15)',
                    color: r.state === 'failed' ? '#f87171'
                      : r.state === 'skipped' ? '#94a3b8' : '#4ade80',
                  }}
                >
                  {r.state === 'ready' ? 'will close' : r.state}
                </span>
                <span className="min-w-0 flex-1" style={{ color: 'var(--text-h)' }}>
                  <strong>{r.report_number}</strong> {r.title ?? ''}
                  <span className="block" style={{ color: 'var(--text-muted)' }}>
                    {r.reason
                      ? r.reason
                      /* Saying so here is the last chance to go back and write
                         one, rather than finding the record says nothing later. */
                      : r.detailed ? r.note : `${r.note} — no detail given`}
                  </span>
                </span>
              </div>
            ))}
          </div>

          {preview?.dry_run && preview.ready > 0 && (
            <button
              type="button"
              disabled={busy}
              onClick={() => send(payload, true)}
              className="mt-2 rounded-lg px-3 py-1.5 text-xs font-semibold disabled:opacity-50"
              style={{ background: '#16a34a', color: '#fff' }}
            >
              {busy ? 'Closing…' : `Close ${preview.ready} issue${preview.ready === 1 ? '' : 's'}`}
            </button>
          )}
        </div>
      )}
    </div>
  );
}

export default function DeveloperExport({ modules = [], scope = 'open', onImported }) {
  const toast = useToast();

  const [open, setOpen] = useState(false);
  const [chosen, setChosen] = useState([]);
  const [busy, setBusy] = useState(false);
  const [withImages, setWithImages] = useState(true);

  /*
   * WHAT "OPEN" DOES NOT MEAN. The register's `open` scope is "not terminal",
   * so an issue that was fixed, passed QA, shipped and was validated in
   * production is still open -- correctly, because somebody still owes it a
   * close. For a developer brief that is the wrong list: it put already-shipped
   * fixes in front of the person being asked to fix them, and the honest
   * reaction to that file is "half of these are done".
   *
   * `unresolved` is the same query with the six fix-submitted states dropped.
   * Default, because it is what this panel is for.
   */
  const [needsCode, setNeedsCode] = useState(true);
  const effectiveScope = needsCode ? 'unresolved' : (scope || 'open');

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
        scope: effectiveScope,
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
            Pick the modules you are about to work on. Nothing ticked exports every module.
            The brief carries reproduction steps and internal screen names — engineering
            material, not something to forward to a customer.
          </p>

          {/* Which issues, said out loud. This used to follow the dashboard tile
              silently, so the one control that decided whether closed and
              already-shipped issues came down was somewhere else on the page. */}
          <div className="mb-3 space-y-1">
            <p className="text-[11px] font-medium" style={{ color: 'var(--text-h)' }}>
              Which issues
            </p>

            <label className="flex items-start gap-2 text-[11px]" style={{ color: 'var(--text-muted)' }}>
              <input
                type="radio"
                className="mt-0.5"
                checked={needsCode}
                onChange={() => setNeedsCode(true)}
              />
              <span>
                <strong style={{ color: 'var(--text-h)' }}>Still needs code</strong>
                {' — '}leaves out anything already in QA, released, or validated in production.
              </span>
            </label>

            <label className="flex items-start gap-2 text-[11px]" style={{ color: 'var(--text-muted)' }}>
              <input
                type="radio"
                className="mt-0.5"
                checked={!needsCode}
                onChange={() => setNeedsCode(false)}
              />
              <span>
                <strong style={{ color: 'var(--text-h)' }}>The set on screen</strong>
                {' — '}whatever the tiles and filters above are showing
                {scope === 'all' && ', which right now includes closed issues'}.
              </span>
            </label>
          </div>

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
            {/* The scope travels with the file and is printed in its header,
                so the reader can always check afterwards -- but they should not
                have to find out afterwards. */}
            <span className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
              scope <code>{effectiveScope}</code>
              {' · '}
              {chosen.length === 0
                ? 'all modules'
                : `${chosen.length} module${chosen.length === 1 ? '' : 's'}`}
            </span>
          </div>

          <SendItBack onDone={onImported} />
        </div>
      )}
    </div>
  );
}
