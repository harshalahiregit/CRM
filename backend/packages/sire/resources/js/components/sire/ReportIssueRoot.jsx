/**
 * SIRE — the single global mount point. Add this ONE line to the app layout and
 * every screen gains Report Issue. No page is modified.
 *
 *   <ReportIssueRoot />
 *
 * Renders a floating trigger plus the modal, and binds Alt+Shift+R. The trigger
 * hides itself while a screenshot is being captured so it does not appear in the
 * shot (see document.body.dataset.sireCapturing in ReportIssueModal).
 */
import { useEffect } from 'react';
import { useSireReporting } from '../../context/SireContextProvider';
import ReportIssueModal from './ReportIssueModal';

function ReportIssueButton() {
  const { openReportIssue, modal, capturing } = useSireReporting();
  // Hidden while the modal is open AND while a capture is in flight, so the
  // trigger never appears in a screenshot of the user's own screen.
  if (modal.open || capturing) return null;
  return (
    <button
      type="button"
      onClick={() => openReportIssue()}
      title="Report an issue (Alt+Shift+R)"
      data-sire-hide-in-capture
      className="fixed bottom-5 right-5 z-40 flex items-center gap-2 rounded-full bg-gray-900 px-4 py-2.5 text-sm font-medium text-white shadow-lg transition hover:bg-gray-700 dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-white"
    >
      <span aria-hidden>⚑</span>
      Report Issue
    </button>
  );
}

export default function ReportIssueRoot() {
  const { openReportIssue } = useSireReporting();

  useEffect(() => {
    const onKey = (e) => {
      // Alt+Shift+R — avoids Ctrl/Cmd+Shift+I (devtools) and Ctrl+Shift+R (hard reload).
      if (e.altKey && e.shiftKey && (e.key === 'R' || e.key === 'r')) {
        e.preventDefault();
        openReportIssue();
      }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [openReportIssue]);

  return (
    <>
      <ReportIssueButton />
      <ReportIssueModal />
    </>
  );
}
