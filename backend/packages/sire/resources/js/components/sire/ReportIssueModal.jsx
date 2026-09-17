/**
 * SIRE — compact Report Issue modal.
 *
 * Two required fields. Everything else is detected, shown, and correctable only
 * when uncertain.
 *
 * PROP CHECK — assumptions about the existing UI kit, verify before merge:
 *   ui/Modal        <Modal open onClose title>{children}</Modal>
 *   ui/AsyncButton  <AsyncButton onClick disabled>{label}</AsyncButton>, handles pending
 *   hooks/useToast  const { success, error } = useToast()
 * If any signature differs, fix it here — nothing else in SIRE touches the kit.
 *
 * DEVIATION, deliberate: this form uses plain controlled state, not
 * react-hook-form + zod. Two fields, one submit, no cross-field rules; RHF would
 * add surface without adding safety. Every other SIRE form follows the house
 * convention.
 */
import { useCallback, useEffect, useMemo, useState } from 'react';
import { sireHostUi, sireHostToast } from '../../lib/sire/host';
import { useSireReporting } from '../../context/SireContextProvider';
import { SireContextCollector } from '../../lib/sire/collector';
import { captureScreen, canCaptureScreen, validateScreenshotFile } from '../../lib/sire/screenshot';
import ContextPreview from './ContextPreview';
import { sireApi } from '../../services/sireApi';

// Resolved through the host bridge: the host's Modal and Button if it registered
// them, SIRE's accessible fallbacks if it did not. Report Issue is the one screen
// that must work on day one, before anybody has configured anything.
const Modal = sireHostUi('Modal');
const AsyncButton = sireHostUi('AsyncButton');
const useToast = sireHostToast();

/**
 * A ceiling, not a target. Each image is an upload of its own and the production
 * disk is tight; six screens is already an unusually thorough report, and
 * somebody selecting a folder of two hundred should not be able to.
 */
const MAX_FILES = 6;

const selectClass =
  'w-full rounded-lg border border-gray-300 px-2 py-2 text-sm dark:border-gray-600 dark:bg-gray-800';

const attachButtonClass =
  'rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium hover:bg-gray-50 '
  + 'dark:border-gray-600 dark:hover:bg-gray-800';

