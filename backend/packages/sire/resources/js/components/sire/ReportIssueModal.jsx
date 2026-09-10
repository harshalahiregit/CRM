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

export default function ReportIssueModal() {
  const { modal, openReportIssue, closeReportIssue, explicitContext, setCapturing } = useSireReporting();
  const toast = useToast();

  const [context, setContext] = useState(null);
  const [overrides, setOverrides] = useState(null);
  const [title, setTitle] = useState('');
  const [description, setDescription] = useState('');
  const [screenshot, setScreenshot] = useState(null);

  // Collect exactly once, at open. Not on mount, not on navigation.
  useEffect(() => {
    if (!modal.open) return;
    setContext(SireContextCollector.collect({ explicit: explicitContext(), overrides: null }));
    setOverrides(null);
    setTitle(modal.seed?.title || '');
    setDescription(modal.seed?.description || '');
    // A capture round-trip closes and reopens this modal; the draft rides in seed.
    setScreenshot(modal.seed?.screenshot || null);
  }, [modal.open, modal.seed, explicitContext]);

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
  const onCapture = useCallback(async () => {
    const draft = { title, description };
    setCapturing(true);
    closeReportIssue();
    try {
      await new Promise((resolve) => setTimeout(resolve, 150));
      const file = await captureScreen();
      openReportIssue({ ...draft, screenshot: file || screenshot });
      if (file) {
        toast?.success?.('Screenshot captured.');
      } else {
        toast?.error?.('Screen capture was cancelled or unavailable. You can attach an image instead.');
      }
    } catch {
      openReportIssue(draft); // never strand the user with the modal closed
      toast?.error?.('Screen capture failed. You can attach an image instead.');
    } finally {
      setCapturing(false);
    }
  }, [title, description, screenshot, setCapturing, closeReportIssue, openReportIssue, toast]);

  const onPickFile = useCallback((event) => {
    const file = event.target.files?.[0];
    const problem = validateScreenshotFile(file);
    if (problem) { toast?.error?.(problem); return; }
    setScreenshot(file);
  }, [toast]);

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
      });

      const report = data?.data ?? data;

      if (screenshot && report?.id) {
        try {
          await sireApi.uploadEvidence(report.id, screenshot);
        } catch {
          // The report is filed; a failed screenshot must not lose it.
          toast?.error?.('Issue reported, but the screenshot could not be attached.');
        }
      }

      toast?.success?.(`Issue reported${report?.report_number ? ` (${report.report_number})` : ''}.`);
      closeReportIssue();
    } catch (err) {
      // 403 is passed through by lib/api.js and must be shown, not swallowed.
      toast?.error?.(err?.response?.data?.message || 'Could not report this issue. Please try again.');
      throw err; // let AsyncButton clear its pending state
    }
  }, [canSubmit, title, description, context, screenshot, toast, closeReportIssue]);

  const shortcutHint = useMemo(
    () => (typeof navigator !== 'undefined' && /Mac/i.test(navigator.platform || '') ? '⌥⇧R' : 'Alt+Shift+R'),
    [],
  );

  if (!modal.open) return null;

  return (
    <Modal open={modal.open} onClose={closeReportIssue} title="Report an issue">
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

        <div className="flex flex-wrap items-center gap-2">
          {canCaptureScreen() && (
            <button
              type="button"
              onClick={onCapture}
              className="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-800"
            >
              Capture this screen
            </button>
          )}
          <label className="cursor-pointer rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-800">
            Attach an image
            <input type="file" accept="image/*" className="hidden" onChange={onPickFile} />
          </label>
          {screenshot && (
            <span className="text-xs text-green-600">
              {screenshot.name} ({Math.round(screenshot.size / 1024)} KB)
              <button type="button" className="ml-2 text-gray-400 hover:text-red-500" onClick={() => setScreenshot(null)}>
                remove
              </button>
            </span>
          )}
        </div>

        <div className="flex items-center justify-between border-t border-gray-200 pt-3 dark:border-gray-700">
          <span className="text-[11px] text-gray-400">{shortcutHint} opens this from anywhere</span>
          <div className="flex gap-2">
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
