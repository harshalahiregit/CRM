/**
 * SIRE — screenshot capture using existing browser capabilities only.
 *
 * No new dependency. html2canvas / modern-screenshot were not added: the CRM has
 * no such package today, and getDisplayMedia is already available in every
 * browser this app supports.
 *
 * Trade-off, stated plainly: getDisplayMedia shows the browser's own picker, so
 * the user chooses what is shared and may pick the wrong surface. That is also
 * its safety property — nothing is captured without an explicit user gesture and
 * an explicit choice. When it is unavailable or declined, the flow degrades to
 * "attach a screenshot file", which always works.
 *
 * NO image editor is built. The CRM has no canvas-annotation infrastructure
 * (no fabric.js, no konva), and the brief says not to build one.
 */

const MAX_EDGE = 1600;      // downscale ceiling — evidence, not print quality
const JPEG_QUALITY = 0.75;  // disk on the production box is tight

export const canCaptureScreen = () =>
  typeof navigator !== 'undefined' &&
  typeof navigator.mediaDevices?.getDisplayMedia === 'function' &&
  typeof window !== 'undefined' &&
  window.isSecureContext !== false;

function drawScaled(source, width, height) {
  const scale = Math.min(1, MAX_EDGE / Math.max(width, height));
  const canvas = document.createElement('canvas');
  canvas.width = Math.round(width * scale);
  canvas.height = Math.round(height * scale);
  canvas.getContext('2d').drawImage(source, 0, 0, canvas.width, canvas.height);
  return canvas;
}

const toBlob = (canvas) =>
  new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', JPEG_QUALITY));

/**
 * Capture the current screen. Resolves to a File, or null if unsupported or
 * declined — a decline is a normal outcome, not an error to surface.
 *
 * The caller must hide the report modal first (see ReportIssueModal), otherwise
 * the screenshot is a picture of the report form.
 */
export async function captureScreen() {
  if (!canCaptureScreen()) return null;

  let stream;
  try {
    stream = await navigator.mediaDevices.getDisplayMedia({
      video: { displaySurface: 'browser' },
      audio: false,
      preferCurrentTab: true, // Chromium hint; ignored elsewhere
    });
  } catch {
    return null; // user declined or the browser refused
  }

  try {
    const track = stream.getVideoTracks()[0];
    if (!track) return null;

    // ImageCapture where available: one frame, no video element, no playback.
    if (typeof window.ImageCapture === 'function') {
      try {
        const bitmap = await new window.ImageCapture(track).grabFrame();
        const canvas = drawScaled(bitmap, bitmap.width, bitmap.height);
        const blob = await toBlob(canvas);
        return blob ? new File([blob], 'screen.jpg', { type: 'image/jpeg' }) : null;
      } catch {
        // fall through to the video path
      }
    }

    const video = document.createElement('video');
    video.srcObject = stream;
    video.muted = true;
    await video.play();
    await new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)));

    const canvas = drawScaled(video, video.videoWidth, video.videoHeight);
    video.pause();
    video.srcObject = null;

    const blob = await toBlob(canvas);
    return blob ? new File([blob], 'screen.jpg', { type: 'image/jpeg' }) : null;
  } finally {
    stream.getTracks().forEach((t) => t.stop()); // never leave the capture running
  }
}

const ALLOWED_UPLOAD_TYPES = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];
const MAX_UPLOAD_BYTES = 8 * 1024 * 1024;

/** Validate a user-chosen screenshot file before it reaches the attachment API. */
export function validateScreenshotFile(file) {
  if (!file) return 'No file selected.';
  if (!ALLOWED_UPLOAD_TYPES.includes(file.type)) return 'Please attach a PNG, JPG, WEBP or GIF image.';
  if (file.size > MAX_UPLOAD_BYTES) return 'That image is larger than 8 MB. Please attach a smaller one.';
  return null;
}