export default function ReportIssueModal() {
  const { modal, openReportIssue, closeReportIssue, explicitContext, setCapturing } = useSireReporting();
  const toast = useToast();

  const [context, setContext] = useState(null);
  const [overrides, setOverrides] = useState(null);
  const [title, setTitle] = useState('');
  const [description, setDescription] = useState('');

  // A list, not one file. A bug is often three screens -- the form, the error,
  // the record it landed on -- and asking for one means the other two arrive in
  // a chat message nobody can find later.
  const [files, setFiles] = useState([]);

  // Set by the REPORTER, at Stage 1. The person hitting the bug knows how badly
  // it blocks them; a lead triaging a queue is guessing. Pre-filled with the
  // middle of each scale so every issue arrives with a stated urgency, and
  // every one of these stays OPTIONAL -- D45, and there is a build check.
  const [options, setOptions] = useState(null);
  const [categoryId, setCategoryId] = useState('');
  const [severityId, setSeverityId] = useState('');
  const [priority, setPriority] = useState('');

  // Collect exactly once, at open. Not on mount, not on navigation.
  useEffect(() => {
    if (!modal.open) return;
    setContext(SireContextCollector.collect({ explicit: explicitContext(), overrides: null }));
    setOverrides(null);
    setTitle(modal.seed?.title || '');
    setDescription(modal.seed?.description || '');
    // A capture round-trip closes and reopens this modal; the draft rides in seed,
    // and that now includes the triage fields and every file already attached.
    setFiles(modal.seed?.files || []);
    setCategoryId(modal.seed?.categoryId ?? '');
    setSeverityId(modal.seed?.severityId ?? '');
    setPriority(modal.seed?.priority ?? '');
  }, [modal.open, modal.seed, explicitContext]);

  /**
   * Fetch the three lists once per open, and only when they are not already in
   * hand -- a capture round-trip reopens this modal and must not re-request.
   *
   * A failure here is silent on purpose. The selects simply do not render, and
   * the form still files the issue; losing a bug report because a dropdown could
   * not load would be a poor trade for a field nobody is required to fill.
   */
  useEffect(() => {
    if (!modal.open || options) return;
    let cancelled = false;

    sireApi.reportOptions()
      .then(({ data }) => {
        if (cancelled) return;
        const payload = data?.data ?? data;
        setOptions(payload);
        // Seeded defaults win, because they are what the user last saw.
        setSeverityId((current) => current || payload?.defaults?.severity_id || '');
        setPriority((current) => current || payload?.defaults?.priority || '');
      })
      .catch(() => {});

    return () => { cancelled = true; };
  }, [modal.open, options]);

  const applyCorrection = useCallback((draft) => {
    const cleaned = Object.fromEntries(Object.entries(draft).filter(([, v]) => v !== ''));
    const next = { ...(overrides || {}), ...cleaned };
    setOverrides(next);
    setContext(SireContextCollector.collect({ explicit: explicitContext(), overrides: next }));
  }, [overrides, explicitContext]);

  /**
   * Capture the screen WITHOUT the report form in it.
   *
   * The modal is closed first and reopened afterwards with the draft carried in
   * `seed`. Hiding it with CSS was the other option and was rejected: Modal may
   * portal to body and render its own backdrop, so a wrapper style is not
   * guaranteed to hide anything. Closing is the only approach that does not
   * depend on the kit's internals.
   *
   * The short delay lets React unmount and the browser paint before the picker
   * opens. getDisplayMedia needs transient activation, which survives ~5s in
   * Chromium, so 150ms is safe.
   */
  const onCapture = useCallback(async (segment) => {
    // Everything the user has typed or attached rides across the round trip.
    const draft = { title, description, files, categoryId, severityId, priority };
    setCapturing(true);
    closeReportIssue();
    try {
      await new Promise((resolve) => setTimeout(resolve, 150));
      const file = await captureScreen({ segment });
      openReportIssue({ ...draft, files: file ? [...files, file] : files });
      if (file) {
        toast?.success?.(segment ? 'Segment captured.' : 'Screenshot captured.');
      } else {
        toast?.error?.('Screen capture was cancelled or unavailable. You can attach an image instead.');
      }
    } catch {
      openReportIssue(draft); // never strand the user with the modal closed
      toast?.error?.('Screen capture failed. You can attach an image instead.');
    } finally {
      setCapturing(false);
    }
  }, [title, description, files, categoryId, severityId, priority,
      setCapturing, closeReportIssue, openReportIssue, toast]);

  /**
   * Add whatever passed validation and say what did not.
   *
   * One bad file out of four must not discard the other three -- the user picked
   * them together and would have to start again.
   */
  const onPickFiles = useCallback((event) => {
    const picked = Array.from(event.target.files || []);
    const accepted = [];
    const rejected = [];

    for (const file of picked) {
      const problem = validateScreenshotFile(file);
      if (problem) rejected.push(`${file.name}: ${problem}`);
      else accepted.push(file);
    }

    if (accepted.length) setFiles((current) => [...current, ...accepted].slice(0, MAX_FILES));
    if (rejected.length) toast?.error?.(rejected.join(' '));
    if (accepted.length && files.length + accepted.length > MAX_FILES) {
      toast?.error?.(`Only the first ${MAX_FILES} images are attached.`);
    }

    // Let the same file be chosen again after it is removed.
    event.target.value = '';
  }, [files.length, toast]);

  const removeFile = useCallback((index) => {
    setFiles((current) => current.filter((_, i) => i !== index));
  }, []);

  // D45: title and description, and nothing else. A build check asserts that
  // severity, category, priority and attachments never appear in this line.
  const canSubmit = title.trim().length >= 5 && description.trim().length >= 10 && context;

  const submit = useCallback(async () => {
    if (!canSubmit) return;
    try {
      const { data } = await sireApi.createReport({
        title: title.trim(),
        description: description.trim(),
        origin: 'internal',
        submit: true,          // create + transition to submitted in one transaction
        context,               // tenant_id / user_id are NOT here — server stamps them

        // The reporter's own read on how bad this is. Omitted entirely when
        // unset, so the server stores null rather than a value nobody chose.
        ...(categoryId ? { category_id: Number(categoryId) } : {}),
        ...(severityId ? { severity_id: Number(severityId) } : {}),
        ...(priority ? { priority } : {}),
      });

      // The API answers {data:{report:{...}}}. Unwrapping only one level
      // left report.id undefined, so the screenshot was silently dropped and
      // the toast never showed the SIR- number.
      const report = data?.data?.report ?? data?.report ?? data?.data ?? data;

      // Uploaded one at a time, after the issue exists. Sequential rather than
      // parallel: the attachment endpoint writes to a shared directory per
      // report, and six simultaneous writes on a box this size buys nothing.
      let failed = 0;
      if (report?.id) {
        for (const file of files) {
          try {
            await sireApi.uploadEvidence(report.id, file);
          } catch {
            failed += 1;
          }
        }
      }

      // The issue is filed either way. A failed upload is worth saying out loud
      // but must never read as though the report itself was lost.
      if (failed) {
        toast?.error?.(
          `Issue reported${report?.report_number ? ` (${report.report_number})` : ''}, `
          + `but ${failed} of ${files.length} image(s) could not be attached.`,
        );
      } else {
        toast?.success?.(
          report?.report_number
            ? `${report.report_number} raised — your issue is logged.`
            : 'Issue reported.',
        );
      }

      closeReportIssue();
    } catch (err) {
      // 403 is passed through by lib/api.js and must be shown, not swallowed.
      toast?.error?.(err?.response?.data?.message || 'Could not report this issue. Please try again.');
      throw err; // let AsyncButton clear its pending state
    }
  }, [canSubmit, title, description, context, files, categoryId, severityId, priority,
      toast, closeReportIssue]);

  const shortcutHint = useMemo(
    () => (typeof navigator !== 'undefined' && /Mac/i.test(navigator.platform || '') ? '⌥⇧R' : 'Alt+Shift+R'),
    [],
  );

  if (!modal.open) return null;

  return (
    <Modal open={modal.open} onClose={closeReportIssue} title="Report an issue" className="sire-report-modal">
      <div className="space-y-3">
        {context && <ContextPreview context={context} onCorrect={applyCorrection} />}

        <label className="block">
          <span className="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">
            What went wrong? <span className="text-red-500">*</span>
          </span>
          <input
            autoFocus
            value={title}
            onChange={(e) => setTitle(e.target.value)}
            maxLength={255}
            placeholder="Saving the lead returns an error"
            className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800"
          />
        </label>

        <label className="block">
          <span className="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">
            What were you doing? <span className="text-red-500">*</span>
          </span>
          <textarea
            rows={4}
            value={description}
            onChange={(e) => setDescription(e.target.value)}
            placeholder="Clicked Save after changing the owner. The spinner runs and then nothing happens."
            className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800"
          />
        </label>

        {/* How bad is this? Answered by the person it is happening to.
            Every control here is optional -- see canSubmit. */}
        {options && (
          <div className="grid grid-cols-1 gap-2 sm:grid-cols-3">
            <label className="block">
              <span className="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">
                Category
              </span>
              <select
                value={categoryId}
                onChange={(e) => setCategoryId(e.target.value)}
                className={selectClass}
              >
                <option value="">Not sure</option>
                {(options.categories || []).map((c) => (
                  <option key={c.id} value={c.id}>{c.name}</option>
                ))}
              </select>
            </label>

            <label className="block">
              <span className="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">
                How severe?
              </span>
              <select
                value={severityId}
                onChange={(e) => setSeverityId(e.target.value)}
                className={selectClass}
              >
                <option value="">Not sure</option>
                {(options.severities || []).map((sv) => (
                  <option key={sv.id} value={sv.id}>{sv.name}</option>
                ))}
              </select>
            </label>

            <label className="block">
              <span className="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">
                How urgent?
              </span>
              <select
                value={priority}
                onChange={(e) => setPriority(e.target.value)}
                className={selectClass}
              >
                <option value="">Not sure</option>
                {(options.priorities || []).map((pr) => (
                  <option key={pr.value} value={pr.value}>{pr.label}</option>
                ))}
              </select>
            </label>
          </div>
        )}

        <div className="flex flex-wrap items-center gap-2">
          {canCaptureScreen() && (
            <>
              <button
                type="button"
                onClick={() => onCapture(true)}
                className={attachButtonClass}
              >
                Capture a part of the screen
              </button>
              <button
                type="button"
                onClick={() => onCapture(false)}
                className={attachButtonClass}
              >
                Capture the whole screen
              </button>
            </>
          )}
          <label className={`cursor-pointer ${attachButtonClass}`}>
            Attach images
            <input
              type="file"
              accept="image/*"
              multiple
              className="hidden"
              onChange={onPickFiles}
            />
          </label>
          {files.length > 0 && (
            <span className="text-xs text-gray-500 dark:text-gray-400">
              {files.length} of {MAX_FILES}
            </span>
          )}
        </div>

        {/* Thumbnails, because a list of filenames does not tell anyone whether
            they captured the right screen -- and the point of capturing a
            segment is that the right part is in frame. */}
        {files.length > 0 && (
          <ul className="flex flex-wrap gap-2">
            {files.map((file, index) => (
              <li key={`${file.name}-${index}`} className="relative">
                <img
                  src={URL.createObjectURL(file)}
                  alt={file.name}
                  onLoad={(e) => URL.revokeObjectURL(e.currentTarget.src)}
                  className="h-16 w-24 rounded-md border border-gray-200 object-cover dark:border-gray-700"
                />
                <button
                  type="button"
                  onClick={() => removeFile(index)}
                  aria-label={`Remove ${file.name}`}
                  className="absolute -right-1.5 -top-1.5 flex h-5 w-5 items-center justify-center rounded-full bg-gray-800 text-xs text-white hover:bg-red-500"
                >
                  ×
                </button>
                <span className="mt-0.5 block max-w-24 truncate text-[10px] text-gray-400">
                  {Math.round(file.size / 1024)} KB
                </span>
              </li>
            ))}
          </ul>
        )}

        <div className="flex flex-wrap items-center justify-between gap-2 border-t border-gray-200 pt-3 dark:border-gray-700">
          {/* Hidden on phones: there is no keyboard to press it with, and at 400px
              it was pushing the buttons onto a line of their own. */}
          <span className="hidden text-[11px] text-gray-400 sm:inline">{shortcutHint} opens this from anywhere</span>
          <div className="ml-auto flex gap-2">
            <button type="button" className="px-3 py-2 text-sm text-gray-500" onClick={closeReportIssue}>
              Cancel
            </button>
            <AsyncButton onClick={submit} disabled={!canSubmit}>
              Report issue
            </AsyncButton>
          </div>
        </div>
      </div>
    </Modal>
  );
}
